<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\RecordingScalingPolicy;
use Illuminate\Support\Facades\Event;

/**
 * A mutation that suppressed every ScalingDecisionMade dispatch used to
 * survive the whole suite: two specs called Event::fake() naming it and then
 * never asserted, so the fake was decoration.
 *
 * It is the event consumers build dashboards and alerting on, and it is now
 * emitted from one place — so one spec covers every path that reaches it.
 */
test('acting on a decision announces it', function (): void {
    Event::fake([ScalingDecisionMade::class]);

    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'reconcileQueueTarget');

    $method->invoke($manager, QueueConfiguration::fromConfig('redis', 'exports'), 3);

    Event::assertDispatched(
        ScalingDecisionMade::class,
        fn (ScalingDecisionMade $e): bool => $e->decision->queue === 'exports'
            && $e->decision->targetWorkers === 3,
    );
});

test('the announced target is the one policies returned', function (): void {
    // The event must describe what was acted on, not what was proposed —
    // which is the whole reason it moved out of the leader.
    Event::fake([ScalingDecisionMade::class]);

    config()->set('queue-autoscale.policies', [RecordingScalingPolicy::class]);
    RecordingScalingPolicy::reset(capTo: 2);
    app()->forgetInstance(PolicyExecutor::class);
    app()->forgetInstance(AutoscaleManager::class);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'reconcileQueueTarget'))
        ->invoke($manager, QueueConfiguration::fromConfig('redis', 'exports'), 9);

    Event::assertDispatched(
        ScalingDecisionMade::class,
        fn (ScalingDecisionMade $e): bool => $e->decision->targetWorkers === 2,
    );
});
