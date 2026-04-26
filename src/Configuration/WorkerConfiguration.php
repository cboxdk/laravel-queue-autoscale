<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class WorkerConfiguration
{
    public function __construct(
        public int $min,
        public int $max,
        public int $tries,
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

        if ($timeoutSeconds <= 0) {
            throw new InvalidConfigurationException("workers.timeout_seconds must be > 0, got {$timeoutSeconds}");
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
