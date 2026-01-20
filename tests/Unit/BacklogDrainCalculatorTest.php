<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;

it('returns zero for empty backlog', function () {
    $calculator = new BacklogDrainCalculator;

    expect($calculator->calculateRequiredWorkers(
        backlog: 0,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.8,
    ))->toBe(0.0);
});

it('uses fallback logic when oldest job age is unavailable but backlog exists', function () {
    $calculator = new BacklogDrainCalculator;

    // Fallback scenario: oldest_job_age = 0 (unavailable) but backlog = 50
    // SLA target: 30s, avg job time: 1s
    // Conservative estimate: process within full SLA window
    // Jobs per worker: max(30s / 1s, 1.0) = 30 jobs
    // Workers needed: 50 / 30 = 1.666...
    expect($calculator->calculateRequiredWorkers(
        backlog: 50,
        oldestJobAge: 0, // Age unavailable (common with some queue drivers)
        slaTarget: 30,
        avgJobTime: 1.0,
        breachThreshold: 0.8,
    ))->toBe(50.0 / 30.0);
});

it('returns zero for zero average job time', function () {
    $calculator = new BacklogDrainCalculator;

    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: 0.0,
        breachThreshold: 0.8,
    ))->toBe(0.0);
});

it('returns zero for negative average job time', function () {
    $calculator = new BacklogDrainCalculator;

    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 25,
        slaTarget: 30,
        avgJobTime: -1.0,
        breachThreshold: 0.8,
    ))->toBe(0.0);
});

it('returns zero when oldest job is below action threshold', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, breach threshold: 0.5 = 15s action threshold
    // Oldest job: 10s (below 15s threshold)
    expect($calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 10,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    ))->toBe(0.0);
});

it('calculates workers using continuous multiplier at threshold', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, breach threshold: 0.5 = 15s action threshold
    // Oldest job: 15s (exactly at 50% of SLA)
    // SLA progress: 15/30 = 0.5 (50%)
    // Multiplier: 1 + 8*(0.5-0.5)² = 1.0
    // Base workers: 100 / (15s / 2s) = 13.33
    // Final: 13.33 * 1.0 = 13.33
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 15,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    $expected = 100 / 7.5;
    expect(abs($result - $expected))->toBeLessThan(0.01);
});

it('applies continuous scaling at 60% SLA progress', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 100s, breach threshold: 0.5 = 50s action threshold
    // Oldest job: 60s (60% of SLA)
    // SLA progress: 60/100 = 0.6 (60%)
    // Multiplier: 1 + 8*(0.6-0.5)² = 1 + 8*0.01 = 1.08
    // Time until breach: 100 - 60 = 40s
    // Base workers: 80 / (40s / 5s) = 10
    // Final: 10 * 1.08 = 10.8
    $result = $calculator->calculateRequiredWorkers(
        backlog: 80,
        oldestJobAge: 60,
        slaTarget: 100,
        avgJobTime: 5.0,
        breachThreshold: 0.5,
    );

    expect(abs($result - 10.8))->toBeLessThan(0.1);
});

it('applies continuous scaling at 80% SLA progress', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 100s, breach threshold: 0.5
    // Oldest job: 80s (80% of SLA)
    // SLA progress: 80/100 = 0.8 (80%)
    // Multiplier: 1 + 8*(0.8-0.5)² = 1 + 8*0.09 = 1.72
    // Time until breach: 100 - 80 = 20s
    // Base workers: 100 / (20s / 2s) = 10
    // Final: 10 * 1.72 = 17.2
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 80,
        slaTarget: 100,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    expect(abs($result - 17.2))->toBeLessThan(0.1);
});

it('applies continuous scaling at 90% SLA progress', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 100s, breach threshold: 0.5
    // Oldest job: 90s (90% of SLA)
    // SLA progress: 90/100 = 0.9 (90%)
    // Multiplier: 1 + 8*(0.9-0.5)² = 1 + 8*0.16 = 2.28
    // Time until breach: 100 - 90 = 10s
    // Base workers: 100 / (10s / 2s) = 20
    // Final: 20 * 2.28 = 45.6
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 90,
        slaTarget: 100,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    expect(abs($result - 45.6))->toBeLessThan(0.1);
});

it('applies continuous scaling at 100% SLA (exact breach)', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 30s (100% of SLA - exactly at breach)
    // SLA progress: 30/30 = 1.0 (100%)
    // Multiplier: 1 + 8*(1.0-0.5)² = 1 + 8*0.25 = 3.0
    // Time until breach: 0s (already breached)
    // Base workers: 100 / max(2.0, 0.1) = 50
    // Final: 50 * 3.0 = 150.0
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 30,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    expect($result)->toBe(150.0);
});

it('scales aggressively when SLA is already breached', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 35s (breached by 5s - 116.6% of SLA)
    // SLA progress: min(35/30, 1.5) = 1.166 (capped at 150%)
    // Multiplier: 1 + 8*(1.166-0.5)² = 1 + 8*0.444 = 4.55
    // Time until breach: -5s (negative, so base uses max(avgJobTime, 0.1))
    // Base workers: 100 / max(2.0, 0.1) = 50
    // Final: 50 * 4.55 = 227.67
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 35,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    $slaProgress = min(35.0 / 30.0, 1.5);
    $expectedMultiplier = min(1.0 + 8.0 * pow($slaProgress - 0.5, 2), 5.0);
    $expected = 50 * $expectedMultiplier;
    expect(abs($result - $expected))->toBeLessThan(1);
});

