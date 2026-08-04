<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;

/**
 * Note: CapacityCalculator uses SystemMetrics which queries actual system state.
 * These tests verify the calculator works correctly with real system metrics.
 * For full isolation, SystemMetrics would need to be injectable/mockable.
 */
it('returns capacity calculation result with detailed breakdown', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

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
    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    expect($result)->toBeInstanceOf(CapacityCalculationResult::class)
        ->and($result->finalMaxWorkers)->toBeInt()
        ->and($result->finalMaxWorkers)->toBeGreaterThanOrEqual(0);
});

it('calculates capacity based on current system state', function () {
    $calculator = new CapacityCalculator;

    // First calculation
    $result1 = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    // Second calculation (should be consistent in stable system)
    $result2 = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    expect($result1->finalMaxWorkers)->toBeInt()
        ->and($result2->finalMaxWorkers)->toBeInt()
        ->and($result1->finalMaxWorkers)->toBeGreaterThanOrEqual(0)
        ->and($result2->finalMaxWorkers)->toBeGreaterThanOrEqual(0);
});

it('respects system resource constraints', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    // Max workers should be reasonable (not millions)
    // This validates the calculation uses constraints properly
    expect($result->finalMaxWorkers)->toBeLessThan(1000);
});

it('provides detailed capacity breakdown with explanations', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    expect($result->details)->toBeArray()
        ->and($result->details)->toHaveKey('cpu_explanation')
        ->and($result->details)->toHaveKey('memory_explanation')
        ->and($result->details)->toHaveKey('cpu_details')
        ->and($result->details)->toHaveKey('memory_details');
});

it('identifies limiting factor correctly', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    // Limiting factor should be one of: cpu, memory, balanced
    expect($result->limitingFactor)->toBeIn(['cpu', 'memory', 'balanced']);
});

it('provides helper methods for limiting factor checks', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    // One of the helper methods should return true (unless 'balanced')
    if ($result->limitingFactor !== 'balanced') {
        $hasLimitingFactorTrue = $result->isCpuLimited() || $result->isMemoryLimited();
        expect($hasLimitingFactorTrue)->toBeTrue();
    }
});

it('provides human-readable summary', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    $summary = $result->getSummary();

    expect($summary)->toBeString()
        ->and($summary)->toContain('workers')
        ->and($summary)->toContain('limited by');
});

it('provides formatted details for verbose output', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    $formatted = $result->getFormattedDetails();

    expect($formatted)->toBeArray()
        ->and($formatted)->toHaveKey('CPU Limit')
        ->and($formatted)->toHaveKey('Memory Limit')
        ->and($formatted)->toHaveKey('Config Limit')
        ->and($formatted)->toHaveKey('Final Capacity');
});

it('includes worker_cpu_core_estimate in cpu_details', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    expect($result->details['cpu_details'])
        ->toHaveKey('worker_cpu_core_estimate')
        ->and($result->details['cpu_details']['worker_cpu_core_estimate'])->toBeFloat();
});

it('allows more workers with lower worker_cpu_core_estimate', function () {
    config()->set('queue-autoscale.limits.max_cpu_percent', 100);
    config()->set('queue-autoscale.limits.reserve_cpu_cores', 0);
    $calculator = new CapacityCalculator;

    $highEstimate = ResourceEstimate::fromConfig(1.0, 128.0);
    $highResult = $calculator->calculateMaxWorkers(0, $highEstimate);

    $lowEstimate = ResourceEstimate::fromConfig(0.2, 128.0);
    $lowResult = $calculator->calculateMaxWorkers(0, $lowEstimate);

    if ($highResult->maxWorkersByCpu > 0) {
        expect($lowResult->maxWorkersByCpu)->toBeGreaterThan($highResult->maxWorkersByCpu);
    } else {
        expect($lowEstimate->cpuCoresPerWorker)->toBe(0.2)
            ->and($highEstimate->cpuCoresPerWorker)->toBe(1.0);
    }
});

it('uses default worker_cpu_core_estimate of 0.2 when not configured', function () {
    config()->offsetUnset('queue-autoscale.limits.worker_cpu_core_estimate');
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    expect($result->details['cpu_details']['worker_cpu_core_estimate'])->toBe(0.2)
        ->and($result->details['cpu_details']['cpu_estimate_source'])->toBe('default');
});

