<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\WorkloadStateTracker;

/**
 * Per-queue bookkeeping is keyed by queue name in a process that runs for
 * weeks. An application generating queue names per tenant accumulates one
 * entry per tenant that ever dispatched a job, and nothing shed them.
 *
 * Bounded rather than cleared: the anti-flapping window and breach state are
 * what stop a queue oscillating, so discarding them every cycle would defeat
 * both.
 */
function managerTracker(AutoscaleManager $m): WorkloadStateTracker
{
    return (new ReflectionProperty($m, 'workloadState'))->getValue($m);
}

/**
 * Record a scaling action as though it had happened at $at, so the retention
 * cutoff sees a genuinely old entry rather than a hand-placed array key.
 */
function seedQueueState(AutoscaleManager $m, string $key, Carbon $at): void
{
    $tracker = managerTracker($m);

    Carbon::setTestNow($at);

    try {
        $tracker->recordScale($key, 'up');
        $tracker->setBreaching($key, true);
    } finally {
        Carbon::setTestNow();
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

    $tracker = managerTracker($manager);

    expect($tracker->lastDirection('redis:tenant-gone'))->toBeNull()
        ->and($tracker->wasBreaching('redis:tenant-gone'))->toBeFalse()
        ->and($tracker->inCooldown('redis:tenant-gone', 86400))->toBeFalse();
});

test('a recently scaled queue keeps its anti-flapping window', function (): void {
    // Dropping this would let a queue reverse direction immediately, which is
    // exactly what the cooldown exists to prevent.
    $manager = app(AutoscaleManager::class);

    seedQueueState($manager, 'redis:busy', now()->subSeconds(30));

    beginCycle($manager);

    $tracker = managerTracker($manager);

    expect($tracker->lastDirection('redis:busy'))->toBe('up')
        ->and($tracker->inCooldown('redis:busy', 60))->toBeTrue();
});

test('the rendered queue list does not keep vanished queues', function (): void {
    $manager = app(AutoscaleManager::class);

    $p = new ReflectionProperty($manager, 'currentQueueStats');
    $p->setValue($manager, ['redis:gone' => ['workers' => 3]]);

    beginCycle($manager);

    expect($p->getValue($manager))->toBe([]);
});
