<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Policies\AggressiveScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

beforeEach(function () {
    $this->policy = new AggressiveScaleDownPolicy;
});

test('returns null for scale up decisions', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up needed',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeNull();
});

test('returns null for hold decisions', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 5,
        reason: 'Hold workers',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeNull();
});

test('returns null for non-idle scale down decisions allowing full scale down', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 5,
        reason: 'Scale down to target',
        predictedPickupTime: 5.0, // Non-idle: still has predicted work
    );

    $result = $this->policy->beforeScaling($decision);

    // Non-idle, non-minimal target: pass through for full unrestricted scale-down
    expect($result)->toBeNull();
});

test('forces immediate scale-down to target for idle queue scaling to zero', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 0,
        reason: 'Queue is empty',
        predictedPickupTime: 0.0, // Idle
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeInstanceOf(ScalingDecision::class)
        ->and($result->targetWorkers)->toBe(0)
        ->and($result->reason)->toContain('AggressiveScaleDownPolicy')
        ->and($result->reason)->toContain('idle queue');
});

test('forces immediate scale-down when predicted pickup time is null and target is minimal', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 8,
        targetWorkers: 1,
        reason: 'Low demand',
        predictedPickupTime: null, // No prediction = likely idle
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeInstanceOf(ScalingDecision::class)
        ->and($result->targetWorkers)->toBe(1)
        ->and($result->reason)->toContain('AggressiveScaleDownPolicy');
});

test('preserves prediction and sla values in modified decision', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 0,
        reason: 'Queue is empty',
        predictedPickupTime: 0.0,
        slaTarget: 30,
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result->predictedPickupTime)->toBe(0.0)
        ->and($result->slaTarget)->toBe(30);
});

test('includes original reason in modified decision', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 0,
        reason: 'Original scale-down reason',
        predictedPickupTime: 0.0,
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result->reason)->toContain('AggressiveScaleDownPolicy')
        ->and($result->reason)->toContain('Original scale-down reason');
});

test('afterScaling does nothing', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 2,
        reason: 'Scale down',
    );

    $this->policy->afterScaling($decision);

    expect(true)->toBeTrue();
});
