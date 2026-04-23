<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Pickup\NullPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\NullSpawnLatencyTracker;

test('uses null pickup store by default in single-host mode', function (): void {
    config([
        'queue-autoscale.cluster.enabled' => false,
        'queue-autoscale.pickup_time.store' => 'auto',
    ]);

    app()->forgetInstance(PickupTimeStoreContract::class);

    expect(app(PickupTimeStoreContract::class))->toBeInstanceOf(NullPickupTimeStore::class);
});

test('binds RedisPickupTimeStore when explicitly requested', function (): void {
    config([
        'queue-autoscale.pickup_time.store' => 'redis',
        'queue-autoscale.pickup_time.max_samples_per_queue' => 42,
    ]);

    app()->forgetInstance(PickupTimeStoreContract::class);

    $store = app(PickupTimeStoreContract::class);

    expect($store)->toBeInstanceOf(RedisPickupTimeStore::class);

    $reflection = new ReflectionClass($store);
    $prop = $reflection->getProperty('maxSamplesPerQueue');
    $prop->setAccessible(true);
    expect($prop->getValue($store))->toBe(42);
});

test('rejects pickup_time.store class that does not implement contract', function (): void {
    config(['queue-autoscale.pickup_time.store' => stdClass::class]);

    app()->forgetInstance(PickupTimeStoreContract::class);

    expect(fn () => app(PickupTimeStoreContract::class))
        ->toThrow(RuntimeException::class);
});

test('uses redis pickup store automatically in cluster mode', function (): void {
    config([
        'queue-autoscale.cluster.enabled' => true,
        'queue-autoscale.pickup_time.store' => 'auto',
    ]);

    app()->forgetInstance(PickupTimeStoreContract::class);

    expect(app(PickupTimeStoreContract::class))->toBeInstanceOf(RedisPickupTimeStore::class);
});

test('uses null spawn latency tracker by default in single-host mode', function (): void {
    config([
        'queue-autoscale.cluster.enabled' => false,
        'queue-autoscale.spawn_latency.tracker' => 'auto',
    ]);

    app()->forgetInstance(SpawnLatencyTrackerContract::class);

    expect(app(SpawnLatencyTrackerContract::class))->toBeInstanceOf(NullSpawnLatencyTracker::class);
});

test('uses redis spawn latency tracker automatically in cluster mode', function (): void {
    config([
        'queue-autoscale.cluster.enabled' => true,
        'queue-autoscale.spawn_latency.tracker' => 'auto',
    ]);

    app()->forgetInstance(SpawnLatencyTrackerContract::class);

    expect(app(SpawnLatencyTrackerContract::class))->toBeInstanceOf(EmaSpawnLatencyTracker::class);
});

test('rejects spawn latency tracker class that does not implement contract', function (): void {
    config(['queue-autoscale.spawn_latency.tracker' => stdClass::class]);

    app()->forgetInstance(SpawnLatencyTrackerContract::class);

    expect(fn () => app(SpawnLatencyTrackerContract::class))
        ->toThrow(RuntimeException::class);
});

test('binds ForecasterContract to LinearRegressionForecaster', function (): void {
    $forecaster = app(ForecasterContract::class);
    expect($forecaster)->toBeInstanceOf(LinearRegressionForecaster::class);
});

test('binds ForecastPolicyContract to ModerateForecastPolicy', function (): void {
    $policy = app(ForecastPolicyContract::class);
    expect($policy)->toBeInstanceOf(ModerateForecastPolicy::class);
});
