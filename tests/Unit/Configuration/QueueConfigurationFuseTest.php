<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

beforeEach(function (): void {
    config(['queue-autoscale.sla_defaults' => BalancedProfile::class]);
});

test('resolves the fuse block from the default profile', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'default');

    expect($cfg->fuse->enabled)->toBeTrue()
        ->and($cfg->fuse->failureThresholdPercent)->toBe(50.0)
        ->and($cfg->fuse->minSamples)->toBe(20)
        ->and($cfg->fuse->windowSeconds)->toBe(60)
        ->and($cfg->fuse->cooldownSeconds)->toBe(60);
});

test('a per-queue profile class brings its own fuse tuning', function (): void {
    config(['queue-autoscale.queues' => ['payments' => CriticalProfile::class]]);

    $cfg = QueueConfiguration::fromConfig('redis', 'payments');

    expect($cfg->fuse->failureThresholdPercent)->toBe(40.0)
        ->and($cfg->fuse->minSamples)->toBe(10)
        ->and($cfg->fuse->windowSeconds)->toBe(30);
});

test('a low-traffic profile widens the window so min_samples is reachable', function (): void {
    config(['queue-autoscale.queues' => ['reports' => BackgroundProfile::class]]);

    $cfg = QueueConfiguration::fromConfig('redis', 'reports');

    expect($cfg->fuse->windowSeconds)->toBe(300)
        ->and($cfg->fuse->cooldownSeconds)->toBe(300);
});

test('a pinned queue ships with the fuse off', function (): void {
    config(['queue-autoscale.queues' => ['sequential' => ExclusiveProfile::class]]);

    $cfg = QueueConfiguration::fromConfig('redis', 'sequential');

    expect($cfg->fuse->enabled)->toBeFalse();
});

test('a partial per-queue override deep merges over the profile block', function (): void {
    config(['queue-autoscale.queues' => [
        'flaky' => ['fuse' => ['failure_threshold_percent' => 25.0]],
    ]]);

    $cfg = QueueConfiguration::fromConfig('redis', 'flaky');

    expect($cfg->fuse->failureThresholdPercent)->toBe(25.0)
        // Untouched keys still come from the profile, not from bare defaults.
        ->and($cfg->fuse->minSamples)->toBe(20)
        ->and($cfg->fuse->windowSeconds)->toBe(60);
});

test('a queue can opt out of the fuse while keeping the rest of its profile', function (): void {
    config(['queue-autoscale.queues' => [
        'best-effort' => ['fuse' => ['enabled' => false]],
    ]]);

    $cfg = QueueConfiguration::fromConfig('redis', 'best-effort');

    expect($cfg->fuse->enabled)->toBeFalse()
        ->and($cfg->sla->targetSeconds)->toBe(30);
});

test('the global switch disables the fuse for every queue', function (): void {
    config([
        'queue-autoscale.fuse.enabled' => false,
        'queue-autoscale.queues' => ['payments' => CriticalProfile::class],
    ]);

    expect(QueueConfiguration::fromConfig('redis', 'payments')->fuse->enabled)->toBeFalse();
    expect(QueueConfiguration::fromConfig('redis', 'anything')->fuse->enabled)->toBeFalse();
});

test('a literal profile array predating the fuse still resolves', function (): void {
    // Upgrade safety: configs written before the fuse existed have no 'fuse'
    // key anywhere, and must keep booting on package defaults.
    config([
        'queue-autoscale.sla_defaults' => [
            'sla' => ['target_seconds' => 20, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 20],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => ModerateForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 1, 'max' => 8, 'tries' => 3, 'timeout_seconds' => 3600,
                'sleep_seconds' => 3, 'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => ['enabled' => true, 'fallback_seconds' => 2.0, 'min_samples' => 5, 'ema_alpha' => 0.2],
        ],
        'queue-autoscale.queues' => [],
    ]);

    $cfg = QueueConfiguration::fromConfig('redis', 'legacy');

    expect($cfg->sla->targetSeconds)->toBe(20)
        ->and($cfg->workers->max)->toBe(8)
        ->and($cfg->fuse->enabled)->toBeTrue()
        ->and($cfg->fuse->failureThresholdPercent)->toBe(50.0)
        ->and($cfg->fuse->minSamples)->toBe(20);
});
