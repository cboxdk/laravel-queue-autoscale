<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

/**
 * Decides which job pickups are worth writing to the sample store.
 *
 * The p95 that drives every SLA decision is computed from at most
 * `pickup_time.max_samples_per_queue` entries — the store trims the rest away.
 * A queue running at 10k jobs/s therefore pays two Redis round trips per job to
 * produce hundreds of thousands of samples that are discarded within the same
 * second, and the handful that survive all describe the last instant of the
 * window rather than the window itself.
 *
 * Sampling fixes both halves. Each process caps how many pickups it forwards
 * per second; above that rate it forwards a uniformly random subset. The
 * estimate keeps its statistical footing because every job in a window has the
 * same probability of being chosen, so the p95 of the subset is a consistent
 * estimator of the p95 of the population — and the surviving samples now span
 * the whole window instead of clustering at its end.
 *
 * The inclusion probability is derived from the PREVIOUS window's count, never
 * the running count of the current one. Deriving it from a growing counter
 * would shrink the probability as a window filled up, making a job's chance of
 * being sampled depend on when inside the window it arrived — which is exactly
 * the correlation that would bias the estimate during a burst.
 *
 * State is per process and held in memory, so this must be resolved as a
 * singleton; a fresh instance per event would keep every rate estimate at zero.
 */
class PickupSampler
{
    /**
     * Window state per connection+queue.
     *
     * Keyed rather than global because a group worker polls several queues at
     * once. With one shared counter, a queue running at five thousand jobs a
     * second set the sampling probability for the queue next to it running at
     * two — so the quiet queue contributed roughly one sample every twenty-five
     * seconds, never reached sla.min_samples, and its p95 never existed. The
     * SLA-driven strategy went blind on it for as long as its noisy neighbour
     * stayed hot.
     *
     * @var array<string, array{started_at: float, seen: int, previous: int}>
     */
    private array $windows = [];

    /** @var \Closure(): float */
    private \Closure $clock;

    /** @var \Closure(): float */
    private \Closure $randomizer;

    /**
     * @param  int  $maxPerSecond  Pickups this process forwards per second before it starts sampling.
     * @param  \Closure(): float|null  $clock  Returns the current unix time as a float.
     * @param  \Closure(): float|null  $randomizer  Returns a float in [0.0, 1.0).
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $maxPerSecond = 100,
        private readonly float $windowSeconds = 1.0,
        ?\Closure $clock = null,
        ?\Closure $randomizer = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->randomizer = $randomizer ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * Whether this pickup should be written to the sample store.
     */
    public function shouldRecord(string $connection = '', string $queue = ''): bool
    {
        if (! $this->enabled || $this->maxPerSecond <= 0) {
            return true;
        }

        $window = $this->rollWindow(self::key($connection, $queue));
        $budget = $this->budget();

        if ($window['previous'] <= $budget) {
            return true;
        }

        return ($this->randomizer)() < $budget / $window['previous'];
    }

    /**
     * The probability the most recent window is being sampled at, for telemetry
     * and the debug command. 1.0 means every pickup is being recorded.
     */
    public function currentSampleRate(string $connection = '', string $queue = ''): float
    {
        if (! $this->enabled || $this->maxPerSecond <= 0) {
            return 1.0;
        }

        $previous = $this->projectedPreviousCount(self::key($connection, $queue));
        $budget = $this->budget();

        return $previous <= $budget ? 1.0 : $budget / $previous;
    }

    /**
     * NUL-separated so a connection or queue containing the separator cannot
     * make two different pairs share one window. WorkloadName rejects NUL in
     * either name, so it cannot appear by accident.
     */
    private static function key(string $connection, string $queue): string
    {
        return $connection."\0".$queue;
    }

    /**
     * What the previous window's count would be if a pickup arrived now.
     *
     * Projected rather than read straight out of state: the window only rolls
     * when a pickup arrives, so a queue that has gone quiet would otherwise
     * report the rate it had while it was busy — forever, to telemetry and to
     * the debug command.
     */
    private function projectedPreviousCount(string $key): int
    {
        $window = $this->windows[$key] ?? null;

        if ($window === null) {
            return 0;
        }

        $elapsed = ($this->clock)() - $window['started_at'];

        if ($elapsed < $this->windowSeconds) {
            return $window['previous'];
        }

        return $elapsed < $this->windowSeconds * 2 ? $window['seen'] : 0;
    }

    private function budget(): int
    {
        return (int) ceil($this->maxPerSecond * $this->windowSeconds);
    }

    /**
     * @return array{started_at: float, seen: int, previous: int}
     */
    private function rollWindow(string $key): array
    {
        $now = ($this->clock)();
        $window = $this->windows[$key] ?? ['started_at' => $now, 'seen' => 0, 'previous' => 0];

        $elapsed = $now - $window['started_at'];

        if ($elapsed >= $this->windowSeconds) {
            // A process that idles for several windows must not carry a stale
            // rate into the burst that wakes it up, so anything older than one
            // full window resets rather than shifts.
            $window = [
                'started_at' => $now,
                'seen' => 0,
                'previous' => $elapsed < $this->windowSeconds * 2 ? $window['seen'] : 0,
            ];
        }

        $window['seen']++;
        $this->windows[$key] = $window;

        return $window;
    }
}
