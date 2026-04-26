<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;

test('constructs with valid values', function (): void {
    $sla = new SlaConfiguration(30, 95, 300, 20);

    expect($sla->targetSeconds)->toBe(30)
        ->and($sla->percentile)->toBe(95)
        ->and($sla->windowSeconds)->toBe(300)
        ->and($sla->minSamples)->toBe(20);
});

test('rejects percentile outside allowed values', function (): void {
    expect(fn () => new SlaConfiguration(30, 42, 300, 20))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects window shorter than 60 seconds', function (): void {
    expect(fn () => new SlaConfiguration(30, 95, 59, 20))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects non-positive target seconds', function (): void {
    expect(fn () => new SlaConfiguration(0, 95, 300, 20))
        ->toThrow(InvalidConfigurationException::class);
});
