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
    }
}
