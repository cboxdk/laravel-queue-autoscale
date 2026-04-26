<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;

test('insufficient data returns a result flagged as insufficient', function (): void {
    $result = ForecastResult::insufficientData();

    expect($result->hasSufficientData)->toBeFalse()
        ->and($result->projectedRate)->toBe(0.0)
        ->and($result->rSquared)->toBe(0.0)
        ->and($result->sampleCount)->toBe(0);
});

test('can construct a result with all values', function (): void {
    $result = new ForecastResult(
        projectedRate: 12.5,
        rSquared: 0.92,
        slope: 0.3,
        sampleCount: 60,
        hasSufficientData: true,
    );

    expect($result->projectedRate)->toBe(12.5)
        ->and($result->rSquared)->toBe(0.92)
        ->and($result->slope)->toBe(0.3)
        ->and($result->sampleCount)->toBe(60)
        ->and($result->hasSufficientData)->toBeTrue();
});
