<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;

/**
 * The clock is injectable so simulations can advance time in their own steps.
 * Adding it introduced a constructor to a class that had never had one, and
 * that is a trap: a consumer subclass declaring its own constructor cannot be
 * calling parent::__construct(), because doing so was a fatal error in the
 * version they wrote it against. Depending on the constructor having run would
 * therefore break every such subclass on upgrade, from inside estimate(), with
 * "value of type null is not callable".
 *
 * This package ships non-final classes precisely so consumers extend them, so
 * the clock is read through a helper that falls back on its own.
 */
test('a subclass that never runs the parent constructor still works', function (): void {
    $estimator = new class extends ArrivalRateEstimator
    {
        public function __construct()
        {
            // Exactly what a consumer wrote against the previous release.
        }
    };

    $estimate = $estimator->estimate('redis:default', 100, 5.0);

    expect($estimate['rate'])->toBeFloat()
        ->and($estimate['source'])->toBeString();
});

test('an injected clock is used when one is given', function (): void {
    $estimator = new ArrivalRateEstimator(static fn (): float => 1_000_000.0);

    $estimator->estimate('redis:default', 10, 1.0);

    // Two readings at the same simulated instant: the interval is zero, which
    // the estimator must recognise rather than divide by.
    expect($estimator->estimate('redis:default', 20, 1.0)['source'])->toBe('interval_too_short');
});

test('without a clock it reads the process clock', function (): void {
    $estimator = new ArrivalRateEstimator;

    expect($estimator->estimate('redis:default', 10, 1.0)['source'])->toBe('no_history');
});
