<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\FuseConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BurstyProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;
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

    expect($resolved)->toHaveKeys(['sla', 'forecast', 'workers', 'spawn_compensation', 'fuse']);
    expect($resolved['sla'])->toHaveKeys(['target_seconds', 'percentile', 'window_seconds', 'min_samples']);
    expect($resolved['forecast'])->toHaveKeys(['forecaster', 'policy', 'horizon_seconds', 'history_seconds']);
    expect($resolved['workers'])->toHaveKeys(['min', 'max', 'tries', 'timeout_seconds', 'sleep_seconds', 'shutdown_timeout_seconds']);
    expect($resolved['spawn_compensation'])->toHaveKeys(['enabled', 'fallback_seconds', 'min_samples', 'ema_alpha']);
    expect($resolved['fuse'])->toHaveKeys(['enabled', 'failure_threshold_percent', 'min_samples', 'window_seconds', 'cooldown_seconds']);
})->with([
    CriticalProfile::class,
    HighVolumeProfile::class,
    BalancedProfile::class,
    BurstyProfile::class,
    BackgroundProfile::class,
]);

test('shipped fuse tuning matches the documented table', function (string $class, array $expected): void {
    // Pinned deliberately: docs/basic-usage/workload-profiles.md and
    // docs/basic-usage/failure-fuse.md both publish these numbers, and a
    // silent change here would leave the documentation lying.
    expect((new $class)->resolve()['fuse'])->toBe($expected);
})->with([
    'critical' => [CriticalProfile::class, [
        'enabled' => true, 'failure_threshold_percent' => 40.0, 'min_samples' => 10,
        'window_seconds' => 30, 'cooldown_seconds' => 30,
    ]],
    'high volume' => [HighVolumeProfile::class, [
        'enabled' => true, 'failure_threshold_percent' => 50.0, 'min_samples' => 100,
        'window_seconds' => 60, 'cooldown_seconds' => 60,
    ]],
    'balanced' => [BalancedProfile::class, [
        'enabled' => true, 'failure_threshold_percent' => 50.0, 'min_samples' => 20,
        'window_seconds' => 60, 'cooldown_seconds' => 60,
    ]],
    'bursty' => [BurstyProfile::class, [
        'enabled' => true, 'failure_threshold_percent' => 50.0, 'min_samples' => 20,
        'window_seconds' => 120, 'cooldown_seconds' => 60,
    ]],
    'background' => [BackgroundProfile::class, [
        'enabled' => true, 'failure_threshold_percent' => 60.0, 'min_samples' => 10,
        'window_seconds' => 300, 'cooldown_seconds' => 300,
    ]],
]);

test('every shipped fuse block is accepted by FuseConfiguration', function (string $class): void {
    // A profile shipping values the value object rejects would only blow up
    // in an app that selected that profile.
    $fuse = FuseConfiguration::fromArray(
        (new $class)->resolve()['fuse']
    );

    expect($fuse->minSamples)->toBeGreaterThan(0);
})->with([
    CriticalProfile::class,
    HighVolumeProfile::class,
    BalancedProfile::class,
    BurstyProfile::class,
    BackgroundProfile::class,
    ExclusiveProfile::class,
]);

test('a low-volume profile can actually reach its own min_samples', function (string $class, int $jobsPerMinute): void {
    // A window too short for the queue's throughput makes the fuse dead
    // config: it can never accumulate the samples it needs to act. The x2
    // accounts for the two-bucket read.
    $fuse = (new $class)->resolve()['fuse'];
    $reachable = ($jobsPerMinute * ($fuse['window_seconds'] / 60)) * 2;

    expect($reachable)->toBeGreaterThanOrEqual($fuse['min_samples']);
})->with([
    // Conservative throughput estimates for each profile's intended workload.
    'background at 2 jobs/min' => [BackgroundProfile::class, 2],
    'bursty at 10 jobs/min' => [BurstyProfile::class, 10],
    'balanced at 20 jobs/min' => [BalancedProfile::class, 20],
    'critical at 20 jobs/min' => [CriticalProfile::class, 20],
    'high volume at 600 jobs/min' => [HighVolumeProfile::class, 600],
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
