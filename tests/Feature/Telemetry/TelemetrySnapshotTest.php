<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\LaravelQueueAutoscale\Telemetry\QueueAutoscaleTelemetrySnapshot;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('binds a default snapshot implementation', function () {
    expect(app()->bound(ProvidesTelemetrySnapshot::class))->toBeTrue()
        ->and(app(ProvidesTelemetrySnapshot::class))->toBeInstanceOf(QueueAutoscaleTelemetrySnapshot::class);
});

it('returns an empty cluster snapshot when cluster mode is disabled', function () {
    config()->set('queue-autoscale.cluster.enabled', false);

    expect(app(ProvidesTelemetrySnapshot::class)->snapshot())->toBe(['cluster' => []]);
});

it('returns the cluster summary when cluster mode is enabled', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.telemetry.cache_ttl', 0);

    $store = Mockery::mock(ClusterStore::class);
    $store->shouldReceive('summary')->once()->andReturn(['manager_count' => 2]);
    app()->instance(ClusterStore::class, $store);

    expect(app(ProvidesTelemetrySnapshot::class)->snapshot())->toBe(['cluster' => ['manager_count' => 2]]);
});

it('treats an empty summary as an empty cluster snapshot', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.telemetry.cache_ttl', 0);

    $store = Mockery::mock(ClusterStore::class);
    $store->shouldReceive('summary')->once()->andReturn([]);
    app()->instance(ClusterStore::class, $store);

    expect(app(ProvidesTelemetrySnapshot::class)->snapshot())->toBe(['cluster' => []]);
});

it('caches the snapshot for cache_ttl seconds', function () {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.telemetry.cache_ttl', 10);

    $store = Mockery::mock(ClusterStore::class);
    $store->shouldReceive('summary')->once()->andReturn(['manager_count' => 1]);
    app()->instance(ClusterStore::class, $store);

    $snapshot = app(ProvidesTelemetrySnapshot::class);
    $snapshot->snapshot();
    $snapshot->snapshot();
});
