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

it('includes worker_cpu_core_estimate in cpu_details', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    expect($result->details['cpu_details'])
        ->toHaveKey('worker_cpu_core_estimate')
        ->and($result->details['cpu_details']['worker_cpu_core_estimate'])->toBeFloat();
});

it('allows more workers with lower worker_cpu_core_estimate', function () {
    // Eliminate environment dependencies: no reserve, allow full CPU.
    config()->set('queue-autoscale.limits.max_cpu_percent', 100);
    config()->set('queue-autoscale.limits.reserve_cpu_cores', 0);
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 1.0);
    $calculator = new CapacityCalculator;
    $highEstimate = $calculator->calculateMaxWorkers();

    // Reuse cached metrics — only the config value changes.
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    $lowEstimate = $calculator->calculateMaxWorkers();

    // On CI runners where system-metrics reports 0 cores (no cgroup limit),
    // both estimates yield 0 workers — verify estimate is applied instead.
    if ($highEstimate->maxWorkersByCpu > 0) {
        expect($lowEstimate->maxWorkersByCpu)->toBeGreaterThan($highEstimate->maxWorkersByCpu);
    } else {
        expect($lowEstimate->details['cpu_details']['worker_cpu_core_estimate'])->toBe(0.2)
            ->and($highEstimate->details['cpu_details']['worker_cpu_core_estimate'])->toBe(1.0);
    }
});

it('uses default worker_cpu_core_estimate of 0.2 when not configured', function () {
    config()->offsetUnset('queue-autoscale.limits.worker_cpu_core_estimate');
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers();

    expect($result->details['cpu_details']['worker_cpu_core_estimate'])->toBe(0.2)
        ->and($result->details['cpu_details']['cpu_estimate_source'])->toBe('config');
});

it('uses measured CPU estimate when set, overriding config', function () {
    // Eliminate environment dependencies: no reserve, allow full CPU.
    config()->set('queue-autoscale.limits.max_cpu_percent', 100);
    config()->set('queue-autoscale.limits.reserve_cpu_cores', 0);
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 1.0);
    $calculator = new CapacityCalculator;

    $configResult = $calculator->calculateMaxWorkers();

    // Reuse cached metrics — only the estimate changes.
    $calculator->setMeasuredWorkerCpuCoreEstimate(0.1);
    $measuredResult = $calculator->calculateMaxWorkers();

    // Estimate is correctly applied regardless of available capacity.
    expect($measuredResult->details['cpu_details']['worker_cpu_core_estimate'])->toBe(0.1)
        ->and($measuredResult->details['cpu_details']['cpu_estimate_source'])->toBe('measured');

    // When system has detectable CPU cores, lower estimate yields more workers.
    if ($configResult->maxWorkersByCpu > 0) {
        expect($measuredResult->maxWorkersByCpu)->toBeGreaterThan($configResult->maxWorkersByCpu);
    }
});

it('falls back to config when measured estimate is cleared', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.5);
    $calculator = new CapacityCalculator;

    $calculator->setMeasuredWorkerCpuCoreEstimate(0.1);
    $calculator->invalidateCache();
    $measuredResult = $calculator->calculateMaxWorkers();

    $calculator->setMeasuredWorkerCpuCoreEstimate(null);
    $calculator->invalidateCache();
    $configResult = $calculator->calculateMaxWorkers();

    expect($measuredResult->details['cpu_details']['cpu_estimate_source'])->toBe('measured')
        ->and($configResult->details['cpu_details']['cpu_estimate_source'])->toBe('config')
        ->and($configResult->details['cpu_details']['worker_cpu_core_estimate'])->toBe(0.5);
});

it('caches system metrics across consecutive calls within TTL', function () {
    $calculator = new CapacityCalculator;

    // First call - measures system metrics (expensive, ~1s for CPU)
    $start = microtime(true);
    $result1 = $calculator->calculateMaxWorkers(5);
    $firstCallDuration = microtime(true) - $start;

    // Second call - should use cached metrics (fast)
    $start = microtime(true);
    $result2 = $calculator->calculateMaxWorkers(5);
    $secondCallDuration = microtime(true) - $start;

    // Second call should be significantly faster (cached, no 1s CPU measurement)
    expect($secondCallDuration)->toBeLessThan($firstCallDuration)
        // Results should be consistent (same cached metrics)
        ->and($result1->details['cpu_details']['current_cpu_percent'])
        ->toBe($result2->details['cpu_details']['current_cpu_percent'])
        ->and($result1->details['memory_details']['current_memory_percent'])
        ->toBe($result2->details['memory_details']['current_memory_percent']);
});

it('invalidates cache when explicitly requested', function () {
    $calculator = new CapacityCalculator;

    // First call caches metrics
    $calculator->calculateMaxWorkers();

    // Invalidate the cache
    $calculator->invalidateCache();

    // Next call should refresh metrics (will take ~1s for CPU measurement)
    $start = microtime(true);
    $result = $calculator->calculateMaxWorkers();
    $duration = microtime(true) - $start;

    expect($result)->toBeInstanceOf(CapacityCalculationResult::class)
        // Should have taken measurable time for fresh CPU measurement
        ->and($duration)->toBeGreaterThan(0.5);
});
