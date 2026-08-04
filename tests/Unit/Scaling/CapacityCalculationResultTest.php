<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;

test('creates instance with all properties', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Config,
        details: ['key' => 'value'],
    );

    expect($result->maxWorkersByCpu)->toBe(20)
        ->and($result->maxWorkersByMemory)->toBe(15)
        ->and($result->maxWorkersByConfig)->toBe(10)
        ->and($result->finalMaxWorkers)->toBe(10)
        ->and($result->limitingFactor)->toBe(LimitingFactor::Config)
        ->and($result->details)->toBe(['key' => 'value']);
});

test('isCpuLimited returns true when cpu is limiting factor', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 10,
        maxWorkersByMemory: 20,
        maxWorkersByConfig: 30,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Cpu,
    );

    expect($result->isCpuLimited())->toBeTrue()
        ->and($result->isMemoryLimited())->toBeFalse()
        ->and($result->isConfigLimited())->toBeFalse();
});

test('isMemoryLimited returns true when memory is limiting factor', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 30,
        maxWorkersByMemory: 10,
        maxWorkersByConfig: 20,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Memory,
    );

    expect($result->isMemoryLimited())->toBeTrue()
        ->and($result->isCpuLimited())->toBeFalse()
        ->and($result->isConfigLimited())->toBeFalse();
});

test('isConfigLimited returns true when config is limiting factor', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 30,
        maxWorkersByMemory: 20,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Config,
    );

    expect($result->isConfigLimited())->toBeTrue()
        ->and($result->isCpuLimited())->toBeFalse()
        ->and($result->isMemoryLimited())->toBeFalse();
});

test('getSummary returns formatted string', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Config,
    );

    $summary = $result->getSummary();

    expect($summary)->toContain('CPU: 20 workers')
        ->and($summary)->toContain('Memory: 15 workers')
        ->and($summary)->toContain('Config: 10 workers')
        ->and($summary)->toContain('Final: 10 workers')
        ->and($summary)->toContain('limited by: config');
});

test('getFormattedDetails returns formatted array', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Config,
        details: [
            'cpu_explanation' => '8 cores available',
            'memory_explanation' => '4GB free',
        ],
    );

    $formatted = $result->getFormattedDetails();

    expect($formatted)->toHaveKey('CPU Limit')
        ->and($formatted)->toHaveKey('Memory Limit')
        ->and($formatted)->toHaveKey('Config Limit')
        ->and($formatted)->toHaveKey('Final Capacity')
        ->and($formatted['CPU Limit'])->toContain('20 workers')
        ->and($formatted['CPU Limit'])->toContain('8 cores available')
        ->and($formatted['Memory Limit'])->toContain('15 workers')
        ->and($formatted['Memory Limit'])->toContain('4GB free');
});

test('getFormattedDetails handles missing details', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Cpu,
    );

    $formatted = $result->getFormattedDetails();

    expect($formatted['CPU Limit'])->toContain('no details')
        ->and($formatted['Memory Limit'])->toContain('no details')
        ->and($formatted['Final Capacity'])->toContain('constrained by CPU');
});

test('getFormattedDetails describes every limiting factor', function (LimitingFactor $factor, string $expected) {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 10,
        maxWorkersByMemory: 10,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: $factor,
    );

    expect($result->getFormattedDetails()['Final Capacity'])->toContain($expected);
})->with([
    [LimitingFactor::Cpu, 'constrained by CPU'],
    [LimitingFactor::Memory, 'constrained by memory'],
    [LimitingFactor::Balanced, 'CPU and memory constrain equally'],
    [LimitingFactor::Config, 'constrained by workers.max'],
    [LimitingFactor::Strategy, 'optimal based on demand'],
    [LimitingFactor::Fuse, 'held back by the failure fuse'],
    [LimitingFactor::SystemMetricsUnavailable, 'system metrics unavailable'],
]);

test('every limiting factor has a description, so none can leak a raw token', function (): void {
    // The old string version fell through to "limited by: <token>" for any
    // value its match did not know, which is how operators saw
    // "limited by: system_metrics_unavailable" in verbose output.
    foreach (LimitingFactor::cases() as $factor) {
        expect($factor->description())
            ->not->toContain('_')          // no raw snake_case token
            ->not->toContain('limited by:'); // no fall-through phrasing
    }
});

test('handles non-string values in details gracefully', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: LimitingFactor::Config,
        details: [
            'cpu_explanation' => 123,
            'memory_explanation' => ['array'],
        ],
    );

    $formatted = $result->getFormattedDetails();

    expect($formatted['CPU Limit'])->toContain('no details')
        ->and($formatted['Memory Limit'])->toContain('no details');
});
