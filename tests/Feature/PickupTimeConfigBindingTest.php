<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

test('binds RedisPickupTimeStore with configured max_samples_per_queue', function (): void {
    config(['queue-autoscale.pickup_time.max_samples_per_queue' => 42]);

    // Re-bind to pick up the new config value (singleton was already resolved in boot)
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

test('binds ForecasterContract to LinearRegressionForecaster', function (): void {
    $forecaster = app(ForecasterContract::class);
    expect($forecaster)->toBeInstanceOf(LinearRegressionForecaster::class);
});

test('binds ForecastPolicyContract to ModerateForecastPolicy', function (): void {
    $policy = app(ForecastPolicyContract::class);
    expect($policy)->toBeInstanceOf(ModerateForecastPolicy::class);
});
