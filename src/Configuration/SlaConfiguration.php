<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class SlaConfiguration
{
    private const ALLOWED_PERCENTILES = [50, 75, 90, 95, 99];

    public function __construct(
        public int $targetSeconds,
        public int $percentile,
        public int $windowSeconds,
        public int $minSamples,
    ) {
        if ($targetSeconds <= 0) {
            throw new InvalidConfigurationException("sla.target_seconds must be > 0, got {$targetSeconds}");
        }

        if (! in_array($percentile, self::ALLOWED_PERCENTILES, true)) {
            throw new InvalidConfigurationException(sprintf(
                'sla.percentile must be one of %s, got %d',
                implode(', ', self::ALLOWED_PERCENTILES),
                $percentile,
            ));
        }

        if ($windowSeconds < 60) {
            throw new InvalidConfigurationException("sla.window_seconds must be >= 60, got {$windowSeconds}");
        }

        if ($minSamples < 1) {
            throw new InvalidConfigurationException("sla.min_samples must be >= 1, got {$minSamples}");
        }
    }
}
