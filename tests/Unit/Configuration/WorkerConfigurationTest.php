<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;

test('constructs with valid values', function (): void {
    $cfg = new WorkerConfiguration(1, 10, 3, 3600, 300, 3, 30);

    expect($cfg->min)->toBe(1)
        ->and($cfg->max)->toBe(10);
});

test('rejects min > max', function (): void {
    expect(fn () => new WorkerConfiguration(10, 5, 3, 3600, 300, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects negative min', function (): void {
    expect(fn () => new WorkerConfiguration(-1, 10, 3, 3600, 300, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects a job timeout that outlives the worker process', function (): void {
    // --timeout longer than --max-time can never fire: the process is
    // recycled first, so the job silently keeps the old behaviour.
    expect(fn () => new WorkerConfiguration(1, 10, 3, 300, 600, 3, 30))
        ->toThrow(InvalidConfigurationException::class, 'must be less than');
});

test('rejects a non-positive job timeout', function (): void {
    expect(fn () => new WorkerConfiguration(1, 10, 3, 3600, 0, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects zero tries', function (): void {
    expect(fn () => new WorkerConfiguration(1, 10, 0, 3600, 300, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('defaults scalable to true', function (): void {
    $cfg = new WorkerConfiguration(1, 10, 3, 3600, 300, 3, 30);

    expect($cfg->scalable)->toBeTrue();
});

test('allows non-scalable when min equals max', function (): void {
    $cfg = new WorkerConfiguration(1, 1, 3, 3600, 300, 3, 30, scalable: false);

    expect($cfg->scalable)->toBeFalse();
    expect($cfg->pinnedCount())->toBe(1);
});

test('rejects non-scalable when min differs from max', function (): void {
    expect(fn () => new WorkerConfiguration(1, 3, 3, 3600, 300, 3, 30, scalable: false))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects non-scalable when min is zero', function (): void {
    expect(fn () => new WorkerConfiguration(0, 0, 3, 3600, 300, 3, 30, scalable: false))
        ->toThrow(InvalidConfigurationException::class);
});
