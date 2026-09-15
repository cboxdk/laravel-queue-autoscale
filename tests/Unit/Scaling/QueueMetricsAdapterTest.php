<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\QueueMetricsAdapter;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * The adapter is this package's serialization boundary with the metrics
 * package. A depth field that is threaded into QueueMetricsData everywhere
 * except here reads as a silent zero in production while every strategy unit
 * test still passes, so the seam itself is what these pin.
 */
function adapterDepthPayload(int $delayedDueNow): array
{
    return [
        'connection' => 'redis',
        'queue' => 'default',
        'driver' => 'redis',
        'depth' => [
            'total' => 4,
            'pending' => 0,
            'scheduled' => 4,
            'delayed_due_now' => $delayedDueNow,
            'reserved' => 0,
            'oldest_job_age_seconds' => 0,
            'oldest_job_age_status' => 'normal',
        ],
        'performance_60s' => ['throughput_per_minute' => 0.0, 'avg_duration_ms' => 0.0],
        'lifetime' => ['failure_rate_percent' => 0.0],
        'workers' => ['active_count' => 0, 'current_busy_percent' => 0.0],
    ];
}

test('the discovery payload carries the due delayed count into typed metrics', function (): void {
    $mapped = (new QueueMetricsAdapter)->mapFields(adapterDepthPayload(3));

    expect(QueueMetricsData::fromArray($mapped)->delayedDueNow)->toBe(3);
});

test('a missing due delayed count reads as zero rather than failing', function (): void {
    $payload = adapterDepthPayload(0);
    unset($payload['depth']['delayed_due_now']);

    $mapped = (new QueueMetricsAdapter)->mapFields($payload);

    expect(QueueMetricsData::fromArray($mapped)->delayedDueNow)->toBe(0);
});

test('a group sums the due delayed count across its member queues', function (): void {
    config([
        'queue-autoscale.groups' => [
            'reports' => [
                'connection' => 'redis',
                'queues' => ['reports-a', 'reports-b'],
                'workers' => ['min' => 0, 'max' => 5],
            ],
        ],
    ]);

    $group = GroupConfiguration::allFromConfig()['reports'];

    $aggregated = (new QueueMetricsAdapter)->aggregateGroup($group, [
        'redis:reports-a' => createMetrics(['queue' => 'reports-a', 'scheduled' => 2, 'delayed_due_now' => 2]),
        'redis:reports-b' => createMetrics(['queue' => 'reports-b', 'scheduled' => 5, 'delayed_due_now' => 1]),
    ]);

    expect($aggregated->delayedDueNow)->toBe(3)
        ->and($aggregated->scheduled)->toBe(7);
});
