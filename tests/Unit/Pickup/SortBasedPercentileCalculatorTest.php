<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;

test('returns null when fewer than 20 samples', function (): void {
    $calc = new SortBasedPercentileCalculator;

    expect($calc->compute([1.0, 2.0, 3.0], 95))->toBeNull();
});

test('computes p95 correctly on 100-value range', function (): void {
    $calc = new SortBasedPercentileCalculator;
    $values = [];
    for ($i = 1; $i <= 100; $i++) {
        $values[] = (float) $i;
    }

    $p95 = $calc->compute($values, 95);

    expect($p95)->toBe(95.0);
});

test('computes p50 as median', function (): void {
    $calc = new SortBasedPercentileCalculator;
    $values = [];
    for ($i = 1; $i <= 100; $i++) {
        $values[] = (float) $i;
    }

    expect($calc->compute($values, 50))->toBe(50.0);
});

test('computes p99 near top of range', function (): void {
    $calc = new SortBasedPercentileCalculator;
    $values = [];
    for ($i = 1; $i <= 100; $i++) {
        $values[] = (float) $i;
    }

    expect($calc->compute($values, 99))->toBe(99.0);
});

test('unsorted input is handled correctly', function (): void {
    $calc = new SortBasedPercentileCalculator;
    $values = [];
    for ($i = 100; $i >= 1; $i--) {
        $values[] = (float) $i;
    }

    expect($calc->compute($values, 95))->toBe(95.0);
});

test('all-same values return that value', function (): void {
    $calc = new SortBasedPercentileCalculator;
    $values = array_fill(0, 30, 7.5);

    expect($calc->compute($values, 95))->toBe(7.5);
});
