<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\TrendPredictor;

it('returns current rate when trend is null', function () {
    $predictor = new TrendPredictor;

    expect($predictor->predictArrivalRate(10.0, null, 60))->toBe(10.0);
});

it('returns current rate when trend is stable', function () {
    $predictor = new TrendPredictor;

    $trend = (object) ['direction' => 'stable'];

    expect($predictor->predictArrivalRate(10.0, $trend, 60))->toBe(10.0);
});

it('increases rate when trend is up with forecast', function () {
    $predictor = new TrendPredictor;

    $trend = (object) [
        'direction' => 'up',
        'forecast' => 15.0,
    ];

    expect($predictor->predictArrivalRate(10.0, $trend, 60))->toBe(15.0);
});

it('uses 20% increase when trend is up without forecast', function () {
    $predictor = new TrendPredictor;

    $trend = (object) ['direction' => 'up'];

    expect($predictor->predictArrivalRate(10.0, $trend, 60))->toBe(12.0);
});

it('decreases rate by 20% when trend is down', function () {
    $predictor = new TrendPredictor;

    $trend = (object) ['direction' => 'down'];

    expect($predictor->predictArrivalRate(10.0, $trend, 60))->toBe(8.0);
});
