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
    private float $windowStartedAt;

    private int $seenThisWindow = 0;

    private int $seenLastWindow = 0;

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
        $this->windowStartedAt = ($this->clock)();
    }

    /**
     * Whether this pickup should be written to the sample store.
     */
    public function shouldRecord(): bool
    {
        if (! $this->enabled || $this->maxPerSecond <= 0) {
            return true;
        }

        $this->rollWindowIfElapsed();

        $this->seenThisWindow++;

        $budget = (int) ceil($this->maxPerSecond * $this->windowSeconds);

        if ($this->seenLastWindow <= $budget) {
            return true;
        }

        return ($this->randomizer)() < $budget / $this->seenLastWindow;
    }

    /**
     * The probability the most recent window is being sampled at, for telemetry
     * and the debug command. 1.0 means every pickup is being recorded.
     */
    public function currentSampleRate(): float
    {
        if (! $this->enabled || $this->maxPerSecond <= 0) {
            return 1.0;
        }

        $budget = (int) ceil($this->maxPerSecond * $this->windowSeconds);

        if ($this->seenLastWindow <= $budget) {
            return 1.0;
        }

        return $budget / $this->seenLastWindow;
    }

    private function rollWindowIfElapsed(): void
    {
        $now = ($this->clock)();
        $elapsed = $now - $this->windowStartedAt;

        if ($elapsed < $this->windowSeconds) {
            return;
        }

        // A process that idles for several windows must not carry a stale rate
        // into the burst that wakes it up, so anything older than one full
        // window resets rather than shifts.
        $this->seenLastWindow = $elapsed < $this->windowSeconds * 2
            ? $this->seenThisWindow
            : 0;

        $this->seenThisWindow = 0;
        $this->windowStartedAt = $now;
    }
}
