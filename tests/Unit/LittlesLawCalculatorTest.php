<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;

it('calculates workers using Littles Law', function () {
    $calculator = new LittlesLawCalculator;

    // L = λW: 10 jobs/sec × 2 seconds/job = 20 workers
    expect($calculator->calculate(10.0, 2.0))->toBe(20.0);
});

it('returns zero for zero arrival rate', function () {
    $calculator = new LittlesLawCalculator;

    expect($calculator->calculate(0.0, 2.0))->toBe(0.0);
});

it('returns zero for zero processing time', function () {
    $calculator = new LittlesLawCalculator;

    expect($calculator->calculate(10.0, 0.0))->toBe(0.0);
});

it('handles fractional results', function () {
    $calculator = new LittlesLawCalculator;

    // 0.5 jobs/sec × 3 seconds = 1.5 workers
    expect($calculator->calculate(0.5, 3.0))->toBe(1.5);
});

it('returns zero for negative values', function () {
    $calculator = new LittlesLawCalculator;

    expect($calculator->calculate(-5.0, 2.0))->toBe(0.0);
    expect($calculator->calculate(5.0, -2.0))->toBe(0.0);
});
