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

test('returns null for scale down decisions allowing full scale down', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 10,
        targetWorkers: 2,
        reason: 'Scale down to minimum',
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeNull();
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
