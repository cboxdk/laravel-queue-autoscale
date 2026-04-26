<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Illuminate\Support\Str;

final readonly class AutoscaleConfiguration
{
    public static function applicationScopeId(): string
    {
        $appName = self::stringConfig('app.name', 'laravel');
        $appEnv = self::stringConfig('app.env', 'production');
        $basePath = function_exists('base_path') ? base_path() : getcwd();
        $hash = substr(sha1($basePath.'|'.$appName.'|'.$appEnv), 0, 12);

        return Str::slug($appName, '-').'-'.$appEnv.'-'.$hash;
    }

    public static function isEnabled(): bool
    {
        return (bool) config('queue-autoscale.enabled', true);
    }

    public static function managerId(): string
    {
        $configured = config('queue-autoscale.manager_id');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $host = self::hostLabel();
        $source = self::managerIdentitySource();

        return sprintf(
            '%s-%s',
            Str::slug($host, '-'),
            substr(sha1($source), 0, 12),
        );
    }

    public static function hostLabel(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'unknown-host';
    }

    public static function clusterAppId(): string
    {
        $appName = self::stringConfig('app.name', 'laravel');
        $appEnv = self::stringConfig('app.env', 'production');
        $queueDefault = self::stringConfig('queue.default', 'default');
        $queueConfig = config("queue.connections.{$queueDefault}", []);

        $signature = json_encode([
            'app' => $appName,
            'env' => $appEnv,
            'queue_default' => $queueDefault,
            'queue_connection' => $queueConfig,
        ]);

        $hash = substr(sha1($signature ?: $appName.'|'.$appEnv.'|'.$queueDefault), 0, 12);

        return Str::slug($appName, '-').'-'.$appEnv.'-'.$hash;
    }

    public static function pickupTimeStore(): string
    {
        $configured = config('queue-autoscale.pickup_time.store', 'auto');

        return is_string($configured) ? trim($configured) : 'auto';
    }

    public static function spawnLatencyTracker(): string
    {
        $configured = config('queue-autoscale.spawn_latency.tracker', 'auto');

        return is_string($configured) ? trim($configured) : 'auto';
    }

    public static function clusterEnabled(): bool
    {
        return (bool) config('queue-autoscale.cluster.enabled', false);
    }

    public static function clusterHeartbeatTtlSeconds(): int
    {
        return self::intConfig('queue-autoscale.cluster.heartbeat_ttl_seconds', 15);
    }

    public static function clusterLeaderLeaseSeconds(): int
    {
        return self::intConfig('queue-autoscale.cluster.leader_lease_seconds', 15);
    }

    public static function clusterRecommendationTtlSeconds(): int
    {
        return self::intConfig('queue-autoscale.cluster.recommendation_ttl_seconds', 30);
    }

    public static function clusterSummaryTtlSeconds(): int
    {
        return self::intConfig('queue-autoscale.cluster.summary_ttl_seconds', 30);
    }

    private static function managerIdentitySource(): string
    {
        $parts = [];
        $envCandidates = [
            'k8s_pod_uid' => env('K8S_POD_UID'),
            'pod_uid' => env('POD_UID'),
            'ecs_task_arn' => env('ECS_TASK_ARN'),
            'container_id' => env('CONTAINER_ID'),
            'hostname_env' => env('HOSTNAME'),
        ];

        foreach ($envCandidates as $label => $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $parts[] = "{$label}=".trim($candidate);
            }
        }

        $machineIds = [
            '/etc/machine-id',
            '/var/lib/dbus/machine-id',
        ];

        foreach ($machineIds as $path) {
            if (! is_file($path)) {
                continue;
            }

            $value = @file_get_contents($path);

            if (is_string($value) && trim($value) !== '') {
                $parts[] = basename($path).'='.trim($value);
            }
        }

        $parts[] = 'host='.self::hostLabel();
        $parts[] = 'ip='.(string) gethostbyname(self::hostLabel());

        return implode('|', array_unique(array_filter($parts, static fn (string $value): bool => trim($value) !== '')));
    }

    public static function evaluationIntervalSeconds(): int
    {
        return self::intConfig('queue-autoscale.manager.evaluation_interval_seconds', 5);
    }

    public static function logChannel(): string
    {
        return self::stringConfig('queue-autoscale.manager.log_channel', 'stack');
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
        return self::intValue(self::scalingConfig('trend_window_seconds', 300), 300);
    }

    public static function forecastHorizonSeconds(): int
    {
        return self::intValue(self::scalingConfig('forecast_horizon_seconds', 60), 60);
    }

    public static function breachThreshold(): float
    {
        return self::floatValue(self::scalingConfig('breach_threshold', 0.5), 0.5);
    }

    /**
     * Fallback job time when metrics are unavailable
     *
     * Used by scaling algorithms when actual job duration data is not available.
     * Should be set based on typical job characteristics in your application.
     */
    public static function fallbackJobTimeSeconds(): float
    {
        return self::floatValue(self::scalingConfig('fallback_job_time_seconds', 2.0), 2.0);
    }

    /**
     * Minimum confidence required to use estimated arrival rate
     *
     * Below this confidence level, processing rate is used instead.
     */
    public static function minArrivalRateConfidence(): float
    {
        return self::floatValue(self::scalingConfig('min_arrival_rate_confidence', 0.5), 0.5);
    }

    public static function maxCpuPercent(): int
    {
        return self::intConfig('queue-autoscale.limits.max_cpu_percent', 85);
    }

    public static function maxMemoryPercent(): int
    {
        return self::intConfig('queue-autoscale.limits.max_memory_percent', 85);
    }

    public static function workerMemoryMbEstimate(): int
    {
        return self::intConfig('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    }

    public static function reserveCpuCores(): int
    {
        return self::intConfig('queue-autoscale.limits.reserve_cpu_cores', 1);
    }

    public static function workerTimeoutSeconds(): int
    {
        return self::intConfig('queue-autoscale.workers.timeout_seconds', 3600);
    }

    public static function workerTries(): int
    {
        return self::intConfig('queue-autoscale.workers.tries', 3);
    }

    public static function workerSleepSeconds(): int
    {
        return self::intConfig('queue-autoscale.workers.sleep_seconds', 3);
    }

    public static function shutdownTimeoutSeconds(): int
    {
        return self::intConfig('queue-autoscale.workers.shutdown_timeout_seconds', 30);
    }

    public static function healthCheckIntervalSeconds(): int
    {
        return self::intConfig('queue-autoscale.workers.health_check_interval_seconds', 10);
    }

    public static function strategyClass(): string
    {
        return self::stringConfig('queue-autoscale.strategy');
    }

    /** @return array<int, class-string> */
    public static function policyClasses(): array
    {
        $policies = config('queue-autoscale.policies', []);

        if (! is_array($policies)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $policy): string => is_string($policy) ? $policy : '', $policies),
            static fn (string $policy): bool => $policy !== '' && class_exists($policy),
        ));
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
                ? self::stringValue($config['connection'], 'default')
                : 'default';

            $result["{$connection}:{$queueName}"] = [
                'connection' => $connection,
                'queue' => $queueName,
            ];
        }

        return $result;
    }

    private static function intConfig(string $key, int $default): int
    {
        return self::intValue(config($key, $default), $default);
    }

    private static function stringConfig(string $key, string $default = ''): string
    {
        return self::stringValue(config($key, $default), $default);
    }

    private static function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function floatValue(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private static function stringValue(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }
}
