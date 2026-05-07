<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;

/**
 * Create a minimal ClusterManagerState for summary tests.
 */
function makeSummaryManagerState(string $id, int $maxWorkers, int $totalWorkers = 0): ClusterManagerState
{
    return new ClusterManagerState(
        managerId: $id,
        host: "host-{$id}",
        lastSeenAt: (int) (microtime(true) * 1000),
        totalWorkers: $totalWorkers,
        maxWorkers: $maxWorkers,
        availableWorkerCapacity: max($maxWorkers - $totalWorkers, 0),
        capacityLimiter: 'cpu',
        cpuPercent: 10.0,
        cpuCores: 2.0,
        cpuUsableCores: 1.8,
        cpuReservedCores: 0.2,
        memoryPercent: 30.0,
        memoryTotalMb: 1024.0,
        memoryUsedMb: 307.2,
        memoryFreeMb: 716.8,
        queueCount: 1,
        groupCount: 0,
        packageVersion: '3.6.1',
        queueWorkers: [],
        groupWorkers: [],
    );
}

/**
 * Invoke private buildClusterSummary via reflection.
 *
 * @param  array<int, ClusterManagerState>  $managers
 * @param  array<int, array<string, mixed>>  $workloads
 * @param  array<int, array<string, mixed>>  $scalingDecisions
 * @return array<string, mixed>
 */
function invokeBuildClusterSummary(array $managers, array $workloads, array $scalingDecisions = []): array
{
    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'buildClusterSummary');

    return $method->invoke($manager, $managers, $workloads, $scalingDecisions);
}

it('includes scaling_decisions key in cluster summary', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');

    $managers = [makeSummaryManagerState('mgr-1', maxWorkers: 10, totalWorkers: 3)];
    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'default',
            'driver' => 'redis',
            'current_workers' => 1,
            'demand' => 3,
            'target_workers' => 3,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 30,
            'pending' => 50,
            'oldest_job_age' => 5,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 100.0,
            'active_workers' => 1,
            'utilization_percent' => 80.0,
            'member_queues' => ['default'],
            'action' => 1,
        ],
    ];

    $summary = invokeBuildClusterSummary($managers, $workloads);

    expect($summary)->toHaveKey('scaling_decisions')
        ->and($summary['scaling_decisions'])->toBeArray()
        ->and($summary['scaling_decisions'])->toBeEmpty();
});

it('includes scaling decision entries when provided', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');

    $managers = [makeSummaryManagerState('mgr-1', maxWorkers: 10, totalWorkers: 3)];
    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'fast',
            'driver' => 'redis',
            'current_workers' => 1,
            'demand' => 6,
            'target_workers' => 6,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 30,
            'pending' => 140,
            'oldest_job_age' => 5,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 200.0,
            'active_workers' => 1,
            'utilization_percent' => 90.0,
            'member_queues' => ['fast'],
            'action' => 1,
        ],
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'slow',
            'driver' => 'redis',
            'current_workers' => 1,
            'demand' => 3,
            'target_workers' => 3,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 60,
            'pending' => 60,
            'oldest_job_age' => 10,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 50.0,
            'active_workers' => 1,
            'utilization_percent' => 70.0,
            'member_queues' => ['slow'],
            'action' => 1,
        ],
    ];

    $scalingDecisions = [
        [
            'workload_key' => 'queue:redis:fast',
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'fast',
            'from' => 1,
            'to' => 6,
            'action' => 'scale_up',
            'reason' => 'cluster:scale_up',
        ],
        [
            'workload_key' => 'queue:redis:slow',
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'slow',
            'from' => 1,
            'to' => 3,
            'action' => 'scale_up',
            'reason' => 'cluster:scale_up',
        ],
    ];

    $summary = invokeBuildClusterSummary($managers, $workloads, $scalingDecisions);

    expect($summary['scaling_decisions'])->toHaveCount(2)
        ->and($summary['scaling_decisions'][0]['workload_key'])->toBe('queue:redis:fast')
        ->and($summary['scaling_decisions'][0]['from'])->toBe(1)
        ->and($summary['scaling_decisions'][0]['to'])->toBe(6)
        ->and($summary['scaling_decisions'][0]['action'])->toBe('scale_up')
        ->and($summary['scaling_decisions'][1]['workload_key'])->toBe('queue:redis:slow')
        ->and($summary['scaling_decisions'][1]['from'])->toBe(1)
        ->and($summary['scaling_decisions'][1]['to'])->toBe(3);
});

