<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;

beforeEach(function (): void {
    config(['queue-autoscale.sla_defaults' => BalancedProfile::class]);
});

test('builds from profile class and queue list', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms', 'push'],
        'profile' => BalancedProfile::class,
        'connection' => 'redis',
    ]);

    expect($group->name)->toBe('notifications');
    expect($group->connection)->toBe('redis');
    expect($group->queues)->toBe(['email', 'sms', 'push']);
    expect($group->mode)->toBe('priority');
    expect($group->queueArgument())->toBe('email,sms,push');
});

test('falls back to sla_defaults when no profile named', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email'],
    ]);

    expect($group->sla->targetSeconds)->toBe(30);
});

test('applies overrides on top of profile', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email'],
        'profile' => BalancedProfile::class,
        'overrides' => ['sla' => ['target_seconds' => 45]],
    ]);

    expect($group->sla->targetSeconds)->toBe(45);
});

test('rejects empty queue list', function (): void {
    expect(fn () => GroupConfiguration::fromConfig('empty', ['queues' => []]))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects missing queue list', function (): void {
    expect(fn () => GroupConfiguration::fromConfig('bad', ['profile' => BalancedProfile::class]))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects duplicate queue in same group', function (): void {
    expect(fn () => GroupConfiguration::fromConfig('dup', [
        'queues' => ['email', 'email'],
    ]))->toThrow(InvalidConfigurationException::class);
});

test('rejects unsupported mode', function (): void {
    expect(fn () => GroupConfiguration::fromConfig('bad', [
        'queues' => ['email'],
        'mode' => 'round-robin',
    ]))->toThrow(InvalidConfigurationException::class);
});

test('rejects non-scalable profile', function (): void {
    expect(fn () => GroupConfiguration::fromConfig('bad', [
        'queues' => ['email'],
        'profile' => ExclusiveProfile::class,
    ]))->toThrow(InvalidConfigurationException::class);
});

test('to scaling configuration uses group name as queue', function (): void {
    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms'],
        'profile' => CriticalProfile::class,
        'connection' => 'sqs',
    ]);

    $scalingCfg = $group->toScalingConfiguration();

    expect($scalingCfg->queue)->toBe('notifications');
    expect($scalingCfg->connection)->toBe('sqs');
    expect($scalingCfg->sla->targetSeconds)->toBe(10);
});

test('allFromConfig loads all configured groups', function (): void {
    config([
        'queue-autoscale.groups' => [
            'notifications' => [
                'queues' => ['email', 'sms'],
                'profile' => BalancedProfile::class,
            ],
            'reports' => [
                'queues' => ['daily', 'weekly'],
                'profile' => BalancedProfile::class,
            ],
        ],
    ]);

    $groups = GroupConfiguration::allFromConfig();

    expect($groups)->toHaveKeys(['notifications', 'reports']);
    expect($groups['notifications']->queues)->toBe(['email', 'sms']);
});

test('assertNoQueueConflicts rejects queue in both per-queue and group', function (): void {
    config([
        'queue-autoscale.queues' => [
            'email' => BalancedProfile::class,
        ],
    ]);

    $group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms'],
    ]);

    expect(fn () => GroupConfiguration::assertNoQueueConflicts(['notifications' => $group]))
        ->toThrow(InvalidConfigurationException::class);
});

test('assertNoQueueConflicts rejects queue in multiple groups', function (): void {
    $g1 = GroupConfiguration::fromConfig('group-a', ['queues' => ['shared']]);
    $g2 = GroupConfiguration::fromConfig('group-b', ['queues' => ['shared']]);

    expect(fn () => GroupConfiguration::assertNoQueueConflicts(['group-a' => $g1, 'group-b' => $g2]))
        ->toThrow(InvalidConfigurationException::class);
});

test('assertNoQueueConflicts passes when no overlap', function (): void {
    config([
        'queue-autoscale.queues' => [
            'standalone' => BalancedProfile::class,
        ],
    ]);

    $g1 = GroupConfiguration::fromConfig('group-a', ['queues' => ['email', 'sms']]);
    $g2 = GroupConfiguration::fromConfig('group-b', ['queues' => ['reports']]);

    expect(fn () => GroupConfiguration::assertNoQueueConflicts(['group-a' => $g1, 'group-b' => $g2]))
        ->not->toThrow(InvalidConfigurationException::class);
});
