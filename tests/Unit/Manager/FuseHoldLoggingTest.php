<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Log;

function decisionLimitedBy(string $limiter, string $queue = 'default'): ScalingDecision
{
    return new ScalingDecision(
        connection: 'redis',
        queue: $queue,
        currentWorkers: 8,
        targetWorkers: 2,
        reason: 'fuse OPEN: 90.0% failure rate over 200 jobs',
        capacity: new CapacityCalculationResult(
            maxWorkersByCpu: 20,
            maxWorkersByMemory: 20,
            maxWorkersByConfig: 20,
            finalMaxWorkers: 2,
            limitingFactor: LimitingFactor::from($limiter),
        ),
    );
}

function invokeLogFuseHold(AutoscaleManager $manager, ScalingDecision $decision): void
{
    $method = new ReflectionMethod(AutoscaleManager::class, 'logFuseHold');
    $method->invoke($manager, $decision);
}

beforeEach(function (): void {
    $this->manager = app(AutoscaleManager::class);
});

test('logs a warning while the fuse is holding a queue down', function (): void {
    // A held queue scales once, on the trip, and then holds — so without
    // this the log falls silent for the rest of the outage.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Autoscaling held back by failure fuse'
                && $context['queue'] === 'default'
                && $context['target_workers'] === 2
                && str_contains($context['reason'], 'fuse OPEN');
        });

    invokeLogFuseHold($this->manager, decisionLimitedBy('fuse'));
});

test('stays quiet when something other than the fuse is the constraint', function (string $limiter): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->never();

    invokeLogFuseHold($this->manager, decisionLimitedBy($limiter));
})->with(['cpu', 'memory', 'config', 'strategy']);

test('stays quiet when there is no capacity result at all', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->never();

    invokeLogFuseHold($this->manager, new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 2,
        targetWorkers: 2,
        reason: 'steady state',
    ));
});

test('rate limits repeated holds into one line per cooldown window', function (): void {
    // The manager evaluates every few seconds; an hour-long outage must not
    // produce hundreds of identical lines.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    foreach (range(1, 25) as $ignored) {
        invokeLogFuseHold($this->manager, decisionLimitedBy('fuse'));
    }
});

test('rate limits per queue, so one outage does not mask another', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->twice();

    invokeLogFuseHold($this->manager, decisionLimitedBy('fuse', 'payments'));
    invokeLogFuseHold($this->manager, decisionLimitedBy('fuse', 'invoices'));
});

test('honours the configured alert cooldown', function (): void {
    // Proves the manager uses the container-resolved limiter rather than the
    // constructor default, which would ignore the operator's setting.
    config()->set('queue-autoscale.alerting.cooldown_seconds', 900);
    app()->forgetInstance(AlertRateLimiter::class);
    app()->forgetInstance(AutoscaleManager::class);

    $manager = app(AutoscaleManager::class);

    $limiter = (new ReflectionProperty(AutoscaleManager::class, 'alerts'))->getValue($manager);

    expect($limiter->cooldownSeconds)->toBe(900);
});