it('omits hold decisions from scaling_decisions array', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');

    $managers = [makeSummaryManagerState('mgr-1', maxWorkers: 10, totalWorkers: 5)];
    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'stable',
            'driver' => 'redis',
            'current_workers' => 5,
            'demand' => 5,
            'target_workers' => 5,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 30,
            'pending' => 0,
            'oldest_job_age' => 0,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 10.0,
            'active_workers' => 5,
            'utilization_percent' => 20.0,
            'member_queues' => ['stable'],
            'action' => 0,
        ],
    ];

    // No scaling decisions passed (hold decisions are excluded before calling buildClusterSummary)
    $summary = invokeBuildClusterSummary($managers, $workloads);

    expect($summary['scaling_decisions'])->toBeEmpty();
});

it('includes scale_down decisions in scaling_decisions array', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');

    $managers = [makeSummaryManagerState('mgr-1', maxWorkers: 10, totalWorkers: 8)];
    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'draining',
            'driver' => 'redis',
            'current_workers' => 8,
            'demand' => 2,
            'target_workers' => 2,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 30,
            'pending' => 0,
            'oldest_job_age' => 0,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 5.0,
            'active_workers' => 2,
            'utilization_percent' => 10.0,
            'member_queues' => ['draining'],
            'action' => -1,
        ],
    ];

    $scalingDecisions = [
        [
            'workload_key' => 'queue:redis:draining',
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'draining',
            'from' => 8,
            'to' => 2,
            'action' => 'scale_down',
            'reason' => 'cluster:scale_down',
        ],
    ];

    $summary = invokeBuildClusterSummary($managers, $workloads, $scalingDecisions);

    expect($summary['scaling_decisions'])->toHaveCount(1)
        ->and($summary['scaling_decisions'][0]['action'])->toBe('scale_down')
        ->and($summary['scaling_decisions'][0]['from'])->toBe(8)
        ->and($summary['scaling_decisions'][0]['to'])->toBe(2);
});

it('cluster summary receives historical decisions from ClusterStore', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);

    $managers = [makeSummaryManagerState('mgr-1', maxWorkers: 10, totalWorkers: 5)];
    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'default',
            'driver' => 'redis',
            'current_workers' => 5,
            'demand' => 5,
            'target_workers' => 5,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 30,
            'pending' => 0,
            'oldest_job_age' => 0,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 10.0,
            'active_workers' => 5,
            'utilization_percent' => 20.0,
            'member_queues' => ['default'],
            'action' => 0,
        ],
    ];

    // Simulate historical decisions that were recorded in prior cycles
    $historicalDecisions = [
        [
            'workload_key' => 'queue:redis:default',
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'default',
            'from' => 1,
            'to' => 5,
            'action' => 'scale_up',
            'reason' => 'cluster:scale_up',
            'recorded_at' => microtime(true) - 300,
        ],
        [
            'workload_key' => 'queue:redis:default',
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'default',
            'from' => 5,
            'to' => 8,
            'action' => 'scale_up',
            'reason' => 'cluster:scale_up',
            'recorded_at' => microtime(true) - 120,
        ],
        [
            'workload_key' => 'queue:redis:default',
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'default',
            'from' => 8,
            'to' => 5,
            'action' => 'scale_down',
            'reason' => 'cluster:scale_down',
            'recorded_at' => microtime(true) - 60,
        ],
    ];

    // buildClusterSummary accepts historical decisions the same as current-cycle ones
    $summary = invokeBuildClusterSummary($managers, $workloads, $historicalDecisions);

    expect($summary['scaling_decisions'])->toHaveCount(3)
        ->and($summary['scaling_decisions'][0]['recorded_at'])->toBeFloat()
        ->and($summary['scaling_decisions'][0]['action'])->toBe('scale_up')
        ->and($summary['scaling_decisions'][0]['from'])->toBe(1)
        ->and($summary['scaling_decisions'][0]['to'])->toBe(5)
        ->and($summary['scaling_decisions'][2]['action'])->toBe('scale_down')
        ->and($summary['scaling_decisions'][2]['from'])->toBe(8)
        ->and($summary['scaling_decisions'][2]['to'])->toBe(5);
});

