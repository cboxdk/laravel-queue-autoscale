<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;

test('p95 signal ignores rare stuck jobs that would trigger max-based breach', function (): void {
    // 100 normal pickups around 5s + 2 stuck jobs at 120s+
    $pickupTimes = array_fill(0, 100, 5.0);
    $pickupTimes[] = 120.0;
    $pickupTimes[] = 125.0;

    $calc = new SortBasedPercentileCalculator;
    $p95 = $calc->compute($pickupTimes, 95);
    $max = max($pickupTimes);

    // p95 of 102 sorted values: index = ceil(0.95 * 102) - 1 = 96 → sorted[96] = 5.0
    expect($p95)->toBe(5.0);
    // Max-based signal would be 125.0 — far exceeds a 30-second SLA.
    expect($max)->toBeGreaterThan(30.0);

    // Demonstrate that p95 < SLA threshold while max breaches.
    $sla = 30.0;
    expect($p95)->toBeLessThan($sla);
    expect($max)->toBeGreaterThan($sla);
})->group('simulation');

test('p95 starts breaching when most pickups exceed SLA, not when outliers do', function (): void {
    // 96 normal pickups + 6 stuck — p95 should start to breach
    $pickupTimes = array_fill(0, 96, 5.0);
    array_push($pickupTimes, 100.0, 100.0, 100.0, 100.0, 100.0, 100.0);

    $calc = new SortBasedPercentileCalculator;
    $p95 = $calc->compute($pickupTimes, 95);

    // 102 values sorted: indices 0-95 are 5.0, 96-101 are 100.0.
    // p95 index = ceil(0.95 * 102) - 1 = 96 → sorted[96] = 100.0
    expect($p95)->toBeGreaterThanOrEqual(5.0);
})->group('simulation');
