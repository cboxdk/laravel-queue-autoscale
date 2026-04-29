<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;

it('serializes cluster manager state payloads', function () {
    $state = new ClusterManagerState(
        managerId: 'manager-a',
        host: 'orderscale-0',
        lastSeenAt: 1234,
        totalWorkers: 3,
        maxWorkers: 10,
        availableWorkerCapacity: 7,
        capacityLimiter: 'memory',
        cpuPercent: 42.5,
        cpuCores: 8.0,
        cpuUsableCores: 7,
        cpuReservedCores: 1,
        memoryPercent: 63.1,
        memoryTotalMb: 8192.0,
        memoryUsedMb: 5169.2,
        memoryFreeMb: 3022.8,
        queueCount: 1,
        groupCount: 1,
        packageVersion: '2.0.0',
        queueWorkers: ['redis:default' => 2],
        groupWorkers: ['redis:mailers' => 1],
    );

    $decoded = ClusterManagerState::fromArray($state->toArray());

    expect($decoded->managerId)->toBe('manager-a')
        ->and($decoded->host)->toBe('orderscale-0')
        ->and($decoded->maxWorkers)->toBe(10)
        ->and($decoded->capacityLimiter)->toBe('memory')
        ->and($decoded->cpuCores)->toBe(8.0)
        ->and($decoded->cpuUsableCores)->toBe(7)
        ->and($decoded->cpuReservedCores)->toBe(1)
        ->and($decoded->memoryTotalMb)->toBe(8192.0)
        ->and($decoded->memoryUsedMb)->toBe(5169.2)
        ->and($decoded->memoryFreeMb)->toBe(3022.8)
        ->and($decoded->queueCount)->toBe(1)
        ->and($decoded->groupCount)->toBe(1)
        ->and($decoded->packageVersion)->toBe('2.0.0')
        ->and($decoded->queueWorkers)->toBe(['redis:default' => 2])
        ->and($decoded->groupWorkers)->toBe(['redis:mailers' => 1]);
});

it('round-trips fractional cpu core values through serialization', function (float $cpuCores) {
    $state = new ClusterManagerState(
        managerId: 'cgroup-manager',
        host: 'container-0',
        lastSeenAt: 5000,
        totalWorkers: 1,
        maxWorkers: 5,
        availableWorkerCapacity: 4,
        capacityLimiter: 'cpu',
        cpuPercent: 25.0,
        cpuCores: $cpuCores,
        cpuUsableCores: 1,
        cpuReservedCores: 0,
        memoryPercent: 40.0,
        memoryTotalMb: 512.0,
        memoryUsedMb: 204.8,
        memoryFreeMb: 307.2,
        queueCount: 1,
        groupCount: 0,
        packageVersion: '3.2.0',
        queueWorkers: ['redis:default' => 1],
        groupWorkers: [],
    );

    $decoded = ClusterManagerState::fromArray($state->toArray());

    expect($decoded->cpuCores)->toBe($cpuCores)
        ->and($decoded->cpuCores)->toBeFloat();
})->with([0.2, 0.5, 1.5, 2.0, 4.0]);

it('resolves recommendation targets for queues and groups', function () {
    $recommendation = new ClusterRecommendation(
        managerId: 'manager-a',
        issuedAt: 1234,
        workloads: [
            ClusterRecommendation::queueWorkloadKey('redis', 'default') => 2,
            ClusterRecommendation::groupWorkloadKey('redis', 'mailers') => 1,
        ],
    );

    $decoded = ClusterRecommendation::fromArray($recommendation->toArray());

    expect($decoded->targetForQueue('redis', 'default'))->toBe(2)
        ->and($decoded->targetForGroup('redis', 'mailers'))->toBe(1)
        ->and($decoded->targetForQueue('redis', 'missing'))->toBe(0);
});
