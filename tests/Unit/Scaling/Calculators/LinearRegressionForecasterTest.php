<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;

test('returns insufficient data when history has fewer than 5 samples', function (): void {
    $forecaster = new LinearRegressionForecaster;
    $history = [
        ['timestamp' => 1000.0, 'rate' => 1.0],
        ['timestamp' => 1001.0, 'rate' => 2.0],
    ];

    $result = $forecaster->forecast($history, 60);

    expect($result->hasSufficientData)->toBeFalse();
});

test('perfectly linear input produces R² = 1.0 and correct slope', function (): void {
    $forecaster = new LinearRegressionForecaster;
    $history = [];
    for ($i = 0; $i < 10; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => 10.0 + (float) $i];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->hasSufficientData)->toBeTrue()
        ->and($result->rSquared)->toBeGreaterThan(0.999)
        ->and($result->slope)->toBeGreaterThan(0.999)->toBeLessThan(1.001)
        ->and($result->projectedRate)->toBeGreaterThan(78.9)->toBeLessThan(79.1);
});

test('flat line returns slope near zero and R² of 1.0', function (): void {
    $forecaster = new LinearRegressionForecaster;
    $history = [];
    for ($i = 0; $i < 10; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => 5.0];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->hasSufficientData)->toBeTrue()
        ->and(abs($result->slope))->toBeLessThan(0.0001)
        ->and($result->rSquared)->toBe(1.0)
        ->and($result->projectedRate)->toBeGreaterThan(4.99)->toBeLessThan(5.01);
});

test('noisy data produces R² below 0.5', function (): void {
    $forecaster = new LinearRegressionForecaster;
    $rates = [5.0, 15.0, 3.0, 18.0, 2.0, 20.0, 4.0, 17.0];
    $history = [];
    foreach ($rates as $i => $rate) {
        $history[] = ['timestamp' => (float) $i, 'rate' => $rate];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->hasSufficientData)->toBeTrue()
        ->and($result->rSquared)->toBeLessThan(0.5);
});

test('negative slope allowed for declining arrival rate', function (): void {
    $forecaster = new LinearRegressionForecaster;
    $history = [];
    for ($i = 0; $i < 10; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => 100.0 - (float) $i];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->slope)->toBeLessThan(0.0)
        ->and($result->projectedRate)->toBeLessThan(100.0);
});

test('sample count reflects input size', function (): void {
    $forecaster = new LinearRegressionForecaster;
    $history = [];
    for ($i = 0; $i < 15; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => (float) $i];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->sampleCount)->toBe(15);
});
