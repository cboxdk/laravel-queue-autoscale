<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\FuseConfiguration;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Fuse\FuseState;
use Cbox\LaravelQueueAutoscale\Fuse\NullFailureWindowStore;

beforeEach(function (): void {
    $this->store = new NullFailureWindowStore;
});

test('drops recorded outcomes', function (): void {
    $this->store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);
    $this->store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);

    expect($this->store->currentWindow('redis', 'default', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('never reports a persisted state', function (): void {
    $this->store->writeState('redis', 'default', 'open', 123.0);

    expect($this->store->readState('redis', 'default'))->toBeNull();
});

test('resetting is harmless', function (): void {
    $this->store->resetWindow('redis', 'default', 60);

    expect($this->store->currentWindow('redis', 'default', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('a fuse backed by it can never trip, however bad things get', function (): void {
    $fuse = new FailureFuse($this->store);
    $config = makeQueueConfig(['fuse' => ['minSamples' => 1, 'failureThresholdPercent' => 1.0]]);

    foreach (range(1, 500) as $ignored) {
        $this->store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);
    }

    $verdict = $fuse->evaluate($config);

    expect($verdict->state)->toBe(FuseState::Closed)
        ->and($verdict->workerCeiling(1))->toBeNull();
});

test('satisfies the same contract shape as the cache store', function (): void {
    // Both are wired behind the same binding, so a divergent return shape
    // would only surface at runtime in whichever mode is not under test.
    $config = new FuseConfiguration(true, 50.0, 20, 60, 60);
    $window = $this->store->currentWindow('redis', 'default', $config->windowSeconds);

    expect($window)->toHaveKeys(['total', 'failures'])
        ->and($window['total'])->toBeInt()
        ->and($window['failures'])->toBeInt();
});
