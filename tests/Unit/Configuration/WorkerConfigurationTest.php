<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;

test('constructs with valid values', function (): void {
    $cfg = new WorkerConfiguration(1, 10, 3, 3600, 3, 30);

    expect($cfg->min)->toBe(1)
        ->and($cfg->max)->toBe(10);
});

test('rejects min > max', function (): void {
    expect(fn () => new WorkerConfiguration(10, 5, 3, 3600, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects negative min', function (): void {
    expect(fn () => new WorkerConfiguration(-1, 10, 3, 3600, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects zero tries', function (): void {
    expect(fn () => new WorkerConfiguration(1, 10, 0, 3600, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('defaults scalable to true', function (): void {
    $cfg = new WorkerConfiguration(1, 10, 3, 3600, 3, 30);

    expect($cfg->scalable)->toBeTrue();
});

test('allows non-scalable when min equals max', function (): void {
    $cfg = new WorkerConfiguration(1, 1, 3, 3600, 3, 30, scalable: false);

    expect($cfg->scalable)->toBeFalse();
    expect($cfg->pinnedCount())->toBe(1);
});

test('rejects non-scalable when min differs from max', function (): void {
    expect(fn () => new WorkerConfiguration(1, 3, 3, 3600, 3, 30, scalable: false))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects non-scalable when min is zero', function (): void {
    expect(fn () => new WorkerConfiguration(0, 0, 3, 3600, 3, 30, scalable: false))
        ->toThrow(InvalidConfigurationException::class);
});
