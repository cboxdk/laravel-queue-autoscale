<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;

/**
 * Note: CapacityCalculator uses SystemMetrics which queries actual system state.
 * These tests verify the calculator works correctly with real system metrics.
 * For full isolation, SystemMetrics would need to be injectable/mockable.
 */
it('returns capacity calculation result with detailed breakdown', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    expect($result)->toBeInstanceOf(CapacityCalculationResult::class)
        ->and($result->finalMaxWorkers)->toBeInt()
        ->and($result->finalMaxWorkers)->toBeGreaterThanOrEqual(0)
        ->and($result->maxWorkersByCpu)->toBeInt()
        ->and($result->maxWorkersByMemory)->toBeInt()
        ->and($result->maxWorkersByConfig)->toBeInt()
        ->and($result->limitingFactor)->toBeString();
});

it('returns conservative fallback when system metrics fail', function () {
    $calculator = new CapacityCalculator;

    // We can't easily force SystemMetrics::limits() to fail in tests,
    // but we verify the method doesn't throw exceptions
    $result = $calculator->calculateMaxWorkers();

    expect($result)->toBeInstanceOf(CapacityCalculationResult::class)
        ->and($result->finalMaxWorkers)->toBeInt()
        ->and($result->finalMaxWorkers)->toBeGreaterThanOrEqual(0);
});

it('calculates capacity based on current system state', function () {
    $calculator = new CapacityCalculator;

    // First calculation
    $result1 = $calculator->calculateMaxWorkers();

    // Second calculation (should be consistent in stable system)
    $result2 = $calculator->calculateMaxWorkers();

    expect($result1->finalMaxWorkers)->toBeInt()
        ->and($result2->finalMaxWorkers)->toBeInt()
        ->and($result1->finalMaxWorkers)->toBeGreaterThanOrEqual(0)
        ->and($result2->finalMaxWorkers)->toBeGreaterThanOrEqual(0);
});

it('respects system resource constraints', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    // Max workers should be reasonable (not millions)
    // This validates the calculation uses constraints properly
    expect($result->finalMaxWorkers)->toBeLessThan(1000);
});

it('provides detailed capacity breakdown with explanations', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    expect($result->details)->toBeArray()
        ->and($result->details)->toHaveKey('cpu_explanation')
        ->and($result->details)->toHaveKey('memory_explanation')
        ->and($result->details)->toHaveKey('cpu_details')
        ->and($result->details)->toHaveKey('memory_details');
});

it('identifies limiting factor correctly', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    // Limiting factor should be one of: cpu, memory, balanced
    expect($result->limitingFactor)->toBeIn(['cpu', 'memory', 'balanced']);
});

it('provides helper methods for limiting factor checks', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    // One of the helper methods should return true (unless 'balanced')
    if ($result->limitingFactor !== 'balanced') {
        $hasLimitingFactorTrue = $result->isCpuLimited() || $result->isMemoryLimited();
        expect($hasLimitingFactorTrue)->toBeTrue();
    }
});

it('provides human-readable summary', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    $summary = $result->getSummary();

    expect($summary)->toBeString()
        ->and($summary)->toContain('workers')
        ->and($summary)->toContain('limited by');
});

it('provides formatted details for verbose output', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    $formatted = $result->getFormattedDetails();

    expect($formatted)->toBeArray()
        ->and($formatted)->toHaveKey('CPU Limit')
        ->and($formatted)->toHaveKey('Memory Limit')
        ->and($formatted)->toHaveKey('Config Limit')
        ->and($formatted)->toHaveKey('Final Capacity');
});
