<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;

test('creates instance with all properties', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: 'config',
        details: ['key' => 'value'],
    );

    expect($result->maxWorkersByCpu)->toBe(20)
        ->and($result->maxWorkersByMemory)->toBe(15)
        ->and($result->maxWorkersByConfig)->toBe(10)
        ->and($result->finalMaxWorkers)->toBe(10)
        ->and($result->limitingFactor)->toBe('config')
        ->and($result->details)->toBe(['key' => 'value']);
});

test('isCpuLimited returns true when cpu is limiting factor', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 10,
        maxWorkersByMemory: 20,
        maxWorkersByConfig: 30,
        finalMaxWorkers: 10,
        limitingFactor: 'cpu',
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
        limitingFactor: 'memory',
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
        limitingFactor: 'config',
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
        limitingFactor: 'config',
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
        limitingFactor: 'config',
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
        limitingFactor: 'cpu',
    );

    $formatted = $result->getFormattedDetails();

    expect($formatted['CPU Limit'])->toContain('no details')
        ->and($formatted['Memory Limit'])->toContain('no details')
        ->and($formatted['Final Capacity'])->toContain('constrained by CPU');
});

test('getFormattedDetails shows correct factor descriptions', function (string $factor, string $expected) {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 10,
        maxWorkersByMemory: 10,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: $factor,
    );

    $formatted = $result->getFormattedDetails();

    expect($formatted['Final Capacity'])->toContain($expected);
})->with([
    ['cpu', 'constrained by CPU'],
    ['memory', 'constrained by memory'],
    ['config', 'constrained by max_workers'],
    ['strategy', 'optimal based on demand'],
    ['unknown', 'limited by: unknown'],
]);

test('handles non-string values in details gracefully', function () {
    $result = new CapacityCalculationResult(
        maxWorkersByCpu: 20,
        maxWorkersByMemory: 15,
        maxWorkersByConfig: 10,
        finalMaxWorkers: 10,
        limitingFactor: 'config',
        details: [
            'cpu_explanation' => 123,
            'memory_explanation' => ['array'],
        ],
    );

    $formatted = $result->getFormattedDetails();

    expect($formatted['CPU Limit'])->toContain('no details')
        ->and($formatted['Memory Limit'])->toContain('no details');
});
