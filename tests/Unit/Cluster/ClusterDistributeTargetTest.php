<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;

/**
 * Create a minimal ClusterManagerState for distribution tests.
 */
function makeManagerState(string $id, int $maxWorkers, int $totalWorkers = 0, array $queueWorkers = []): ClusterManagerState
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
function invokeDistributeClusterTarget(array $managers, string $workloadKey, int $targetWorkers, array &$assignedTotals): array
{
    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'distributeClusterTarget');

    return $method->invokeArgs($manager, [$managers, $workloadKey, $targetWorkers, &$assignedTotals]);
}

it('caps assignments at per-host maxWorkers', function () {
    $small = makeManagerState('small', maxWorkers: 2);
    $large = makeManagerState('large', maxWorkers: 4);
    $managers = [$small, $large];

    $assignedTotals = ['small' => 0, 'large' => 0];
    $result = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 15, $assignedTotals);

    // Total assigned should be capped at cluster capacity (2 + 4 = 6), not 15
    $totalAssigned = array_sum($result);
    expect($totalAssigned)->toBe(6)
        ->and($result['small'])->toBeLessThanOrEqual(2)
        ->and($result['large'])->toBeLessThanOrEqual(4);
});

it('distributes to least-loaded manager first', function () {
    $a = makeManagerState('a', maxWorkers: 5);
    $b = makeManagerState('b', maxWorkers: 5);
    $managers = [$a, $b];

    $assignedTotals = ['a' => 3, 'b' => 0];
    $result = invokeDistributeClusterTarget($managers, 'queue:redis:default', 2, $assignedTotals);

    // Manager B has fewer assignments, should get workers first
    expect($result['b'])->toBeGreaterThanOrEqual($result['a']);
});

it('skips full hosts in phase 2', function () {
    $full = makeManagerState('full', maxWorkers: 2);
    $available = makeManagerState('available', maxWorkers: 5);
    $managers = [$full, $available];

    // Full host already has 2 assigned
    $assignedTotals = ['full' => 2, 'available' => 0];
    $result = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 3, $assignedTotals);

    expect($result['full'])->toBe(0)
        ->and($result['available'])->toBe(3);
});

it('returns zero assignments when target is zero', function () {
    $a = makeManagerState('a', maxWorkers: 5);
    $managers = [$a];

    $assignedTotals = ['a' => 0];
    $result = invokeDistributeClusterTarget($managers, 'queue:redis:default', 0, $assignedTotals);

    expect($result['a'])->toBe(0);
});

it('preserves existing workers in phase 1', function () {
    $a = makeManagerState('a', maxWorkers: 5, queueWorkers: ['redis:fast' => 2]);
    $b = makeManagerState('b', maxWorkers: 5, queueWorkers: ['redis:fast' => 1]);
    $managers = [$a, $b];

    $assignedTotals = ['a' => 0, 'b' => 0];
    $result = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 3, $assignedTotals);

    // Should preserve existing: a=2, b=1
    expect($result['a'])->toBe(2)
        ->and($result['b'])->toBe(1);
});
