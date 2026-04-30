<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\FairShareAllocator;

/**
 * Create a minimal ClusterManagerState for distribution tests.
 */
function makeFairShareManagerState(string $id, int $maxWorkers, int $totalWorkers = 0, array $queueWorkers = []): ClusterManagerState
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
        packageVersion: '3.4.0',
        queueWorkers: $queueWorkers,
        groupWorkers: [],
    );
}

/**
 * Invoke private distributeClusterTarget via reflection.
 *
 * @param  array<int, ClusterManagerState>  $managers
 * @param  array<string, int>  $assignedTotals
 * @return array<string, int>
 */
function invokeFairShareDistributeClusterTarget(array $managers, string $workloadKey, int $targetWorkers, array &$assignedTotals): array
{
    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'distributeClusterTarget');

    return $method->invokeArgs($manager, [$managers, $workloadKey, $targetWorkers, &$assignedTotals]);
}

it('distributes fair-share targets across hosts without starvation', function () {
    // Simulate: 4 queues each demanding 12 workers, cluster capacity 10 (2 hosts x 5)
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:fast' => 12,
        'queue:redis:slow' => 12,
        'queue:redis:priority' => 12,
        'queue:redis:batch' => 12,
    ];
    $configs = [
        'queue:redis:fast' => ['min' => 1, 'max' => 20],
        'queue:redis:slow' => ['min' => 1, 'max' => 20],
        'queue:redis:priority' => ['min' => 1, 'max' => 20],
        'queue:redis:batch' => ['min' => 1, 'max' => 20],
    ];

    $targets = $allocator->allocate($demands, $configs, 10);

    // No queue should be starved
    foreach ($targets as $key => $target) {
        expect($target)->toBeGreaterThanOrEqual(1, "Queue {$key} was starved");
    }

    expect(array_sum($targets))->toBe(10);

    // Now distribute across 2 hosts
    $managers = [
        makeFairShareManagerState('host-a', maxWorkers: 5),
        makeFairShareManagerState('host-b', maxWorkers: 5),
    ];

    $assignedTotals = ['host-a' => 0, 'host-b' => 0];
    $allAssignments = [];

    foreach ($targets as $workloadKey => $target) {
        $distribution = invokeFairShareDistributeClusterTarget($managers, $workloadKey, $target, $assignedTotals);
        $allAssignments[$workloadKey] = $distribution;
    }

    // Total assigned per host should not exceed host capacity
    expect($assignedTotals['host-a'])->toBeLessThanOrEqual(5)
        ->and($assignedTotals['host-b'])->toBeLessThanOrEqual(5);

    // Total assigned across all workloads should equal the fair-share total
    $totalAssigned = array_sum($assignedTotals);
    expect($totalAssigned)->toBe(10);
});

it('allows idle queues to yield all capacity to busy queue in cluster', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:idle1' => 1,
        'queue:redis:idle2' => 1,
        'queue:redis:idle3' => 1,
        'queue:redis:busy' => 50,
    ];
    $configs = [
        'queue:redis:idle1' => ['min' => 1, 'max' => 10],
        'queue:redis:idle2' => ['min' => 1, 'max' => 10],
        'queue:redis:idle3' => ['min' => 1, 'max' => 10],
        'queue:redis:busy' => ['min' => 1, 'max' => 100],
    ];

    $targets = $allocator->allocate($demands, $configs, 20);

    // Idle queues demand exactly their min, so no contention from them
    // Busy queue gets all remaining capacity
    expect($targets['queue:redis:idle1'])->toBe(1)
        ->and($targets['queue:redis:idle2'])->toBe(1)
        ->and($targets['queue:redis:idle3'])->toBe(1)
        ->and($targets['queue:redis:busy'])->toBe(17);

    expect(array_sum($targets))->toBe(20);
});
