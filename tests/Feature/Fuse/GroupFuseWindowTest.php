<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Fuse\CacheFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Fuse\FuseState;
use Cbox\LaravelQueueAutoscale\Fuse\JobOutcomeRecorder;

/**
 * Workers record a job's outcome under the real queue name; the manager
 * evaluates the group and reads those counters back with the GROUP's fuse
 * window. The bucket number is intdiv(time, window) * window and it is part of
 * the cache key, so a group whose window differs from its members' produced
 * two disjoint sets of keys — counters written where nothing looked, buckets
 * read that were always empty, and a group fuse that could never trip however
 * badly the dependency was failing.
 */
beforeEach(function (): void {
    config()->set('queue-autoscale.fuse.store', 'cache');
    config()->set('queue-autoscale.groups.notifications', [
        'queues' => ['email', 'sms'],
        'connection' => 'redis',
        // Deliberately different from what the members resolve to.
        'overrides' => ['fuse' => ['enabled' => true, 'window_seconds' => 120, 'min_samples' => 4]],
    ]);
});

test('a member queue records into the bucket the group reads', function (): void {
    $group = GroupConfiguration::allFromConfig()['notifications'];
    $store = app(FailureWindowStoreContract::class);

    expect($group->fuse->windowSeconds)->toBe(120);

    // Record failures the way a worker does, under the real queue name.
    $recorder = app(JobOutcomeRecorder::class);
    $method = new ReflectionMethod($recorder, 'windowSecondsFor');

    expect($method->invoke($recorder, 'redis', 'email'))->toBe(
        $group->fuse->windowSeconds,
        'A grouped queue must record with the window the group reads with',
    );
});

test('a group fuse can actually trip on its members failures', function (): void {
    // The clock is pinned to a moment where the two bucket sizes DISAGREE:
    // intdiv(90, 60) * 60 = 60, intdiv(90, 120) * 120 = 0. Without pinning,
    // the two happen to coincide for half of every two-minute period and the
    // spec would pass against the bug roughly half the time.
    $store = new CacheFailureWindowStore(clock: fn (): float => 90.0);
    app()->instance(FailureWindowStoreContract::class, $store);

    $group = GroupConfiguration::allFromConfig()['notifications'];
    $recorder = app(JobOutcomeRecorder::class);
    $window = (new ReflectionMethod($recorder, 'windowSecondsFor'))->invoke($recorder, 'redis', 'email');

    foreach (range(1, 6) as $i) {
        $store->recordOutcome('redis', 'email', failed: true, windowSeconds: $window);
    }

    $verdict = (new FailureFuse($store, clock: fn (): float => 90.0))
        ->evaluate($group->toScalingConfiguration());

    expect($verdict->state)->toBe(FuseState::Open);
});

test('an ungrouped queue still uses its own window', function (): void {
    $recorder = app(JobOutcomeRecorder::class);
    $method = new ReflectionMethod($recorder, 'windowSecondsFor');

    expect($method->invoke($recorder, 'redis', 'standalone'))->toBe(60);
});
