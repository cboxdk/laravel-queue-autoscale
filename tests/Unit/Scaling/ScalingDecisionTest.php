<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

test('creates instance with all properties', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'High load detected',
        predictedPickupTime: 25.0,
        slaTarget: 30,
    );

    expect($decision->connection)->toBe('redis')
        ->and($decision->queue)->toBe('default')
        ->and($decision->currentWorkers)->toBe(5)
        ->and($decision->targetWorkers)->toBe(10)
        ->and($decision->reason)->toBe('High load detected')
        ->and($decision->predictedPickupTime)->toBe(25.0)
        ->and($decision->slaTarget)->toBe(30);
});

test('shouldScaleUp returns true when target exceeds current', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up needed',
    );

    expect($decision->shouldScaleUp())->toBeTrue()
        ->and($decision->shouldScaleDown())->toBeFalse()
        ->and($decision->shouldHold())->toBeFalse();
});

test('shouldScaleDown returns true when target below current', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Scale down needed',
    );

    expect($decision->shouldScaleDown())->toBeTrue()
        ->and($decision->shouldScaleUp())->toBeFalse()
        ->and($decision->shouldHold())->toBeFalse();
});

test('shouldHold returns true when target equals current', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 5,
        reason: 'Hold workers',
    );

    expect($decision->shouldHold())->toBeTrue()
        ->and($decision->shouldScaleUp())->toBeFalse()
        ->and($decision->shouldScaleDown())->toBeFalse();
});

test('workersToAdd returns positive difference when scaling up', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
    );

    expect($decision->workersToAdd())->toBe(5);
});

test('workersToAdd returns zero when scaling down', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Scale down',
    );

    expect($decision->workersToAdd())->toBe(0);
});

test('workersToRemove returns positive difference when scaling down', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Scale down',
    );

    expect($decision->workersToRemove())->toBe(5);
});

test('workersToRemove returns zero when scaling up', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
    );

    expect($decision->workersToRemove())->toBe(0);
});

test('action returns scale_up for scaling up', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
    );

    expect($decision->action())->toBe('scale_up');
});

test('action returns scale_down for scaling down', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Scale down',
    );

    expect($decision->action())->toBe('scale_down');
});

test('action returns hold for no change', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 5,
        reason: 'Hold',
    );

    expect($decision->action())->toBe('hold');
});

test('isSlaBreachRisk returns true when predicted exceeds sla', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'SLA breach risk',
        predictedPickupTime: 45.0,
        slaTarget: 30,
    );

    expect($decision->isSlaBreachRisk())->toBeTrue();
});

test('isSlaBreachRisk returns false when predicted within sla', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 6,
        reason: 'Within SLA',
        predictedPickupTime: 20.0,
        slaTarget: 30,
    );

    expect($decision->isSlaBreachRisk())->toBeFalse();
});

test('isSlaBreachRisk returns false when predicted equals sla', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 6,
        reason: 'At SLA boundary',
        predictedPickupTime: 30.0,
        slaTarget: 30,
    );

    expect($decision->isSlaBreachRisk())->toBeFalse();
});

test('isSlaBreachRisk returns false when predictedPickupTime is null', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 6,
        reason: 'No prediction',
    );

    expect($decision->isSlaBreachRisk())->toBeFalse();
});

test('accepts capacity calculation result', function () {
    $capacity = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: 'config',
    );

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
        capacity: $capacity,
    );

    expect($decision->capacity)->toBe($capacity)
        ->and($decision->capacity->finalMaxWorkers)->toBe(10);
});
