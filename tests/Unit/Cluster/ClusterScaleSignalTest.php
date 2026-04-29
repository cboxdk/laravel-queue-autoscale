<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;

/**
 * Helper to invoke private clusterScaleSignal via reflection.
 *
 * @param  array<int, array<string, mixed>>  $workloads
 * @return array<string, int|string>
 */
function invokeClusterScaleSignal(
    int $currentHosts,
    int $recommendedHosts,
    int $requiredWorkers,
    int $totalWorkerCapacity,
    int $totalWorkers,
    array $workloads,
): array {
    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'clusterScaleSignal');

    return $method->invoke(
        $manager,
        $currentHosts,
        $recommendedHosts,
        $requiredWorkers,
        $totalWorkerCapacity,
        $totalWorkers,
        $workloads,
    );
}

it('returns hold when utilization is at 80 percent', function () {
    $signal = invokeClusterScaleSignal(
        currentHosts: 2,
        recommendedHosts: 1,
        requiredWorkers: 4,
        totalWorkerCapacity: 5,
        totalWorkers: 4, // 4/5 = 80%
        workloads: [['target_workers' => 2, 'current_workers' => 2, 'pending' => 0]],
    );

    expect($signal['action'])->toBe('hold')
        ->and($signal['reason'])->toContain('utilization');
});

it('returns hold when utilization exceeds 80 percent', function () {
    $signal = invokeClusterScaleSignal(
        currentHosts: 2,
        recommendedHosts: 1,
        requiredWorkers: 4,
        totalWorkerCapacity: 4,
        totalWorkers: 4, // 4/4 = 100%
        workloads: [['target_workers' => 2, 'current_workers' => 2, 'pending' => 0]],
    );

    expect($signal['action'])->toBe('hold');
});

it('allows scale down when utilization is below 80 percent', function () {
    $signal = invokeClusterScaleSignal(
        currentHosts: 2,
        recommendedHosts: 1,
        requiredWorkers: 2,
        totalWorkerCapacity: 10,
        totalWorkers: 2, // 2/10 = 20%
        workloads: [['target_workers' => 1, 'current_workers' => 1, 'pending' => 0]],
    );

    expect($signal['action'])->toBe('scale_down');
});

it('returns hold when any workload has pending jobs', function () {
    $signal = invokeClusterScaleSignal(
        currentHosts: 2,
        recommendedHosts: 1,
        requiredWorkers: 4,
        totalWorkerCapacity: 10,
        totalWorkers: 4, // 4/10 = 40% — below threshold
        workloads: [
            ['target_workers' => 2, 'current_workers' => 2, 'pending' => 0],
            ['target_workers' => 2, 'current_workers' => 2, 'pending' => 5],
        ],
    );

    expect($signal['action'])->toBe('hold')
        ->and($signal['reason'])->toContain('pending');
});

it('returns hold when any workload wants to scale up', function () {
    $signal = invokeClusterScaleSignal(
        currentHosts: 2,
        recommendedHosts: 1,
        requiredWorkers: 4,
        totalWorkerCapacity: 10,
        totalWorkers: 3, // 3/10 = 30% — below threshold
        workloads: [
            ['target_workers' => 3, 'current_workers' => 1, 'pending' => 0],
            ['target_workers' => 1, 'current_workers' => 2, 'pending' => 0],
        ],
    );

    expect($signal['action'])->toBe('hold');
});

it('returns scale up when required exceeds capacity', function () {
    $signal = invokeClusterScaleSignal(
        currentHosts: 1,
        recommendedHosts: 2,
        requiredWorkers: 15,
        totalWorkerCapacity: 6,
        totalWorkers: 6,
        workloads: [],
    );

    expect($signal['action'])->toBe('scale_up')
        ->and($signal['recommended_hosts'])->toBeGreaterThanOrEqual(2);
});

it('returns hold at 79 percent utilization with no pressure', function () {
    // Boundary: 79% is below threshold, should allow scale_down
    $signal = invokeClusterScaleSignal(
        currentHosts: 2,
        recommendedHosts: 1,
        requiredWorkers: 4,
        totalWorkerCapacity: 100,
        totalWorkers: 79, // 79/100 = 79%
        workloads: [['target_workers' => 2, 'current_workers' => 2, 'pending' => 0]],
    );

    expect($signal['action'])->toBe('scale_down');
});
