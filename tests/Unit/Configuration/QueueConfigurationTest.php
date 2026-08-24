<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;
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
    config()->set('queue-autoscale.queues.default', ['sla' => ['target_seconds' => 30]]);

    $cfg = QueueConfiguration::fromConfig('redis', 'default');

    expect($cfg->connection)->toBe('redis')
        ->and($cfg->queue)->toBe('default')
        ->and($cfg->sla->targetSeconds)->toBe(30)
        ->and($cfg->forecast->horizonSeconds)->toBe(60)
        ->and($cfg->workers->min)->toBe(1)
        ->and($cfg->spawnCompensation->enabled)->toBeTrue();
});

/*
 * A worker floor is a statement about a queue the operator named. Queues are
 * discovered, so a floor inherited by the fall-through path is a floor
 * multiplied by a set nobody bounded — one permanent process per queue name
 * ever seen. Everything else about the defaults still applies.
 */
test('a queue matching no configured rule loses its worker floor', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'tenant-9f2a-exports');

    expect($cfg->workers->min)->toBe(0)
        ->and($cfg->workers->max)->toBe((new BalancedProfile)->resolve()['workers']['max'])
        ->and($cfg->sla->targetSeconds)->toBe(30)
        ->and($cfg->fuse->enabled)->toBeTrue();
});

test('an explicitly named queue keeps its floor', function (): void {
    config()->set('queue-autoscale.queues.reports', ['workers' => ['min' => 3]]);

    expect(QueueConfiguration::fromConfig('redis', 'reports')->workers->min)->toBe(3);
});

test('a glob-matched queue keeps its floor', function (): void {
    config()->set('queue-autoscale.queues', ['tenant-*' => ['workers' => ['min' => 2]]]);

    expect(QueueConfiguration::fromConfig('redis', 'tenant-42')->workers->min)->toBe(2)
        ->and(QueueConfiguration::fromConfig('redis', 'unrelated')->workers->min)->toBe(0);
});

test('a wildcard rule restores the floor for every discovered queue', function (): void {
    // The documented escape hatch for anyone relying on the old behaviour.
    config()->set('queue-autoscale.queues', ['*' => ['workers' => ['min' => 1]]]);

    expect(QueueConfiguration::fromConfig('redis', 'anything-at-all')->workers->min)->toBe(1);
});

/*
 * workers.scalable = false requires min === max by construction, so zeroing
 * the floor would make the configuration invalid and throw — aborting every
 * discovered queue every cycle. Pointing sla_defaults at a non-scalable
 * profile is an explicit statement that every queue runs a fixed worker
 * count, unlike the shipped default which is merely what you get by not
 * choosing.
 */
test('a non-scalable default profile keeps its floor for unmatched queues', function (): void {
    config()->set('queue-autoscale.sla_defaults', ExclusiveProfile::class);
    config()->set('queue-autoscale.queues', []);

    $cfg = QueueConfiguration::fromConfig('redis', 'discovered-tenant-x');

    expect($cfg->workers->scalable)->toBeFalse()
        ->and($cfg->workers->min)->toBe($cfg->workers->max)
        ->and($cfg->workers->min)->toBeGreaterThan(0);
});

/*
 * The floor guard and the constructor must read workers.scalable the same
 * way. Reading one strictly and casting the other made them disagree on every
 * falsy non-false value: 'scalable' => 0 slipped past a strict check, had its
 * floor zeroed, and then threw on the cast.
 */
test('the floor guard agrees with the constructor on every falsy scalable value', function (mixed $scalable): void {
    $defaults = (new BalancedProfile)->resolve();
    $defaults['workers']['min'] = 4;
    $defaults['workers']['max'] = 4;
    $defaults['workers']['scalable'] = $scalable;

    config()->set('queue-autoscale.sla_defaults', $defaults);
    config()->set('queue-autoscale.queues', []);

    $cfg = QueueConfiguration::fromConfig('redis', 'discovered');

    expect($cfg->workers->min)->toBe($cfg->workers->scalable ? 0 : 4);
})->with([[0], [false], [null], [''], ['0']]);
