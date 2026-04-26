<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // Fresh cache state per test so rate-limiter lock keys don't leak between tests.
    Cache::flush();
    $this->policy = new BreachNotificationPolicy;
    config()->set('queue-autoscale.manager.log_channel', 'test-channel');
});

test('beforeScaling always returns null', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
        predictedPickupTime: 45.0,
        slaTarget: 30,
    );

    $result = $this->policy->beforeScaling($decision);

    expect($result)->toBeNull();
});

test('logs warning when sla breach risk detected', function () {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->andReturnSelf();

    Log::shouldReceive('warning')
        ->once()
        ->with('SLA BREACH RISK DETECTED', Mockery::type('array'));

    Log::shouldReceive('notice')
        ->once()
        ->withAnyArgs();

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up due to breach risk',
        predictedPickupTime: 45.0,
        slaTarget: 30,
    );

    $this->policy->afterScaling($decision);
});

test('logs notice when sla utilization above 90 percent', function () {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->andReturnSelf();

    Log::shouldReceive('notice')
        ->once()
        ->with(Mockery::pattern('/High SLA utilization:/'), Mockery::type('array'));

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 6,
        reason: 'Slight scale up',
        predictedPickupTime: 27.5,
        slaTarget: 30,
    );

    $this->policy->afterScaling($decision);
});

test('does not log when no breach risk and low utilization', function () {
    Log::shouldReceive('channel')->never();

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 6,
        reason: 'Normal scaling',
        predictedPickupTime: 15.0,
        slaTarget: 30,
    );

    $this->policy->afterScaling($decision);
});

test('does not log when predicted pickup time is null', function () {
    Log::shouldReceive('channel')->never();

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 6,
        reason: 'No prediction available',
    );

    $this->policy->afterScaling($decision);
});

test('exactly 90 percent utilization triggers notice', function () {
    Log::shouldReceive('channel')
        ->with('test-channel')
        ->andReturnSelf();

    Log::shouldReceive('notice')
        ->once()
        ->with(Mockery::pattern('/High SLA utilization:/'), Mockery::type('array'));

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 6,
        reason: 'At threshold',
        predictedPickupTime: 27.0,
        slaTarget: 30,
    );

    $this->policy->afterScaling($decision);
});

test('suppresses repeated breach-risk logs within cooldown window', function () {
    $policy = new BreachNotificationPolicy(new AlertRateLimiter(cooldownSeconds: 300));

    Log::shouldReceive('channel')->with('test-channel')->andReturnSelf();
    // First call: breach warning + high-util notice each log once.
    Log::shouldReceive('warning')->once()->with('SLA BREACH RISK DETECTED', Mockery::type('array'));
    Log::shouldReceive('notice')->once()->withAnyArgs();

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'payments',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up due to breach risk',
        predictedPickupTime: 45.0,
        slaTarget: 30,
    );

    // Second and third call with the same queue should be silenced by the rate limiter.
    $policy->afterScaling($decision);
    $policy->afterScaling($decision);
    $policy->afterScaling($decision);
});

test('allows breach-risk log for a different queue during first queues cooldown', function () {
    $policy = new BreachNotificationPolicy(new AlertRateLimiter(cooldownSeconds: 300));

    Log::shouldReceive('channel')->with('test-channel')->andReturnSelf();
    Log::shouldReceive('warning')->twice()->with('SLA BREACH RISK DETECTED', Mockery::type('array'));
    Log::shouldReceive('notice')->twice()->withAnyArgs();

    $payments = new ScalingDecision(
        connection: 'redis',
        queue: 'payments',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up due to breach risk',
        predictedPickupTime: 45.0,
        slaTarget: 30,
    );

    $emails = new ScalingDecision(
        connection: 'redis',
        queue: 'emails',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up due to breach risk',
        predictedPickupTime: 45.0,
        slaTarget: 30,
    );

    $policy->afterScaling($payments);
    $policy->afterScaling($emails);
});
