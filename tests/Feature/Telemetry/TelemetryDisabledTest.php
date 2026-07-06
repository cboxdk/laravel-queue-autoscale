<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Feature\Telemetry;

use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Telemetry\TelemetryEventSubscriber;
use Cbox\LaravelQueueAutoscale\Tests\TestCase;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Support\Facades\Event;

final class TelemetryDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(TelemetryManager::class)) {
            $this->markTestSkipped('requires cboxdk/laravel-telemetry');
        }

        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [TelemetryServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('queue-autoscale.telemetry.enabled', false);
    }

    public function test_it_does_not_subscribe_when_disabled(): void
    {
        $this->assertFalse(Event::hasListeners(ScalingDecisionMade::class));
    }

    public function test_it_does_not_bind_the_event_subscriber_when_disabled(): void
    {
        $this->assertFalse($this->app->bound(TelemetryEventSubscriber::class));
    }
}