it('produces scale_up signal when unclamped demand exceeds cluster capacity', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');

    // 2 hosts, 5 max workers each = 10 total capacity
    $managers = [
        makeSummaryManagerState('host-a', maxWorkers: 5, totalWorkers: 5),
        makeSummaryManagerState('host-b', maxWorkers: 5, totalWorkers: 5),
    ];

    // 4 queues with demand summing to 30, but targets clamped to fit capacity (10)
    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'fast',
            'driver' => 'redis',
            'current_workers' => 4,
            'demand' => 15,
            'target_workers' => 4,
            'worker_min' => 1,
            'worker_max' => 20,
            'sla_target_seconds' => 10,
            'pending' => 240,
            'oldest_job_age' => 12,
            'oldest_job_age_status' => 'warning',
            'throughput_per_minute' => 300.0,
            'active_workers' => 4,
            'utilization_percent' => 95.0,
            'member_queues' => ['fast'],
            'action' => 0,
        ],
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'priority',
            'driver' => 'redis',
            'current_workers' => 3,
            'demand' => 8,
            'target_workers' => 3,
            'worker_min' => 1,
            'worker_max' => 15,
            'sla_target_seconds' => 15,
            'pending' => 80,
            'oldest_job_age' => 8,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 150.0,
            'active_workers' => 3,
            'utilization_percent' => 90.0,
            'member_queues' => ['priority'],
            'action' => 0,
        ],
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'slow',
            'driver' => 'redis',
            'current_workers' => 2,
            'demand' => 5,
            'target_workers' => 2,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 60,
            'pending' => 40,
            'oldest_job_age' => 15,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 50.0,
            'active_workers' => 2,
            'utilization_percent' => 85.0,
            'member_queues' => ['slow'],
            'action' => 0,
        ],
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'email',
            'driver' => 'redis',
            'current_workers' => 1,
            'demand' => 2,
            'target_workers' => 1,
            'worker_min' => 1,
            'worker_max' => 5,
            'sla_target_seconds' => 120,
            'pending' => 10,
            'oldest_job_age' => 3,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 20.0,
            'active_workers' => 1,
            'utilization_percent' => 60.0,
            'member_queues' => ['email'],
            'action' => 0,
        ],
    ];

    $summary = invokeBuildClusterSummary($managers, $workloads);

    // required_workers should reflect unclamped demand (30), not clamped targets (10)
    expect($summary['required_workers'])->toBe(30)
        ->and($summary['total_worker_capacity'])->toBe(10)
        ->and($summary['scale_signal']['action'])->toBe('scale_up')
        ->and($summary['scale_signal']['recommended_hosts'])->toBeGreaterThan(2);
});

it('produces hold signal when demand fits within cluster capacity', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');

    // 2 hosts, 5 max workers each = 10 total capacity
    $managers = [
        makeSummaryManagerState('host-a', maxWorkers: 5, totalWorkers: 4),
        makeSummaryManagerState('host-b', maxWorkers: 5, totalWorkers: 4),
    ];

    // demand sums to 8, fits within capacity of 10
    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'fast',
            'driver' => 'redis',
            'current_workers' => 4,
            'demand' => 5,
            'target_workers' => 5,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 10,
            'pending' => 0,
            'oldest_job_age' => 2,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 100.0,
            'active_workers' => 4,
            'utilization_percent' => 50.0,
            'member_queues' => ['fast'],
            'action' => 1,
        ],
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'slow',
            'driver' => 'redis',
            'current_workers' => 3,
            'demand' => 3,
            'target_workers' => 3,
            'worker_min' => 1,
            'worker_max' => 10,
            'sla_target_seconds' => 60,
            'pending' => 0,
            'oldest_job_age' => 1,
            'oldest_job_age_status' => 'normal',
            'throughput_per_minute' => 50.0,
            'active_workers' => 3,
            'utilization_percent' => 40.0,
            'member_queues' => ['slow'],
            'action' => 0,
        ],
    ];

    $summary = invokeBuildClusterSummary($managers, $workloads);

    expect($summary['required_workers'])->toBe(8)
        ->and($summary['scale_signal']['action'])->not->toBe('scale_up')
        ->and($summary['scale_signal']['recommended_hosts'])->toBe(2);
});

it('surfaces both demand and target_workers in workload entries', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.app_id', 'test-cluster');

    $managers = [makeSummaryManagerState('host-a', maxWorkers: 10, totalWorkers: 8)];

    $workloads = [
        [
            'type' => 'queue',
            'connection' => 'redis',
            'name' => 'fast',
            'driver' => 'redis',
            'current_workers' => 8,
            'demand' => 25,
            'target_workers' => 8,
            'worker_min' => 1,
            'worker_max' => 30,
            'sla_target_seconds' => 10,
            'pending' => 200,
            'oldest_job_age' => 15,
            'oldest_job_age_status' => 'warning',
            'throughput_per_minute' => 400.0,
            'active_workers' => 8,
            'utilization_percent' => 95.0,
            'member_queues' => ['fast'],
            'action' => 0,
        ],
    ];

    $summary = invokeBuildClusterSummary($managers, $workloads);

    $workload = $summary['workloads'][0];
    expect($workload)->toHaveKey('demand')
        ->and($workload)->toHaveKey('target_workers')
        ->and($workload['demand'])->toBe(25)
        ->and($workload['target_workers'])->toBe(8)
        ->and($workload['demand'])->toBeGreaterThan($workload['target_workers']);
});
