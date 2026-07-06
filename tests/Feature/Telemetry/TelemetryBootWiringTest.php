<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Feature\Telemetry;

use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\LaravelQueueAutoscale\Telemetry\TelemetryEventSubscriber;
use Cbox\LaravelQueueAutoscale\Tests\TestCase;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Illuminate\Support\Facades\Event;
use Mockery;

final class TelemetryBootWiringTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [TelemetryServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('telemetry.enabled', true);
        $app['config']->set('telemetry.store', 'array');
        $app['config']->set('queue-autoscale.telemetry.enabled', true);
    }

    public function test_it_subscribes_to_package_events_at_boot(): void
    {
        foreach ([ScalingDecisionMade::class, WorkersScaled::class, SlaBreached::class] as $event) {
            $this->assertTrue(Event::hasListeners($event), "No listener registered for {$event}");
        }
    }

    public function test_it_registers_the_telemetry_provider_with_the_manager(): void
    {
        $snapshot = Mockery::mock(ProvidesTelemetrySnapshot::class);
        $snapshot->shouldReceive('snapshot')->andReturn(['cluster' => ['manager_count' => 1]]);
        $this->app->instance(ProvidesTelemetrySnapshot::class, $snapshot);

        $families = $this->app->make(TelemetryManager::class)->collect();
        $names = array_map(fn ($family) => $family->name(), $families);

        $this->assertContains('queue_autoscale.cluster.managers', $names);
    }

    public function test_the_event_subscriber_is_a_singleton(): void
    {
        $this->assertSame(
            $this->app->make(TelemetryEventSubscriber::class),
            $this->app->make(TelemetryEventSubscriber::class),
        );
    }
}
