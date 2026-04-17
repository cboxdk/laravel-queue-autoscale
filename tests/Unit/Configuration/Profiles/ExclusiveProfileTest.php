<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

test('implements ProfileContract', function (): void {
    expect(new ExclusiveProfile)->toBeInstanceOf(ProfileContract::class);
});

test('pins workers to exactly one and disables scaling', function (): void {
    $resolved = (new ExclusiveProfile)->resolve();

    expect($resolved['workers']['min'])->toBe(1);
    expect($resolved['workers']['max'])->toBe(1);
    expect($resolved['workers']['scalable'] ?? true)->toBeFalse();
});

test('resolves through QueueConfiguration as non-scalable', function (): void {
    config([
        'queue-autoscale.sla_defaults' => \Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile::class,
        'queue-autoscale.queues' => [
            'sequential-sync' => ExclusiveProfile::class,
        ],
    ]);

    $cfg = QueueConfiguration::fromConfig('redis', 'sequential-sync');

    expect($cfg->workers->scalable)->toBeFalse();
    expect($cfg->workers->pinnedCount())->toBe(1);
});

test('defaults to scalable when profile does not set the flag', function (): void {
    config([
        'queue-autoscale.sla_defaults' => \Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile::class,
        'queue-autoscale.queues' => [],
    ]);

    $cfg = QueueConfiguration::fromConfig('redis', 'default');

    expect($cfg->workers->scalable)->toBeTrue();
});
