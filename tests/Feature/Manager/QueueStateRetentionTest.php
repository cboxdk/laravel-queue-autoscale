<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;

/**
 * Per-queue bookkeeping is keyed by queue name in a process that runs for
 * weeks. An application generating queue names per tenant accumulates one
 * entry per tenant that ever dispatched a job, and nothing shed them.
 *
 * Bounded rather than cleared: the anti-flapping window and breach state are
 * what stop a queue oscillating, so discarding them every cycle would defeat
 * both.
 */
function managerState(AutoscaleManager $m, string $prop): array
{
    return (new ReflectionProperty($m, $prop))->getValue($m);
}

function seedQueueState(AutoscaleManager $m, string $key, Carbon $at): void
{
    foreach ([['lastScaleTime', $at], ['lastScaleDirection', 'up'], ['breachState', true]] as [$prop, $value]) {
        $p = new ReflectionProperty($m, $prop);
        $existing = $p->getValue($m);
        $existing[$key] = $value;
        $p->setValue($m, $existing);
    }
}

function beginCycle(AutoscaleManager $m): void
{
    (new ReflectionMethod($m, 'beginEvaluationCycle'))->invoke($m);
}

test('state for a queue that went quiet is dropped', function (): void {
    $manager = app(AutoscaleManager::class);

    seedQueueState($manager, 'redis:tenant-gone', now()->subHours(3));

    beginCycle($manager);

    expect(managerState($manager, 'lastScaleTime'))->not->toHaveKey('redis:tenant-gone')
        ->and(managerState($manager, 'lastScaleDirection'))->not->toHaveKey('redis:tenant-gone')
        ->and(managerState($manager, 'breachState'))->not->toHaveKey('redis:tenant-gone');
});

test('a recently scaled queue keeps its anti-flapping window', function (): void {
    // Dropping this would let a queue reverse direction immediately, which is
    // exactly what the cooldown exists to prevent.
    $manager = app(AutoscaleManager::class);

    seedQueueState($manager, 'redis:busy', now()->subSeconds(30));

    beginCycle($manager);

    expect(managerState($manager, 'lastScaleTime'))->toHaveKey('redis:busy')
        ->and(managerState($manager, 'lastScaleDirection'))->toHaveKey('redis:busy');
});

test('the rendered queue list does not keep vanished queues', function (): void {
    $manager = app(AutoscaleManager::class);

    $p = new ReflectionProperty($manager, 'currentQueueStats');
    $p->setValue($manager, ['redis:gone' => ['workers' => 3]]);

    beginCycle($manager);

    expect($p->getValue($manager))->toBe([]);
});
