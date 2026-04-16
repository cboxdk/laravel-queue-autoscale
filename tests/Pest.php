<?php

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;
use Cbox\LaravelQueueAutoscale\Tests\TestCase;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

uses(TestCase::class)->in(__DIR__);

/**
 * Helper function to build a v2 QueueConfiguration for tests.
 *
 * Accepts flat overrides that map to the old v1 property names for convenience:
 * - slaTarget      → sla.targetSeconds       (default 30)
 * - slaPercentile  → sla.percentile          (default 95)
 * - minWorkers     → workers.min             (default 1)
 * - maxWorkers     → workers.max             (default 10)
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
            timeoutSeconds: 3600,
            sleepSeconds: 3,
            shutdownTimeoutSeconds: 30,
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
