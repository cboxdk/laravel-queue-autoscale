<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

/**
 * Per-queue tuning for the failure fuse.
 *
 * The fuse stops the autoscaler from scaling up into a downstream outage. When
 * the observed failure rate over the sliding window crosses
 * $failureThresholdPercent (with at least $minSamples outcomes recorded), the
 * fuse trips and the queue is held at workers.min until $cooldownSeconds have
 * passed and a probe confirms recovery.
 */
readonly class FuseConfiguration
{
    public function __construct(
        public bool $enabled,
        public float $failureThresholdPercent,
        public int $minSamples,
        public int $windowSeconds,
        public int $cooldownSeconds,
    ) {
        if ($failureThresholdPercent <= 0.0 || $failureThresholdPercent > 100.0) {
            throw new InvalidConfigurationException("fuse.failure_threshold_percent must be in (0, 100], got {$failureThresholdPercent}");
        }

        if ($minSamples < 1) {
            throw new InvalidConfigurationException("fuse.min_samples must be >= 1, got {$minSamples}");
        }

        if ($windowSeconds < 1) {
            throw new InvalidConfigurationException("fuse.window_seconds must be >= 1, got {$windowSeconds}");
        }

        if ($cooldownSeconds < 1) {
            throw new InvalidConfigurationException("fuse.cooldown_seconds must be >= 1, got {$cooldownSeconds}");
        }
    }

    /**
     * Build from a profile's optional 'fuse' block.
     *
     * The block is optional so profiles and literal config arrays written
     * before the fuse existed keep working on package defaults. The global
     * queue-autoscale.fuse.enabled flag is a master switch: it can turn the
     * fuse off everywhere, but never turns it on for a queue that opted out.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function fromArray(array $overrides): self
    {
        return new self(
            enabled: AutoscaleConfiguration::fuseEnabled()
                && self::boolValue($overrides['enabled'] ?? null, true),
            failureThresholdPercent: self::floatValue($overrides['failure_threshold_percent'] ?? null, 50.0),
            minSamples: self::intValue($overrides['min_samples'] ?? null, 20),
            windowSeconds: self::intValue($overrides['window_seconds'] ?? null, 60),
            cooldownSeconds: self::intValue($overrides['cooldown_seconds'] ?? null, 60),
        );
    }

    private static function boolValue(mixed $value, bool $default): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_numeric($value) => (float) $value !== 0.0,
            default => $default,
        };
    }

    private static function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function floatValue(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
