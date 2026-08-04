<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;
use Cbox\LaravelQueueAutoscale\Fuse\CacheFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Fuse\FuseState;
use Illuminate\Support\Facades\Event;

/**
 * A group is scaled as one unit under the group's name, but workers record
 * outcomes under the REAL queue they pulled the job from. Reading the group
 * name alone finds an empty window forever, so the fuse could never trip for
 * any grouped queue — enabled in config, silently inert in production.
 */
beforeEach(function (): void {
    Event::fake([FuseTripped::class]);
    config(['queue-autoscale.sla_defaults' => BalancedProfile::class]);

    $this->store = new CacheFailureWindowStore;
    $this->fuse = new FailureFuse($this->store);

    $this->group = GroupConfiguration::fromConfig('notifications', [
        'queues' => ['email', 'sms'],
        'connection' => 'redis',
        'overrides' => ['fuse' => ['min_samples' => 20, 'failure_threshold_percent' => 50.0]],
    ])->toScalingConfiguration();
});

test('trips on failures recorded against a member queue', function (): void {
    // The mail provider dies. Workers record under 'email'; the group is
    // evaluated as 'notifications'.
    foreach (range(1, 40) as $i) {
        $this->store->recordOutcome('redis', 'email', failed: $i <= 30, windowSeconds: 60);
    }

    $verdict = $this->fuse->evaluate($this->group);

    expect($verdict->state)->toBe(FuseState::Open)
        ->and($verdict->samples)->toBe(40)
        ->and($verdict->failureRate)->toBe(75.0);

    Event::assertDispatched(FuseTripped::class);
});

test('aggregates evidence across every member queue', function (): void {
    // Neither queue alone reaches min_samples; together they do.
    foreach (range(1, 12) as $ignored) {
        $this->store->recordOutcome('redis', 'email', failed: true, windowSeconds: 60);
        $this->store->recordOutcome('redis', 'sms', failed: true, windowSeconds: 60);
    }

    $verdict = $this->fuse->evaluate($this->group);

    expect($verdict->samples)->toBe(24)
        ->and($verdict->state)->toBe(FuseState::Open);
});

test('a healthy member dilutes a failing one, as it should', function (): void {
    // The group shares a worker pool, so half its work still succeeding means
    // scaling is not purely wasted — 33% is under the 50% threshold.
    foreach (range(1, 15) as $ignored) {
        $this->store->recordOutcome('redis', 'email', failed: true, windowSeconds: 60);
    }
    foreach (range(1, 30) as $ignored) {
        $this->store->recordOutcome('redis', 'sms', failed: false, windowSeconds: 60);
    }

    $verdict = $this->fuse->evaluate($this->group);

    expect($verdict->state)->toBe(FuseState::Closed)
        ->and(round($verdict->failureRate))->toBe(33.0);
});

test('ignores queues outside the group', function (): void {
    foreach (range(1, 40) as $ignored) {
        $this->store->recordOutcome('redis', 'unrelated', failed: true, windowSeconds: 60);
    }

    expect($this->fuse->evaluate($this->group)->state)->toBe(FuseState::Closed);
});

test('clears every member window on transition, not just the group name', function (): void {
    // A reset that missed member queues would leave the failures that tripped
    // the fuse in view, and the probe would re-trip before running a job.
    foreach (range(1, 40) as $ignored) {
        $this->store->recordOutcome('redis', 'email', failed: true, windowSeconds: 60);
        $this->store->recordOutcome('redis', 'sms', failed: true, windowSeconds: 60);
    }

    $this->fuse->evaluate($this->group);

    expect($this->store->currentWindow('redis', 'email', 60))->toBe(['total' => 0, 'failures' => 0])
        ->and($this->store->currentWindow('redis', 'sms', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('keeps fuse state under the group name, not a member queue', function (): void {
    foreach (range(1, 40) as $ignored) {
        $this->store->recordOutcome('redis', 'email', failed: true, windowSeconds: 60);
    }

    $this->fuse->evaluate($this->group);

    expect($this->store->readState('redis', 'notifications')['state'])->toBe('open')
        ->and($this->store->readState('redis', 'email'))->toBeNull();
});
