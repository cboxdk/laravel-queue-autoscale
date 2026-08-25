<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;

/**
 * A heartbeat is JSON another host wrote, and it can be nonsense.
 *
 * `is_numeric()` accepts INF and NAN, and JSON carries them in without
 * complaint: `{"cpu_percent": 1e999}` decodes to INF. That value travelled into
 * the cluster summary and reached `json_encode(..., JSON_THROW_ON_ERROR)`,
 * which refuses it — so one host writing a nonsense heartbeat threw inside the
 * leader's cycle AFTER recommendations had been published but BEFORE the leader
 * applied its own, leaving the leader itself unscaled every cycle until that
 * host aged out of the registry.
 */
function hostileState(array $overrides): ClusterManagerState
{
    return ClusterManagerState::fromArray(array_merge([
        'manager_id' => 'mgr-1',
        'host' => 'host-1',
        'last_seen_at' => 1000,
        'total_workers' => 1,
        'max_workers' => 8,
        'available_worker_capacity' => 7,
        'capacity_limiter' => 'cpu',
        'cpu_percent' => 10.0,
        'cpu_cores' => 4.0,
        'cpu_usable_cores' => 3.6,
        'cpu_reserved_cores' => 0.4,
        'memory_percent' => 30.0,
        'memory_total_mb' => 2048.0,
        'memory_used_mb' => 614.4,
        'memory_free_mb' => 1433.6,
        'queue_count' => 1,
        'group_count' => 0,
        'package_version' => '4.2.0',
        'queue_workers' => [],
        'group_workers' => [],
    ], $overrides));
}

test('an infinite value in a heartbeat does not survive into the cluster state', function (): void {
    $decoded = json_decode('{"cpu_percent": 1e999}', true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['cpu_percent'])->toBeInfinite();

    $state = hostileState(['cpu_percent' => $decoded['cpu_percent']]);

    expect($state->cpuPercent)->toBe(0.0);
});

test('every float a heartbeat carries survives being re-encoded', function (): void {
    // The property that actually matters: whatever a host wrote, the summary
    // the leader builds from it must be encodable.
    $state = hostileState([
        'cpu_percent' => INF,
        'cpu_cores' => -INF,
        'memory_percent' => NAN,
        'memory_total_mb' => INF,
        'memory_used_mb' => NAN,
        'memory_free_mb' => -INF,
        'cpu_usable_cores' => INF,
        'cpu_reserved_cores' => NAN,
    ]);

    $encoded = json_encode($state->toArray(), JSON_THROW_ON_ERROR);

    expect($encoded)->toBeString()
        ->and(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR)['cpu_percent'])->toBe(0);
});

test('an ordinary heartbeat is untouched', function (): void {
    $state = hostileState(['cpu_percent' => 42.5, 'memory_percent' => 61.25]);

    expect($state->cpuPercent)->toBe(42.5)
        ->and($state->memoryPercent)->toBe(61.25);
});
