<?php

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\FuseConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;
use Cbox\LaravelQueueAutoscale\Tests\IntegrationTestCase;
use Cbox\LaravelQueueAutoscale\Tests\TestCase;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Cbox\Telemetry\TelemetryManager;

// Scoped rather than ->in(__DIR__) so tests/Integration can claim its own
// base case: those specs talk to real infrastructure and need queue-metrics'
// provider registered, which the faked suites deliberately do without.
uses(TestCase::class)->in('Unit', 'Feature', 'Simulation');
uses(IntegrationTestCase::class)->in('Integration');

/**
 * The telemetry integration lives behind an optional dev dependency
 * (cboxdk/laravel-telemetry, required on Laravel 12+ only). Skip the
 * Pest-style telemetry specs cleanly when it isn't installed, e.g. on the
 * Laravel 11 CI leg, instead of fataling on a missing class.
 */
uses()
    ->beforeEach(function () {
        if (! class_exists(TelemetryManager::class)) {
            $this->markTestSkipped('requires cboxdk/laravel-telemetry');
        }
    })
    ->in('Feature/Telemetry');

/**
 * Helper function to build a v2 QueueConfiguration for tests.
 *
 * Accepts flat overrides that map to the old v1 property names for convenience:
 * - slaTarget      → sla.targetSeconds       (default 30)
 * - slaPercentile  → sla.percentile          (default 95)
 * - minWorkers     → workers.min             (default 1)
 * - maxWorkers     → workers.max             (default 10)
 * - fuse           → array merged over the default FuseConfiguration args
 *
 * Any key not listed above is ignored.
 */
function makeQueueConfig(array $overrides = []): QueueConfiguration
{
    $slaTarget = (int) ($overrides['slaTarget'] ?? $overrides['maxPickupTimeSeconds'] ?? 30);
    $slaPercentile = (int) ($overrides['slaPercentile'] ?? 95);
    $minWorkers = (int) ($overrides['minWorkers'] ?? 1);
    $maxWorkers = (int) ($overrides['maxWorkers'] ?? 10);
    $connection = (string) ($overrides['connection'] ?? 'redis');
    $queue = (string) ($overrides['queue'] ?? 'default');

    $fuse = array_merge([
        'enabled' => true,
        'failureThresholdPercent' => 50.0,
        'minSamples' => 20,
        'windowSeconds' => 60,
        'cooldownSeconds' => 60,
    ], $overrides['fuse'] ?? []);

    return new QueueConfiguration(
        connection: $connection,
        queue: $queue,
        sla: new SlaConfiguration(
            targetSeconds: $slaTarget,
            percentile: $slaPercentile,
            windowSeconds: 300,
            minSamples: 20,
        ),
        forecast: new ForecastConfiguration(
            forecasterClass: LinearRegressionForecaster::class,
            policyClass: ModerateForecastPolicy::class,
            horizonSeconds: 60,
            historySeconds: 300,
        ),
        spawnCompensation: new SpawnCompensationConfiguration(
            enabled: true,
            fallbackSeconds: 2.0,
            minSamples: 5,
            emaAlpha: 0.2,
        ),
        workers: new WorkerConfiguration(
            min: $minWorkers,
            max: $maxWorkers,
            tries: 3,
            maxTimeSeconds: 3600,
            timeoutSeconds: 300,
            sleepSeconds: 3,
            shutdownTimeoutSeconds: 30,
        ),
        fuse: new FuseConfiguration(
            enabled: (bool) $fuse['enabled'],
            failureThresholdPercent: (float) $fuse['failureThresholdPercent'],
            minSamples: (int) $fuse['minSamples'],
            windowSeconds: (int) $fuse['windowSeconds'],
            cooldownSeconds: (int) $fuse['cooldownSeconds'],
        ),
    );
}

/**
 * Helper function to create QueueMetricsData for tests
 */
function createMetrics(array $overrides = []): QueueMetricsData
{
    return QueueMetricsData::fromArray(array_merge([
        'connection' => 'redis',
        'queue' => 'default',
        'depth' => 0,
        'pending' => 0,
        'scheduled' => 0,
        'reserved' => 0,
        'oldest_job_age' => 0,
        'age_status' => 'normal',
        'throughput_per_minute' => 0.0,
        'avg_duration' => 0.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 0.0,
        'active_workers' => 0,
        'driver' => 'redis',
        'health' => [],
        'calculated_at' => now()->toIso8601String(),
    ], $overrides));
}

/**
 * ElasticMQ speaks the SQS wire protocol and backs the SQS integration specs.
 * Shared here rather than in one spec file so every suite that needs it can
 * ask, including when a single file is run on its own.
 *
 *     docker run -d --name autoscale-elasticmq -p 9324:9324 \
 *         softwaremill/elasticmq-native:1.6.11
 */
function elasticMqEndpoint(): string
{
    $endpoint = env('SQS_TEST_ENDPOINT', 'http://localhost:9324');

    return rtrim(is_string($endpoint) ? $endpoint : 'http://localhost:9324', '/');
}

function elasticMqReachable(): bool
{
    $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    $body = @file_get_contents(elasticMqEndpoint().'/?Action=ListQueues', false, $context);

    return is_string($body) && str_contains($body, 'ListQueuesResponse');
}
