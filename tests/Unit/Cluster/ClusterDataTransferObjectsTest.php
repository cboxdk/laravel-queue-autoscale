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
        cpuPercent: 42.5,
        memoryPercent: 63.1,
        queueWorkers: ['redis:default' => 2],
        groupWorkers: ['redis:mailers' => 1],
    );

    $decoded = ClusterManagerState::fromArray($state->toArray());

    expect($decoded->managerId)->toBe('manager-a')
        ->and($decoded->host)->toBe('orderscale-0')
        ->and($decoded->maxWorkers)->toBe(10)
        ->and($decoded->queueWorkers)->toBe(['redis:default' => 2])
        ->and($decoded->groupWorkers)->toBe(['redis:mailers' => 1]);
});

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
