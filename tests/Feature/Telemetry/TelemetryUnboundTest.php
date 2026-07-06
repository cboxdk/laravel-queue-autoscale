<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Feature\Telemetry;

use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class TelemetryUnboundTest extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Leave telemetry.enabled at its default (true), but do NOT register
        // TelemetryServiceProvider, so TelemetryManager is not bound in the container.
        // This tests the regression where the service provider's bound() guard prevents
        // crashes when handlers try to resolve TelemetryManager.
    }

    public function test_it_does_not_subscribe_when_unbound(): void
    {
        $this->assertFalse(Event::hasListeners(ScalingDecisionMade::class));
    }

    public function test_it_does_not_crash_when_dispatching_event_with_unbound_manager(): void
    {
        $decision = new ScalingDecision(
            connection: 'redis',
            queue: 'default',
            currentWorkers: 2,
            targetWorkers: 5,
            reason: 'backlog growing',
            predictedPickupTime: 12.5,
            slaTarget: 30,
        );

        // Dispatch a real ScalingDecisionMade event. If the service provider's
        // bound() guard is missing, this would throw BindingResolutionException.
        // With the guard in place, it completes without listeners or exceptions.
        event(new ScalingDecisionMade($decision));

        // If we get here, no exception was thrown.
        $this->assertTrue(true);
    }
}
