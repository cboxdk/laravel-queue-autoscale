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

it('produces stable assignments across 30 cycles with frozen cluster state', function () {
    // First cycle: establish initial distribution
    $managers = [
        makeManagerState('large', maxWorkers: 15, queueWorkers: ['redis:fast' => 14]),
        makeManagerState('small', maxWorkers: 8, queueWorkers: ['redis:fast' => 4]),
    ];

    $assignedTotals = ['large' => 0, 'small' => 0];
    $firstResult = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 18, $assignedTotals);

    // Run 30 additional cycles with identical target but fluctuating reported counts
    // (simulating heartbeat jitter that caused the original thrashing bug)
    for ($cycle = 0; $cycle < 30; $cycle++) {
        // Simulate fluctuating reported counts — workers appear to shift between hosts
        $largeReported = $firstResult['large'] + ($cycle % 3 === 0 ? -1 : ($cycle % 3 === 1 ? 1 : 0));
        $smallReported = $firstResult['small'] + ($cycle % 3 === 0 ? 1 : ($cycle % 3 === 1 ? -1 : 0));

        $managers = [
            makeManagerState('large', maxWorkers: 15, queueWorkers: ['redis:fast' => $largeReported]),
            makeManagerState('small', maxWorkers: 8, queueWorkers: ['redis:fast' => $smallReported]),
        ];

        $assignedTotals = ['large' => 0, 'small' => 0];
        $result = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 18, $assignedTotals);

        expect($result)->toBe($firstResult, "Cycle {$cycle}: assignments shifted despite stable target");
    }
});

it('recomputes distribution when target changes', function () {
    // Cycle 1: target = 10
    $managers = [
        makeManagerState('a', maxWorkers: 10, queueWorkers: ['redis:fast' => 5]),
        makeManagerState('b', maxWorkers: 10, queueWorkers: ['redis:fast' => 5]),
    ];

    $assignedTotals = ['a' => 0, 'b' => 0];
    $result1 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 10, $assignedTotals);
    expect(array_sum($result1))->toBe(10);

    // Cycle 2: target increases to 14 — must recompute, not reuse cached
    $managers = [
        makeManagerState('a', maxWorkers: 10, queueWorkers: ['redis:fast' => $result1['a']]),
        makeManagerState('b', maxWorkers: 10, queueWorkers: ['redis:fast' => $result1['b']]),
    ];

    $assignedTotals = ['a' => 0, 'b' => 0];
    $result2 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 14, $assignedTotals);
    expect(array_sum($result2))->toBe(14);
});

it('recomputes distribution when a manager joins', function () {
    // Cycle 1: two managers
    $managers = [
        makeManagerState('a', maxWorkers: 10, queueWorkers: ['redis:fast' => 5]),
        makeManagerState('b', maxWorkers: 10, queueWorkers: ['redis:fast' => 5]),
    ];

    $assignedTotals = ['a' => 0, 'b' => 0];
    $result1 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 10, $assignedTotals);

    // Cycle 2: third manager joins — must recompute
    $managers = [
        makeManagerState('a', maxWorkers: 10, queueWorkers: ['redis:fast' => $result1['a']]),
        makeManagerState('b', maxWorkers: 10, queueWorkers: ['redis:fast' => $result1['b']]),
        makeManagerState('c', maxWorkers: 10, queueWorkers: []),
    ];

    $assignedTotals = ['a' => 0, 'b' => 0, 'c' => 0];
    $result2 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 10, $assignedTotals);
    expect(array_sum($result2))->toBe(10)
        ->and(array_key_exists('c', $result2))->toBeTrue();
});

it('recomputes distribution when a manager leaves', function () {
    // Cycle 1: three managers
    $managers = [
        makeManagerState('a', maxWorkers: 10, queueWorkers: ['redis:fast' => 4]),
        makeManagerState('b', maxWorkers: 10, queueWorkers: ['redis:fast' => 3]),
        makeManagerState('c', maxWorkers: 10, queueWorkers: ['redis:fast' => 3]),
    ];

    $assignedTotals = ['a' => 0, 'b' => 0, 'c' => 0];
    $result1 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 10, $assignedTotals);

    // Cycle 2: manager c leaves — must recompute and redistribute its share
    $managers = [
        makeManagerState('a', maxWorkers: 10, queueWorkers: ['redis:fast' => $result1['a']]),
        makeManagerState('b', maxWorkers: 10, queueWorkers: ['redis:fast' => $result1['b']]),
    ];

    $assignedTotals = ['a' => 0, 'b' => 0];
    $result2 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 10, $assignedTotals);
    expect(array_sum($result2))->toBe(10)
        ->and(array_key_exists('c', $result2))->toBeFalse();
});

it('recomputes distribution when cached assignments exceed host capacity', function () {
    // Cycle 1: two workloads, first gets distributed
    $managers = [
        makeManagerState('a', maxWorkers: 6, queueWorkers: ['redis:fast' => 4]),
        makeManagerState('b', maxWorkers: 6, queueWorkers: ['redis:fast' => 2]),
    ];

    $assignedTotals = ['a' => 0, 'b' => 0];
    $result1 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 6, $assignedTotals);

    // Cycle 2: another workload consumed capacity first, so cached fast
    // assignments may no longer fit
    $assignedTotals = ['a' => 4, 'b' => 4]; // other workloads already took 4 on each host
    $result2 = invokeDistributeClusterTarget($managers, 'queue:redis:fast', 6, $assignedTotals);

    // Must respect host capacity: a can take max 2 more, b can take max 2 more
    expect($result2['a'])->toBeLessThanOrEqual(2)
        ->and($result2['b'])->toBeLessThanOrEqual(2)
        ->and(array_sum($result2))->toBeLessThanOrEqual(4);
});
