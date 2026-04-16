<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

test('falls back to observed rate when forecast has low R²', function (): void {
    $forecaster = new class implements ForecasterContract
    {
        public function forecast(array $history, int $horizonSeconds): ForecastResult
        {
            return new ForecastResult(100.0, 0.1, 5.0, 10, true);
        }
    };

    $estimator = new ArrivalRateEstimator;
    $estimator->setForecaster($forecaster, new ModerateForecastPolicy, horizonSeconds: 60);

    $estimator->estimate('redis:default', 10, 2.0);
    usleep(1_500_000);
    $result = $estimator->estimate('redis:default', 12, 2.0);

    expect($result['rate'])->toBeLessThan(50.0);
});

test('blends forecast into observed rate when R² passes threshold', function (): void {
    $forecaster = new class implements ForecasterContract
    {
        public function forecast(array $history, int $horizonSeconds): ForecastResult
        {
            return new ForecastResult(100.0, 0.9, 5.0, 10, true);
        }
    };

    $estimator = new ArrivalRateEstimator;
    $estimator->setForecaster($forecaster, new ModerateForecastPolicy, horizonSeconds: 60);

    $estimator->estimate('redis:default', 10, 2.0);
    usleep(1_500_000);
    $result = $estimator->estimate('redis:default', 12, 2.0);

    expect($result['rate'])->toBeGreaterThan(10.0)->toBeLessThan(100.0);
});

test('hasForecaster reflects whether forecaster is configured', function (): void {
    $estimator = new ArrivalRateEstimator;

    expect($estimator->hasForecaster())->toBeFalse();

    $forecaster = new class implements ForecasterContract
    {
        public function forecast(array $history, int $horizonSeconds): ForecastResult
        {
            return ForecastResult::insufficientData();
        }
    };

    $estimator->setForecaster($forecaster, new ModerateForecastPolicy, 60);
    expect($estimator->hasForecaster())->toBeTrue();
});
