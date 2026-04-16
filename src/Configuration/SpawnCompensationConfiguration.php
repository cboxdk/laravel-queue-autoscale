<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class SpawnCompensationConfiguration
{
    public function __construct(
        public bool $enabled,
        public float $fallbackSeconds,
        public int $minSamples,
        public float $emaAlpha,
    ) {
        if ($fallbackSeconds < 0.0) {
            throw new InvalidConfigurationException("spawn_compensation.fallback_seconds must be >= 0, got {$fallbackSeconds}");
        }

        if ($minSamples < 1) {
            throw new InvalidConfigurationException("spawn_compensation.min_samples must be >= 1, got {$minSamples}");
        }

        if ($emaAlpha <= 0.0 || $emaAlpha > 1.0) {
            throw new InvalidConfigurationException("spawn_compensation.ema_alpha must be in (0, 1], got {$emaAlpha}");
        }
    }
}
