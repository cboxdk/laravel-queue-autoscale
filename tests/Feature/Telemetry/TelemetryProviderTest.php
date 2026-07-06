<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\LaravelQueueAutoscale\Telemetry\QueueAutoscaleTelemetryProvider;
use Cbox\Telemetry\Facades\Telemetry;

function bindClusterSnapshot(array $cluster): void
{
    $snapshot = Mockery::mock(ProvidesTelemetrySnapshot::class);
    $snapshot->shouldReceive('snapshot')->andReturn(['cluster' => $cluster]);
    app()->instance(ProvidesTelemetrySnapshot::class, $snapshot);
}

function exampleClusterSummary(): array
{
    return [
        'manager_count' => 2,
        'total_workers' => 14,
        'required_workers' => 18,
        'total_worker_capacity' => 40,
        'utilization_percent' => 35.0,
        'scale_signal' => ['recommended_hosts' => 3],
        'managers' => [
            ['host' => 'web-01', 'total_workers' => 8, 'max_workers' => 20],
            ['host' => 'web-02', 'total_workers' => 6, 'max_workers' => 20],
        ],
    ];
}

beforeEach(function () {
    config()->set('queue-autoscale.telemetry.gauges.cluster', true);
    $this->fake = Telemetry::fake();
});

it('publishes cluster summary gauges', function () {
    bindClusterSnapshot(exampleClusterSummary());
    $this->fake->provider(new QueueAutoscaleTelemetryProvider(app()));

    expect($this->fake->gaugeValue('queue_autoscale.cluster.managers', []))->toBe(2.0)
        ->and($this->fake->gaugeValue('queue_autoscale.cluster.workers', []))->toBe(14.0)
        ->and($this->fake->gaugeValue('queue_autoscale.cluster.required_workers', []))->toBe(18.0)
        ->and($this->fake->gaugeValue('queue_autoscale.cluster.capacity', []))->toBe(40.0)
        ->and($this->fake->gaugeValue('queue_autoscale.cluster.utilization', []))->toBe(35.0)
        ->and($this->fake->gaugeValue('queue_autoscale.cluster.recommended_hosts', []))->toBe(3.0);
});

it('publishes per-host gauges labeled by host', function () {
    bindClusterSnapshot(exampleClusterSummary());
    $this->fake->provider(new QueueAutoscaleTelemetryProvider(app()));

    expect($this->fake->gaugeValue('queue_autoscale.cluster.host.workers', ['host' => 'web-01']))->toBe(8.0)
        ->and($this->fake->gaugeValue('queue_autoscale.cluster.host.workers', ['host' => 'web-02']))->toBe(6.0)
        ->and($this->fake->gaugeValue('queue_autoscale.cluster.host.capacity', ['host' => 'web-01']))->toBe(20.0);
});

it('publishes no samples for an empty cluster snapshot', function () {
    bindClusterSnapshot([]);
    $this->fake->provider(new QueueAutoscaleTelemetryProvider(app()));

    $family = collect($this->fake->collect())->first(fn ($f) => $f->name() === 'queue_autoscale.cluster.managers');

    expect($family)->not->toBeNull()
        ->and($family->samples)->toBeEmpty();
});

it('registers no cluster gauges when the gauge group is disabled', function () {
    config()->set('queue-autoscale.telemetry.gauges.cluster', false);
    bindClusterSnapshot(exampleClusterSummary());
    $this->fake->provider(new QueueAutoscaleTelemetryProvider(app()));

    $names = array_map(fn ($family) => $family->name(), $this->fake->collect());

    expect($names)->not->toContain('queue_autoscale.cluster.managers');
});

it('coerces non-numeric summary fields to zero instead of throwing', function () {
    bindClusterSnapshot(['manager_count' => 'garbage', 'managers' => 'not-a-list']);
    $this->fake->provider(new QueueAutoscaleTelemetryProvider(app()));

    expect($this->fake->gaugeValue('queue_autoscale.cluster.managers', []))->toBe(0.0);

    $hostFamily = collect($this->fake->collect())->first(fn ($f) => $f->name() === 'queue_autoscale.cluster.host.workers');

    expect($hostFamily)->not->toBeNull()
        ->and($hostFamily->samples)->toBeEmpty();
});
