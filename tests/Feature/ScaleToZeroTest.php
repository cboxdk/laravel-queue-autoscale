<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([
        ScalingDecisionMade::class,
        WorkersScaled::class,
    ]);

    $this->engine = app(ScalingEngine::class);
});

test('idle queue with min 0 produces target 0', function (): void {
    $config = makeQueueConfig([
        'queue' => 'notifications',
        'minWorkers' => 0,
        'maxWorkers' => 10,
        'slaTarget' => 60,
    ]);

    $idleMetrics = createMetrics([
        'connection' => 'redis',
        'queue' => 'notifications',
        'throughput_per_minute' => 0.0,
        'active_workers' => 0,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $decision = $this->engine->evaluate($idleMetrics, $config, 0);

    expect($decision)->toBeInstanceOf(ScalingDecision::class)
        ->and($decision->targetWorkers)->toBe(0)
        ->and($decision->shouldHold())->toBeTrue();
});

test('queue with min 0 scales up when jobs arrive', function (): void {
    $config = makeQueueConfig([
        'queue' => 'notifications',
        'minWorkers' => 0,
        'maxWorkers' => 10,
        'slaTarget' => 60,
    ]);

    $activeMetrics = createMetrics([
        'connection' => 'redis',
        'queue' => 'notifications',
        'throughput_per_minute' => 120.0,
        'active_workers' => 0,
        'pending' => 50,
        'oldest_job_age' => 10,
    ]);

    $decision = $this->engine->evaluate($activeMetrics, $config, 0);

    expect($decision->targetWorkers)->toBeGreaterThan(0)
        ->and($decision->shouldScaleUp())->toBeTrue();
});

test('queue with min 0 scales back to 0 when idle again', function (): void {
    $config = makeQueueConfig([
        'queue' => 'notifications',
        'minWorkers' => 0,
        'maxWorkers' => 10,
        'slaTarget' => 60,
    ]);

    $idleMetrics = createMetrics([
        'connection' => 'redis',
        'queue' => 'notifications',
        'throughput_per_minute' => 0.0,
        'active_workers' => 0,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $decision = $this->engine->evaluate($idleMetrics, $config, 3);

    expect($decision->targetWorkers)->toBe(0)
        ->and($decision->shouldScaleDown())->toBeTrue()
        ->and($decision->workersToRemove())->toBe(3);
});
