<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Fuse;

use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed outcome counters using fixed tumbling buckets.
 *
 * Outcomes are counted into a bucket derived from wall-clock time, and each
 * bucket key carries a TTL of twice the window — so expired buckets clean
 * themselves up and no sweeper is needed.
 *
 * Reads sum the current AND previous bucket. A single tumbling bucket would
 * drop to zero samples the instant it rolls over, which reads as "not enough
 * data" and would let a tripped queue resume scaling for a whole window. The
 * two-bucket sum keeps between one and two windows of evidence in view at all
 * times, at the cost of remembering failures slightly longer than the nominal
 * window.
 *
 * The Cache facade is used rather than Redis directly because the fuse must
 * work in single-host mode, which this package supports without Redis. Any
 * shared cache backend works; the array driver confines the fuse to one
 * process, which is only correct in tests.
 */
class CacheFailureWindowStore implements FailureWindowStoreContract
{
    private const int STATE_TTL_SECONDS = 86400;

    /**
     * @param  (\Closure(): float)|null  $clock  Overridable wall clock. Buckets are derived from
     *                                           microtime() rather than Carbon, so Laravel's
     *                                           travel() helper cannot move them; tests pass a
     *                                           clock to exercise bucket rollover.
     */
    public function __construct(
        private readonly ?\Closure $clock = null,
    ) {}

    public function recordOutcome(string $connection, string $queue, bool $failed, int $windowSeconds): void
    {
        $bucket = $this->bucketStart($this->now(), $windowSeconds);
        $ttl = $windowSeconds * 2;

        $this->increment($this->counterKey($connection, $queue, $bucket, 'total'), $ttl);

        if ($failed) {
            $this->increment($this->counterKey($connection, $queue, $bucket, 'failures'), $ttl);
        }
    }

    public function currentWindow(string $connection, string $queue, int $windowSeconds): array
    {
        $current = $this->bucketStart($this->now(), $windowSeconds);
        $previous = $current - $windowSeconds;

        return [
            'total' => $this->read($this->counterKey($connection, $queue, $current, 'total'))
                + $this->read($this->counterKey($connection, $queue, $previous, 'total')),
            'failures' => $this->read($this->counterKey($connection, $queue, $current, 'failures'))
                + $this->read($this->counterKey($connection, $queue, $previous, 'failures')),
        ];
    }

    public function resetWindow(string $connection, string $queue, int $windowSeconds): void
    {
        $current = $this->bucketStart($this->now(), $windowSeconds);
        $previous = $current - $windowSeconds;

        foreach ([$current, $previous] as $bucket) {
            Cache::forget($this->counterKey($connection, $queue, $bucket, 'total'));
            Cache::forget($this->counterKey($connection, $queue, $bucket, 'failures'));
        }
    }

    public function readState(string $connection, string $queue): ?array
    {
        $raw = Cache::get($this->stateKey($connection, $queue));

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['state'], $decoded['changed_at'])) {
            return null;
        }

        if (! is_string($decoded['state']) || ! is_numeric($decoded['changed_at'])) {
            return null;
        }

        return [
            'state' => $decoded['state'],
            'changed_at' => (float) $decoded['changed_at'],
        ];
    }

    public function writeState(string $connection, string $queue, string $state, float $changedAt): void
    {
        Cache::put(
            $this->stateKey($connection, $queue),
            json_encode(['state' => $state, 'changed_at' => $changedAt], JSON_THROW_ON_ERROR),
            self::STATE_TTL_SECONDS,
        );
    }

    private function increment(string $key, int $ttl): void
    {
        Cache::add($key, 0, $ttl);
        Cache::increment($key);
    }

    private function read(string $key): int
    {
        $value = Cache::get($key, 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private function bucketStart(float $timestamp, int $windowSeconds): int
    {
        return intdiv((int) $timestamp, $windowSeconds) * $windowSeconds;
    }

    private function counterKey(string $connection, string $queue, int $bucket, string $suffix): string
    {
        return sprintf('autoscale:fuse:window:%s:%s:%d:%s', $connection, $queue, $bucket, $suffix);
    }

    private function stateKey(string $connection, string $queue): string
    {
        return sprintf('autoscale:fuse:state:%s:%s', $connection, $queue);
    }
}
