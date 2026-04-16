<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BurstyProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\HighVolumeProfile;
use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

test('all profiles implement ProfileContract', function (string $class): void {
    expect(new $class)->toBeInstanceOf(ProfileContract::class);
})->with([
    CriticalProfile::class,
    HighVolumeProfile::class,
    BalancedProfile::class,
    BurstyProfile::class,
    BackgroundProfile::class,
]);

test('all profiles return shape with required top-level keys', function (string $class): void {
    $resolved = (new $class)->resolve();

    expect($resolved)->toHaveKeys(['sla', 'forecast', 'workers', 'spawn_compensation']);
    expect($resolved['sla'])->toHaveKeys(['target_seconds', 'percentile', 'window_seconds', 'min_samples']);
    expect($resolved['forecast'])->toHaveKeys(['forecaster', 'policy', 'horizon_seconds', 'history_seconds']);
    expect($resolved['workers'])->toHaveKeys(['min', 'max', 'tries', 'timeout_seconds', 'sleep_seconds', 'shutdown_timeout_seconds']);
    expect($resolved['spawn_compensation'])->toHaveKeys(['enabled', 'fallback_seconds', 'min_samples', 'ema_alpha']);
})->with([
    CriticalProfile::class,
    HighVolumeProfile::class,
    BalancedProfile::class,
    BurstyProfile::class,
    BackgroundProfile::class,
]);

test('balanced profile uses p95 and 30s target', function (): void {
    $resolved = (new BalancedProfile)->resolve();
    expect($resolved['sla']['target_seconds'])->toBe(30);
    expect($resolved['sla']['percentile'])->toBe(95);
});

test('critical profile uses stricter SLA', function (): void {
    $resolved = (new CriticalProfile)->resolve();
    expect($resolved['sla']['target_seconds'])->toBeLessThanOrEqual(15);
    expect($resolved['sla']['percentile'])->toBeGreaterThanOrEqual(95);
});

test('background profile allows zero min workers', function (): void {
    $resolved = (new BackgroundProfile)->resolve();
    expect($resolved['workers']['min'])->toBe(0);
});
