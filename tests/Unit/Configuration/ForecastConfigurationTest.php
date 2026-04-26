<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

test('constructs with valid values', function (): void {
    $cfg = new ForecastConfiguration(
        forecasterClass: LinearRegressionForecaster::class,
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 60,
        historySeconds: 300,
    );

    expect($cfg->forecasterClass)->toBe(LinearRegressionForecaster::class);
});

test('rejects non-existent forecaster class', function (): void {
    expect(fn () => new ForecastConfiguration(
        forecasterClass: 'Nope\\NotAClass',
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 60,
        historySeconds: 300,
    ))->toThrow(InvalidConfigurationException::class);
});

test('rejects horizon <= 0', function (): void {
    expect(fn () => new ForecastConfiguration(
        forecasterClass: LinearRegressionForecaster::class,
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 0,
        historySeconds: 300,
    ))->toThrow(InvalidConfigurationException::class);
});

test('rejects history shorter than horizon', function (): void {
    expect(fn () => new ForecastConfiguration(
        forecasterClass: LinearRegressionForecaster::class,
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 120,
        historySeconds: 60,
    ))->toThrow(InvalidConfigurationException::class);
});
