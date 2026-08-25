<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Leadership that keeps moving is the disease; the scaling guards degrading is
 * the symptom.
 *
 * Taking the lease discards worker placement, the anti-flapping window and the
 * fair-share rotation's position, because each describes a cluster the new
 * leader has not observed. One failover costs a cycle. Leadership changing
 * several times inside one anti-flapping window means none of those ever
 * completes — measured, with leadership moving every eleven cycles two of six
 * contending queues went back to never being served at all.
 *
 * Until now a change was a debug line and an event nobody is obliged to listen
 * to, so the cluster degraded quietly.
 */
function observeLeader(AutoscaleManager $manager, ?string $leaderId): void
{
    (new ReflectionMethod($manager, 'dispatchLeaderChanged'))->invoke($manager, $leaderId);
}

beforeEach(function (): void {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.scaling.cooldown_seconds', 60);

    // The warning is rate limited through a cache lock, and the lock outlives a
    // test. Without this the one spec that DOES warn consumes the limiter and
    // every "never warns" spec passes for the wrong reason — which is exactly
    // what happened: with the threshold lowered to one, four of these five
    // still passed.
    Cache::flush();

    $this->manager = app(AutoscaleManager::class);
});

test('discovering the leader at startup is not a change', function (): void {
    // Every manager starts knowing nothing and then learns who leads. Counting
    // that would make an ordinary rollout look like an unstable cluster.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->never();

    observeLeader($this->manager, 'mgr-1');
});

test('a single failover is not reported as instability', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->never();

    observeLeader($this->manager, 'mgr-1');
    observeLeader($this->manager, 'mgr-2');
});

test('a failover and the handover after it are still tolerated', function (): void {
    // Two changes inside a window is a transition. Warning here would fire on
    // every deploy, and a warning that fires on every deploy is ignored.
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->never();

    observeLeader($this->manager, 'mgr-1');
    observeLeader($this->manager, 'mgr-2');
    observeLeader($this->manager, 'mgr-3');
});

test('a third change inside one window is reported', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toContain('leadership is changing faster');
            expect($context['changes_observed'])->toBe(3);
            expect($context['window_seconds'])->toBe(60);
            expect($context['consequence'])->toContain('anti-flapping');

            return true;
        });

    observeLeader($this->manager, 'mgr-1');
    observeLeader($this->manager, 'mgr-2');
    observeLeader($this->manager, 'mgr-3');
    observeLeader($this->manager, 'mgr-4');
});

test('repeating the same leader is not a change at all', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->never();

    observeLeader($this->manager, 'mgr-1');

    for ($cycle = 0; $cycle < 20; $cycle++) {
        observeLeader($this->manager, 'mgr-2');
    }
});
