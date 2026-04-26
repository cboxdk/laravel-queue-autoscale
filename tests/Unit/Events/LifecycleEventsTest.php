<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Events\ClusterLeaderChanged;
use Cbox\LaravelQueueAutoscale\Events\ClusterManagerPresenceChanged;
use Cbox\LaravelQueueAutoscale\Events\ClusterSummaryPublished;

test('creates autoscale manager started event', function () {
    $event = new AutoscaleManagerStarted(
        managerId: 'manager-a',
        host: 'orderscale-0',
        clusterEnabled: true,
        clusterId: 'orderscale-prod',
        intervalSeconds: 5,
        startedAt: 1234,
        packageVersion: '2.0.0',
    );

    expect($event->managerId)->toBe('manager-a')
        ->and($event->host)->toBe('orderscale-0')
        ->and($event->clusterEnabled)->toBeTrue()
        ->and($event->clusterId)->toBe('orderscale-prod')
        ->and($event->intervalSeconds)->toBe(5)
        ->and($event->startedAt)->toBe(1234)
        ->and($event->packageVersion)->toBe('2.0.0');
});

test('creates autoscale manager stopped event', function () {
    $event = new AutoscaleManagerStopped(
        managerId: 'manager-a',
        host: 'orderscale-0',
        clusterEnabled: true,
        clusterId: 'orderscale-prod',
        startedAt: 1234,
        stoppedAt: 2345,
        reason: 'restart_signal',
        workerCount: 3,
        packageVersion: '2.0.0',
    );

    expect($event->managerId)->toBe('manager-a')
        ->and($event->reason)->toBe('restart_signal')
        ->and($event->workerCount)->toBe(3)
        ->and($event->stoppedAt)->toBe(2345);
});

test('creates cluster leader changed event', function () {
    $event = new ClusterLeaderChanged(
        clusterId: 'orderscale-prod',
        previousLeaderId: 'manager-a',
        currentLeaderId: 'manager-b',
        observedByManagerId: 'manager-c',
        changedAt: 1234,
    );

    expect($event->clusterId)->toBe('orderscale-prod')
        ->and($event->previousLeaderId)->toBe('manager-a')
        ->and($event->currentLeaderId)->toBe('manager-b')
        ->and($event->observedByManagerId)->toBe('manager-c')
        ->and($event->changedAt)->toBe(1234);
});

test('creates cluster manager presence changed event', function () {
    $event = new ClusterManagerPresenceChanged(
        clusterId: 'orderscale-prod',
        managerIds: ['manager-a', 'manager-b'],
        addedManagerIds: ['manager-b'],
        removedManagerIds: [],
        leaderId: 'manager-a',
        observedByManagerId: 'manager-a',
        observedAt: 1234,
    );

    expect($event->managerIds)->toBe(['manager-a', 'manager-b'])
        ->and($event->addedManagerIds)->toBe(['manager-b'])
        ->and($event->removedManagerIds)->toBe([])
        ->and($event->leaderId)->toBe('manager-a');
});

test('creates cluster summary published event', function () {
    $event = new ClusterSummaryPublished(
        clusterId: 'orderscale-prod',
        leaderId: 'manager-a',
        summary: ['required_workers' => 4],
        publishedAt: 1234,
    );

    expect($event->clusterId)->toBe('orderscale-prod')
        ->and($event->leaderId)->toBe('manager-a')
        ->and($event->summary)->toBe(['required_workers' => 4])
        ->and($event->publishedAt)->toBe(1234);
});
