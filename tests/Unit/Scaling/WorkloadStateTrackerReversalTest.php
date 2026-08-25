<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\WorkloadStateTracker;
use Illuminate\Support\Carbon;

/**
 * The damping predicate the two single-host paths share.
 *
 * Both used to carry their own inline copy, and neither had a spec — the only
 * anti-flapping coverage in the suite drove the cluster leader's separate
 * implementation. Three copies of a rule this subtle is how the divergence bugs
 * start, so the decision lives in one testable place.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-24 09:00:00'));
    $this->tracker = new WorkloadStateTracker;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a scale-down reversing a recent scale-up is held', function (): void {
    $this->tracker->recordScale('redis:exports', 'up');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:exports', 'down', 60))->toBeTrue();
});

test('a scale-up is never held, whichever way the workload last moved', function (): void {
    $this->tracker->recordScale('redis:exports', 'down');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:exports', 'up', 60))->toBeFalse();
});

test('a same-direction scale-down is never held', function (): void {
    $this->tracker->recordScale('redis:exports', 'down');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:exports', 'down', 60))->toBeFalse();
});

test('a hold is never held', function (): void {
    $this->tracker->recordScale('redis:exports', 'up');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:exports', 'hold', 60))->toBeFalse();
});

test('a reversal is allowed once the window has elapsed', function (): void {
    $this->tracker->recordScale('redis:exports', 'up');

    Carbon::setTestNow(now()->addSeconds(61));

    expect($this->tracker->holdsReversal('redis:exports', 'down', 60))->toBeFalse();
});

test('an elapsed window forgets the direction rather than only ignoring it', function (): void {
    // The clear has to happen, not just the comparison: a direction left behind
    // would damp the NEXT reversal too, long after the move that caused it.
    $this->tracker->recordScale('redis:exports', 'up');

    Carbon::setTestNow(now()->addSeconds(61));
    $this->tracker->holdsReversal('redis:exports', 'down', 60);

    expect($this->tracker->lastDirection('redis:exports'))->toBeNull();
});

test('a workload that has never scaled is never held', function (): void {
    expect($this->tracker->holdsReversal('redis:exports', 'down', 60))->toBeFalse();
});

test('workloads are damped independently of each other', function (): void {
    $this->tracker->recordScale('redis:exports', 'up');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:imports', 'down', 60))->toBeFalse();
});

test('a scale-down following a recorded hold is not damped', function (): void {
    // Only a scale-UP opens the window. ClusterCooldown cannot store a 'hold'
    // at all — its remember() drops them — so treating one as something a
    // scale-down reverses would make the two implementations of one rule
    // disagree on an input only this one can be handed.
    $this->tracker->recordScale('redis:exports', 'hold');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:exports', 'down', 60))->toBeFalse();
});

test('consecutive scale-downs are never damped', function (): void {
    $this->tracker->recordScale('redis:exports', 'down');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:exports', 'down', 60))->toBeFalse();

    $this->tracker->recordScale('redis:exports', 'down');

    Carbon::setTestNow(now()->addSeconds(5));

    expect($this->tracker->holdsReversal('redis:exports', 'down', 60))->toBeFalse();
});
