<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class AutoscaleConfiguration
{
    public static function isEnabled(): bool
    {
        return (bool) config('queue-autoscale.enabled', true);
    }

    public static function managerId(): string
    {
        return (string) config('queue-autoscale.manager_id', gethostname());
    }

    public static function evaluationIntervalSeconds(): int
    {
        return (int) config('queue-autoscale.manager.evaluation_interval_seconds', 5);
    }

    public static function logChannel(): string
    {
        return (string) config('queue-autoscale.manager.log_channel', 'stack');
    }

    /**
     * Get scaling config value with backwards compatibility for 'prediction' key
     */
    private static function scalingConfig(string $key, mixed $default): mixed
    {
        // Try new 'scaling' key first, then fall back to deprecated 'prediction'
        return config("queue-autoscale.scaling.{$key}")
            ?? config("queue-autoscale.prediction.{$key}")
            ?? $default;
    }

    public static function trendWindowSeconds(): int
    {
        return (int) self::scalingConfig('trend_window_seconds', 300);
    }

    public static function forecastHorizonSeconds(): int
    {
        return (int) self::scalingConfig('forecast_horizon_seconds', 60);
    }

    public static function breachThreshold(): float
    {
        return (float) self::scalingConfig('breach_threshold', 0.5);
    }

    /**
     * Fallback job time when metrics are unavailable
     *
     * Used by scaling algorithms when actual job duration data is not available.
     * Should be set based on typical job characteristics in your application.
     */
    public static function fallbackJobTimeSeconds(): float
    {
        return (float) self::scalingConfig('fallback_job_time_seconds', 2.0);
    }

    /**
     * Minimum confidence required to use estimated arrival rate
     *
     * Below this confidence level, processing rate is used instead.
     */
    public static function minArrivalRateConfidence(): float
    {
        return (float) self::scalingConfig('min_arrival_rate_confidence', 0.5);
    }

    public static function maxCpuPercent(): int
    {
        return (int) config('queue-autoscale.limits.max_cpu_percent', 85);
    }

    public static function maxMemoryPercent(): int
    {
        return (int) config('queue-autoscale.limits.max_memory_percent', 85);
    }

    public static function workerMemoryMbEstimate(): int
    {
        return (int) config('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    }

    public static function reserveCpuCores(): int
    {
        return (int) config('queue-autoscale.limits.reserve_cpu_cores', 1);
    }

    public static function workerTimeoutSeconds(): int
    {
        return (int) config('queue-autoscale.workers.timeout_seconds', 3600);
    }

    public static function workerTries(): int
    {
        return (int) config('queue-autoscale.workers.tries', 3);
    }

    public static function workerSleepSeconds(): int
    {
        return (int) config('queue-autoscale.workers.sleep_seconds', 3);
    }

    public static function shutdownTimeoutSeconds(): int
    {
        return (int) config('queue-autoscale.workers.shutdown_timeout_seconds', 30);
    }

    public static function healthCheckIntervalSeconds(): int
    {
        return (int) config('queue-autoscale.workers.health_check_interval_seconds', 10);
    }

    public static function strategyClass(): string
    {
        return (string) config('queue-autoscale.strategy');
    }

    /** @return array<int, class-string> */
    public static function policyClasses(): array
    {
        return (array) config('queue-autoscale.policies', []);
    }

    /**
     * Queue name patterns that should be skipped entirely by the autoscaler.
     *
     * Supports fnmatch-style globs (e.g. "legacy-*", "test-?"). Excluded queues
     * are never managed, regardless of whether metrics are observed for them.
     *
     * @return array<int, string>
     */
    public static function excludedPatterns(): array
    {
        /** @var array<int, mixed> $patterns */
        $patterns = (array) config('queue-autoscale.excluded', []);

        return array_values(array_filter(
            array_map(static fn (mixed $p): string => is_string($p) ? $p : '', $patterns),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * Test whether a queue name matches any configured exclusion pattern.
     */
    public static function isExcluded(string $queue): bool
    {
        foreach (self::excludedPatterns() as $pattern) {
            if (fnmatch($pattern, $queue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all configured queues from the autoscale configuration.
     *
     * @return array<string, array{connection: string, queue: string}>
     */
    public static function configuredQueues(): array
    {
        /** @var array<string, array<string, mixed>> $queuesConfig */
        $queuesConfig = config('queue-autoscale.queues', []);
        $result = [];

        foreach ($queuesConfig as $queueName => $config) {
            $connection = isset($config['connection'])
                ? (string) $config['connection']
                : 'default';

            $result["{$connection}:{$queueName}"] = [
                'connection' => $connection,
                'queue' => $queueName,
            ];
        }

        return $result;
    }
}
