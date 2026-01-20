<?php

use Cbox\LaravelQueueAutoscale\Tests\TestCase;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

uses(TestCase::class)->in(__DIR__);

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
