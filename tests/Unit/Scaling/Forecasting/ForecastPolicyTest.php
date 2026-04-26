<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\AggressiveForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\DisabledForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\HintForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

test('disabled policy sets min R² above 1 so forecast is never trusted', function (): void {
    $policy = new DisabledForecastPolicy;

    expect($policy->minRSquared())->toBeGreaterThan(1.0)
        ->and($policy->forecastWeight())->toBe(0.0);
});

test('hint policy requires strong fit and uses small forecast weight', function (): void {
    $policy = new HintForecastPolicy;

    expect($policy->minRSquared())->toBe(0.8)
        ->and($policy->forecastWeight())->toBe(0.3);
});

test('moderate policy is balanced', function (): void {
    $policy = new ModerateForecastPolicy;

    expect($policy->minRSquared())->toBe(0.6)
        ->and($policy->forecastWeight())->toBe(0.5);
});

test('aggressive policy trusts forecast with noise', function (): void {
    $policy = new AggressiveForecastPolicy;

    expect($policy->minRSquared())->toBe(0.4)
        ->and($policy->forecastWeight())->toBe(0.8);
});
