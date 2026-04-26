<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

beforeEach(function (): void {
    config([
        'queue-autoscale.sla_defaults' => BalancedProfile::class,
        'queue-autoscale.queues' => [
            'payments' => CriticalProfile::class,
            'custom' => ['sla' => ['target_seconds' => 45]],
        ],
    ]);
});

test('falls back to default profile when queue not configured', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'unknown');

    expect($cfg->sla->targetSeconds)->toBe(30);
    expect($cfg->sla->percentile)->toBe(95);
});

test('uses per-queue profile class when configured', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'payments');

    expect($cfg->sla->targetSeconds)->toBe(10);
    expect($cfg->sla->percentile)->toBe(99);
});

test('deep merges array override on top of default profile', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'custom');

    expect($cfg->sla->targetSeconds)->toBe(45);
    expect($cfg->sla->percentile)->toBe(95);
    expect($cfg->workers->max)->toBe(10);
});

test('exposes all nested configuration value objects', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'default');

    expect($cfg->connection)->toBe('redis')
        ->and($cfg->queue)->toBe('default')
        ->and($cfg->sla->targetSeconds)->toBe(30)
        ->and($cfg->forecast->horizonSeconds)->toBe(60)
        ->and($cfg->workers->min)->toBe(1)
        ->and($cfg->spawnCompensation->enabled)->toBeTrue();
});
