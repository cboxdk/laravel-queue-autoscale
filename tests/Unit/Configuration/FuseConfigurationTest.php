<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\FuseConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;

test('accepts a valid configuration', function (): void {
    $fuse = new FuseConfiguration(
        enabled: true,
        failureThresholdPercent: 50.0,
        minSamples: 20,
        windowSeconds: 60,
        cooldownSeconds: 60,
    );

    expect($fuse->enabled)->toBeTrue()
        ->and($fuse->failureThresholdPercent)->toBe(50.0)
        ->and($fuse->minSamples)->toBe(20)
        ->and($fuse->windowSeconds)->toBe(60)
        ->and($fuse->cooldownSeconds)->toBe(60);
});

test('accepts a threshold of exactly 100 percent', function (): void {
    $fuse = new FuseConfiguration(true, 100.0, 1, 1, 1);

    expect($fuse->failureThresholdPercent)->toBe(100.0);
});

test('rejects a threshold outside the valid range', function (float $threshold): void {
    expect(fn () => new FuseConfiguration(true, $threshold, 20, 60, 60))
        ->toThrow(InvalidConfigurationException::class, 'failure_threshold_percent');
})->with([0.0, -1.0, 100.1, 250.0]);

test('rejects a min_samples below one', function (int $minSamples): void {
    expect(fn () => new FuseConfiguration(true, 50.0, $minSamples, 60, 60))
        ->toThrow(InvalidConfigurationException::class, 'min_samples');
})->with([0, -5]);

test('rejects a window below one second', function (int $window): void {
    expect(fn () => new FuseConfiguration(true, 50.0, 20, $window, 60))
        ->toThrow(InvalidConfigurationException::class, 'window_seconds');
})->with([0, -30]);

test('rejects a cooldown below one second', function (int $cooldown): void {
    expect(fn () => new FuseConfiguration(true, 50.0, 20, 60, $cooldown))
        ->toThrow(InvalidConfigurationException::class, 'cooldown_seconds');
})->with([0, -30]);

test('validates a disabled fuse too, so a typo is not hidden until it is switched on', function (): void {
    expect(fn () => new FuseConfiguration(false, 50.0, 0, 60, 60))
        ->toThrow(InvalidConfigurationException::class);
});

describe('fromArray', function (): void {
    test('applies package defaults for an absent block', function (): void {
        $fuse = FuseConfiguration::fromArray([]);

        expect($fuse->enabled)->toBeTrue()
            ->and($fuse->failureThresholdPercent)->toBe(50.0)
            ->and($fuse->minSamples)->toBe(20)
            ->and($fuse->windowSeconds)->toBe(60)
            ->and($fuse->cooldownSeconds)->toBe(60);
    });

    test('reads every value from the block', function (): void {
        $fuse = FuseConfiguration::fromArray([
            'enabled' => true,
            'failure_threshold_percent' => 35.5,
            'min_samples' => 7,
            'window_seconds' => 120,
            'cooldown_seconds' => 90,
        ]);

        expect($fuse->failureThresholdPercent)->toBe(35.5)
            ->and($fuse->minSamples)->toBe(7)
            ->and($fuse->windowSeconds)->toBe(120)
            ->and($fuse->cooldownSeconds)->toBe(90);
    });

    test('fills in defaults for partially specified blocks', function (): void {
        $fuse = FuseConfiguration::fromArray(['min_samples' => 5]);

        expect($fuse->minSamples)->toBe(5)
            ->and($fuse->failureThresholdPercent)->toBe(50.0)
            ->and($fuse->windowSeconds)->toBe(60);
    });

    test('falls back to defaults rather than coercing unusable values to zero', function (): void {
        // Casting these would produce 0, which the constructor rejects — a
        // config typo would take the manager down on boot instead of running
        // on documented defaults.
        $fuse = FuseConfiguration::fromArray([
            'failure_threshold_percent' => 'fifty',
            'min_samples' => null,
            'window_seconds' => ['nonsense'],
            'cooldown_seconds' => new stdClass,
        ]);

        expect($fuse->failureThresholdPercent)->toBe(50.0)
            ->and($fuse->minSamples)->toBe(20)
            ->and($fuse->windowSeconds)->toBe(60)
            ->and($fuse->cooldownSeconds)->toBe(60);
    });

    test('accepts numeric strings, as env-driven config produces', function (): void {
        $fuse = FuseConfiguration::fromArray([
            'failure_threshold_percent' => '42.5',
            'min_samples' => '15',
        ]);

        expect($fuse->failureThresholdPercent)->toBe(42.5)
            ->and($fuse->minSamples)->toBe(15);
    });

    test('treats numeric enabled flags as booleans', function (): void {
        expect(FuseConfiguration::fromArray(['enabled' => 1])->enabled)->toBeTrue();
        expect(FuseConfiguration::fromArray(['enabled' => 0])->enabled)->toBeFalse();
    });

    test('defaults enabled to true for an unusable flag', function (): void {
        expect(FuseConfiguration::fromArray(['enabled' => 'yes please'])->enabled)->toBeTrue();
    });

    test('the global switch can disable a queue that opted in', function (): void {
        config()->set('queue-autoscale.fuse.enabled', false);

        expect(FuseConfiguration::fromArray(['enabled' => true])->enabled)->toBeFalse();
    });

    test('the global switch cannot enable a queue that opted out', function (): void {
        config()->set('queue-autoscale.fuse.enabled', true);

        expect(FuseConfiguration::fromArray(['enabled' => false])->enabled)->toBeFalse();
    });
});
