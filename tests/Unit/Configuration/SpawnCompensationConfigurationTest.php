<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;

test('constructs with valid values', function (): void {
    $cfg = new SpawnCompensationConfiguration(true, 2.0, 5, 0.2);

    expect($cfg->enabled)->toBeTrue()
        ->and($cfg->fallbackSeconds)->toBe(2.0)
        ->and($cfg->minSamples)->toBe(5)
        ->and($cfg->emaAlpha)->toBe(0.2);
});

test('rejects alpha outside (0, 1]', function (): void {
    expect(fn () => new SpawnCompensationConfiguration(true, 2.0, 5, 1.5))
        ->toThrow(InvalidConfigurationException::class);

    expect(fn () => new SpawnCompensationConfiguration(true, 2.0, 5, 0.0))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects negative fallback', function (): void {
    expect(fn () => new SpawnCompensationConfiguration(true, -1.0, 5, 0.2))
        ->toThrow(InvalidConfigurationException::class);
});