it('caps multiplier at 5.0 for extreme breaches', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 45s (150% of SLA - extreme breach)
    // SLA progress: min(45/30, 1.5) = 1.5 (capped)
    // Multiplier would be: 1 + 8*(1.5-0.5)² = 1 + 8*1.0 = 9.0, but capped at 5.0
    // Base workers: 100 / max(2.0, 0.1) = 50
    // Final: 50 * 5.0 = 250.0
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 45,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    expect($result)->toBe(250.0);
});

it('provides smooth transition between levels (no discrete jumps)', function () {
    $calculator = new BacklogDrainCalculator;

    // Test that the multiplier changes smoothly without sudden jumps
    // Progress from 60% to 70% should show gradual increase
    $result60 = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 60,
        slaTarget: 100,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    $result65 = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 65,
        slaTarget: 100,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    $result70 = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 70,
        slaTarget: 100,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    // Each step should show gradual increase
    expect($result65)->toBeGreaterThan($result60);
    expect($result70)->toBeGreaterThan($result65);

    // The increase from 60->65 should be similar to 65->70 (smooth curve)
    $delta1 = $result65 - $result60;
    $delta2 = $result70 - $result65;

    // With quadratic, later increases are larger, so delta2 > delta1
    expect($delta2)->toBeGreaterThan($delta1 * 0.5);
});

it('handles fast jobs efficiently', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 60s, oldest job: 40s (66.6% of SLA)
    // SLA progress: 40/60 = 0.666
    // Multiplier: 1 + 8*(0.666-0.5)² = 1 + 8*0.0276 = 1.22
    // Base workers: 50 / (20s / 0.5s) = 1.25
    // Final: 1.25 * 1.22 = 1.525
    $result = $calculator->calculateRequiredWorkers(
        backlog: 50,
        oldestJobAge: 40,
        slaTarget: 60,
        avgJobTime: 0.5,
        breachThreshold: 0.5,
    );

    expect($result)->toBeGreaterThan(0)
        ->and($result)->toBeLessThan(10);
});

it('handles slow jobs requiring more workers', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 24s (80% of SLA)
    // SLA progress: 24/30 = 0.8
    // Multiplier: 1 + 8*(0.8-0.5)² = 1.72
    // Time until breach: 6s
    // Base workers: 20 / max(6s / 10s, 1.0) = 20
    // Final: 20 * 1.72 = 34.4
    $result = $calculator->calculateRequiredWorkers(
        backlog: 20,
        oldestJobAge: 24,
        slaTarget: 30,
        avgJobTime: 10.0,
        breachThreshold: 0.5,
    );

    expect(abs($result - 34.4))->toBeLessThan(0.5);
});

it('handles small backlog efficiently', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 24s (80% of SLA)
    // SLA progress: 24/30 = 0.8
    // Multiplier: 1 + 8*(0.8-0.5)² = 1.72
    // Time until breach: 6s
    // Base workers: 5 / (6s / 2s) = 1.666
    // Final: 1.666 * 1.72 = 2.87
    $result = $calculator->calculateRequiredWorkers(
        backlog: 5,
        oldestJobAge: 24,
        slaTarget: 30,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    expect(abs($result - 2.87))->toBeLessThan(0.2);
});

it('protects against division by very small avgJobTime in breach scenario', function () {
    $calculator = new BacklogDrainCalculator;

    // SLA target: 30s, oldest job: 35s (116.6% of SLA - already breached)
    // SLA progress: 35/30 = 1.166
    // Multiplier: min(1 + 8*(1.166-0.5)², 5.0)
    // Base workers: 100 / max(0.01, 0.1) = 1000 (protection against tiny avgJobTime)
    // Final: 1000 * multiplier
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 35,
        slaTarget: 30,
        avgJobTime: 0.01,
        breachThreshold: 0.5,
    );

    $slaProgress = min(35.0 / 30.0, 1.5);
    $expectedMultiplier = min(1.0 + 8.0 * pow($slaProgress - 0.5, 2), 5.0);
    $expected = 1000 * $expectedMultiplier;
    expect(abs($result - $expected))->toBeLessThan(50);
});

it('has multiplier of 1.0 exactly at 50% SLA progress', function () {
    $calculator = new BacklogDrainCalculator;

    // This verifies the formula starts at 1.0 at the 50% threshold
    // Multiplier: 1 + 8*(0.5-0.5)² = 1.0
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 50,
        slaTarget: 100,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    // Time until breach: 50s
    // Base workers: 100 / (50s / 2s) = 4
    // Final: 4 * 1.0 = 4.0
    expect($result)->toBe(4.0);
});

it('has multiplier of 3.0 exactly at 100% SLA progress', function () {
    $calculator = new BacklogDrainCalculator;

    // Multiplier: 1 + 8*(1.0-0.5)² = 1 + 8*0.25 = 3.0
    // At 100% progress, time until breach = 0
    // Base workers: 100 / max(2.0, 0.1) = 50
    // Final: 50 * 3.0 = 150.0
    $result = $calculator->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 100,
        slaTarget: 100,
        avgJobTime: 2.0,
        breachThreshold: 0.5,
    );

    expect($result)->toBe(150.0);
});
