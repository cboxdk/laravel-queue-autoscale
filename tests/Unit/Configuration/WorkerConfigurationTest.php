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
