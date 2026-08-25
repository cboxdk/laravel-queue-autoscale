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

/**
 * The leader's half of the same problem, which the sweep used to miss entirely.
 *
 * A cluster leader records breach state for every workload it discovers and
 * never calls recordScale() — the scaling happens on the followers. The sweep
 * was driven by the last scaling ACTION, so on a leader it visited nothing:
 * every queue name ever discovered kept a permanent entry, in a process that
 * runs for weeks, for an application that mints a queue name per tenant.
 */
test('breach state a leader recorded but never scaled is still dropped', function (): void {
    $manager = app(AutoscaleManager::class);
    $tracker = managerTracker($manager);

    Carbon::setTestNow(now()->subHours(3));

    try {
        // Exactly what the leader does: breach state, no scaling action.
        $tracker->setBreaching('queue:redis:tenant-gone', true);
    } finally {
        Carbon::setTestNow();
    }

    beginCycle($manager);

    expect($tracker->wasBreaching('queue:redis:tenant-gone'))->toBeFalse();
});

test('a leader sheds every vanished workload, not merely some', function (): void {
    // One entry proves the sweep reaches the map. This proves it reaches all of
    // it — the failure being guarded is unbounded growth, so the count matters.
    $manager = app(AutoscaleManager::class);
    $tracker = managerTracker($manager);
    $breachState = new ReflectionProperty($tracker, 'breachState');

    Carbon::setTestNow(now()->subHours(3));

    try {
        for ($tenant = 0; $tenant < 500; $tenant++) {
            $tracker->setBreaching("queue:redis:tenant-{$tenant}", true);
        }
    } finally {
        Carbon::setTestNow();
    }

    expect($breachState->getValue($tracker))->toHaveCount(500);

    beginCycle($manager);

    expect($breachState->getValue($tracker))->toBe([]);
});

test('a workload still being evaluated keeps its breach state', function (): void {
    // The bound is liveness, not scaling. A queue evaluated every cycle for
    // hours without ever moving must keep the edge that records whether its
    // breach has already been announced — dropping it makes a queue that has
    // been quietly breaching all along announce itself a second time.
    $manager = app(AutoscaleManager::class);
    $tracker = managerTracker($manager);

    Carbon::setTestNow(now()->subHours(3));

    try {
        $tracker->recordScale('redis:steady', 'up');
        $tracker->setBreaching('redis:steady', true);
    } finally {
        Carbon::setTestNow();
    }

    // Seen again this cycle, as an evaluated queue would be.
    $tracker->setBreaching('redis:steady', true);

    beginCycle($manager);

    expect($tracker->wasBreaching('redis:steady'))->toBeTrue();
});