it('uses measured CPU estimate when set, overriding config', function () {
    config()->set('queue-autoscale.limits.max_cpu_percent', 100);
    config()->set('queue-autoscale.limits.reserve_cpu_cores', 0);
    $calculator = new CapacityCalculator;

    $configEstimate = ResourceEstimate::fromConfig(1.0, 128.0);
    $configResult = $calculator->calculateMaxWorkers(0, $configEstimate);

    $measuredEstimate = ResourceEstimate::measured(0.1, 128.0, 500, 500);
    $measuredResult = $calculator->calculateMaxWorkers(0, $measuredEstimate);

    expect($measuredResult->details['cpu_details']['worker_cpu_core_estimate'])->toBe(0.1)
        ->and($measuredResult->details['cpu_details']['cpu_estimate_source'])->toBe('measured');

    if ($configResult->maxWorkersByCpu > 0) {
        expect($measuredResult->maxWorkersByCpu)->toBeGreaterThan($configResult->maxWorkersByCpu);
    }
});

it('uses different estimates producing different results', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.5);
    $calculator = new CapacityCalculator;

    $measuredEstimate = ResourceEstimate::measured(0.1, 128.0, 500, 500);
    $measuredResult = $calculator->calculateMaxWorkers(0, $measuredEstimate);

    $configEstimate = ResourceEstimate::globalDefault();
    $calculator->invalidateCache();
    $configResult = $calculator->calculateMaxWorkers(0, $configEstimate);

    expect($measuredResult->details['cpu_details']['cpu_estimate_source'])->toBe('measured')
        ->and($configResult->details['cpu_details']['cpu_estimate_source'])->toBe('default')
        ->and($configResult->details['cpu_details']['worker_cpu_core_estimate'])->toBe(0.5);
});

it('caches system metrics across consecutive calls within TTL', function () {
    $calculator = new CapacityCalculator;

    $result1 = $calculator->calculateMaxWorkers(5, ResourceEstimate::globalDefault());
    $result2 = $calculator->calculateMaxWorkers(5, ResourceEstimate::globalDefault());

    // Both calls read the same cached host measurement.
    expect($result1->details['cpu_details']['current_cpu_percent'])
        ->toBe($result2->details['cpu_details']['current_cpu_percent'])
        ->and($result1->details['memory_details']['current_memory_percent'])
        ->toBe($result2->details['memory_details']['current_memory_percent']);
});

it('invalidates cache when explicitly requested', function () {
    $calculator = new CapacityCalculator;

    $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());
    $calculator->invalidateCache();

    expect($calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault()))
        ->toBeInstanceOf(CapacityCalculationResult::class);
});

it('never blocks the evaluation loop to sample CPU', function () {
    // CPU usage is derived by diffing against the previous tick's snapshot
    // rather than by sleeping between two of its own. A sleeping sample cost
    // a full second of every manager cycle and made short intervals
    // impossible; this asserts the sleep cannot come back unnoticed.
    $calculator = new CapacityCalculator;

    $start = microtime(true);
    $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());
    $calculator->invalidateCache();
    $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());
    $duration = microtime(true) - $start;

    expect($duration)->toBeLessThan(0.5);
});

it('reports a real CPU percentage once it has two snapshots to diff', function () {
    $calculator = new CapacityCalculator;

    // The first tick of a process has nothing to diff against and reports the
    // neutral fallback; the second measures the interval between them.
    $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());
    $calculator->invalidateCache();
    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    $cpu = $result->details['cpu_details']['current_cpu_percent'];

    expect($cpu)->toBeGreaterThanOrEqual(0.0)
        ->and($cpu)->toBeLessThanOrEqual(100.0);
});

it('includes memory_estimate_source in memory_details', function () {
    $calculator = new CapacityCalculator;

    $result = $calculator->calculateMaxWorkers(0, ResourceEstimate::globalDefault());

    expect($result->details['memory_details'])
        ->toHaveKey('memory_estimate_source')
        ->and($result->details['memory_details']['memory_estimate_source'])->toBe('default');
});

it('uses per-queue memory estimate from ResourceEstimate', function () {
    config()->set('queue-autoscale.limits.max_memory_percent', 100);
    $calculator = new CapacityCalculator;

    $smallEstimate = ResourceEstimate::fromConfig(0.2, 50.0);
    $smallResult = $calculator->calculateMaxWorkers(0, $smallEstimate);

    $largeEstimate = ResourceEstimate::fromConfig(0.2, 2048.0);
    $largeResult = $calculator->calculateMaxWorkers(0, $largeEstimate);

    if ($largeResult->maxWorkersByMemory > 0) {
        expect($smallResult->maxWorkersByMemory)->toBeGreaterThan($largeResult->maxWorkersByMemory);
    } else {
        expect($smallResult->details['memory_details']['worker_memory_mb'])->toBe(50.0)
            ->and($largeResult->details['memory_details']['worker_memory_mb'])->toBe(2048.0);
    }
});
