<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

beforeEach(function (): void {
    config(['queue-autoscale.sla_defaults' => BalancedProfile::class]);
});

test('a group inherits the fuse tuning of its profile', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms'],
        'profile' => CriticalProfile::class,
    ]);

    expect($group->fuse->failureThresholdPercent)->toBe(40.0)
        ->and($group->fuse->windowSeconds)->toBe(30);
});

test('a group without a profile falls back to sla_defaults', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms'],
    ]);

    expect($group->fuse->failureThresholdPercent)->toBe(50.0)
        ->and($group->fuse->minSamples)->toBe(20);
});

test('group overrides apply to the fuse block', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms'],
        'overrides' => ['fuse' => ['failure_threshold_percent' => 70.0, 'cooldown_seconds' => 120]],
    ]);

    expect($group->fuse->failureThresholdPercent)->toBe(70.0)
        ->and($group->fuse->cooldownSeconds)->toBe(120)
        ->and($group->fuse->minSamples)->toBe(20);
});

test('the fuse survives adaptation to a scaling configuration', function (): void {
    // Groups are scaled through a QueueConfiguration built from the group, so
    // a fuse that stopped here would silently never apply to grouped queues.
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms', 'push'],
        'profile' => CriticalProfile::class,
    ]);

    $scaling = $group->toScalingConfiguration();

    expect($scaling->fuse->failureThresholdPercent)->toBe(40.0)
        ->and($scaling->fuse->windowSeconds)->toBe(30)
        ->and($scaling->queue)->toBe('notifications')
        ->and($scaling->memberQueues)->toBe(['email', 'sms', 'push']);
});

test('a group can opt out of the fuse', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email'],
        'overrides' => ['fuse' => ['enabled' => false]],
    ]);

    expect($group->toScalingConfiguration()->fuse->enabled)->toBeFalse();
});
