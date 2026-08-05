<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

/**
 * Per-queue worker settings.
 *
 * The two timeouts are different things and were previously one:
 * $maxTimeSeconds is how long the worker PROCESS lives before recycling
 * (`--max-time`), while $timeoutSeconds is how long a single JOB may run
 * (`--timeout`). The old name for the first was `timeout_seconds`, so an
 * operator setting 3600 believed they had allowed hour-long jobs when they
 * had only asked for hourly process recycling, leaving the real job timeout
 * at Laravel's default.
 */
readonly class WorkerConfiguration
{
    public function __construct(
        public int $min,
        public int $max,
        public int $tries,
        public int $maxTimeSeconds,
        public int $timeoutSeconds,
        public int $sleepSeconds,
        public int $shutdownTimeoutSeconds,
        public bool $scalable = true,
    ) {
        if ($min < 0) {
            throw new InvalidConfigurationException("workers.min must be >= 0, got {$min}");
        }

        if ($max < $min) {
            throw new InvalidConfigurationException("workers.max ({$max}) must be >= workers.min ({$min})");
        }

        if ($tries < 1) {
            throw new InvalidConfigurationException("workers.tries must be >= 1, got {$tries}");
        }

        if ($maxTimeSeconds <= 0) {
            throw new InvalidConfigurationException("workers.max_time_seconds must be > 0, got {$maxTimeSeconds}");
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidConfigurationException("workers.timeout_seconds must be > 0, got {$timeoutSeconds}");
        }

        if ($timeoutSeconds >= $maxTimeSeconds) {
            throw new InvalidConfigurationException(
                "workers.timeout_seconds ({$timeoutSeconds}) must be less than workers.max_time_seconds ".
                "({$maxTimeSeconds}) — a per-job timeout longer than the worker's whole lifetime can never fire."
            );
        }

        if (! $scalable && $min !== $max) {
            throw new InvalidConfigurationException(
                "workers.scalable=false requires workers.min ({$min}) to equal workers.max ({$max})"
            );
        }

        if (! $scalable && $min === 0) {
            throw new InvalidConfigurationException(
                'workers.scalable=false requires workers.min >= 1 (a non-scalable queue needs at least one worker)'
            );
        }
    }

    /**
     * Target worker count for this queue.
     *
     * For scalable queues this returns the minimum; the autoscaler is free to
     * exceed it up to max. For non-scalable (supervised) queues this is the
     * exact count we enforce — the autoscaler never deviates.
     */
    public function pinnedCount(): int
    {
        return $this->min;
    }
}
