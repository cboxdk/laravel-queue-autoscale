<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\FailureClassifierContract;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Fuse\JobOutcomeRecorder;
use Cbox\LaravelQueueAutoscale\Fuse\NullFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryFailureWindowStore;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;

beforeEach(function (): void {
    $this->store = new InMemoryFailureWindowStore;

    $this->app->instance(FailureWindowStoreContract::class, $this->store);
    $this->app->forgetInstance(JobOutcomeRecorder::class);
});

function fakeJob(string $queue = 'default'): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('getQueue')->andReturn($queue);

    return $job;
}

test('records a processed job as a success', function (): void {
    event(new JobProcessed('redis', fakeJob('emails')));

    expect($this->store->recorded)->toHaveCount(1);
    expect($this->store->recorded[0]['failed'])->toBeFalse();
    expect($this->store->recorded[0]['queue'])->toBe('emails');
    expect($this->store->recorded[0]['connection'])->toBe('redis');
});

test('records a thrown job as a failure', function (): void {
    event(new JobExceptionOccurred('redis', fakeJob('emails'), new RuntimeException('downstream down')));

    expect($this->store->recorded)->toHaveCount(1);
    expect($this->store->recorded[0]['failed'])->toBeTrue();
});

test('counts failures from the first exception, not from retry exhaustion', function (): void {
    // JobFailed would only fire after tries are exhausted — three attempts too
    // late to keep the autoscaler from reacting to the resulting backlog.
    foreach (range(1, 3) as $ignored) {
        event(new JobExceptionOccurred('redis', fakeJob(), new RuntimeException('boom')));
    }

    expect($this->store->currentWindow('redis', 'default', 60))->toBe(['total' => 3, 'failures' => 3]);
});

test('uses the queue window size when recording', function (): void {
    config()->set('queue-autoscale.queues.slow', ['fuse' => ['window_seconds' => 300]]);

    event(new JobProcessed('redis', fakeJob('slow')));

    expect($this->store->recorded[0]['window_seconds'])->toBe(300);
});

test('ignores queues this package does not manage', function (): void {
    config()->set('queue-autoscale.excluded', ['horizon-*']);

    event(new JobProcessed('redis', fakeJob('horizon-notifications')));

    expect($this->store->recorded)->toBeEmpty();
});

test('drops an ignored exception entirely rather than counting it as a success', function (): void {
    // Counting it as a success would dilute the failure rate with an outcome
    // that says nothing about the dependency's health.
    config()->set('queue-autoscale.fuse.ignored_exceptions', [InvalidArgumentException::class]);
    $this->app->forgetInstance(FailureClassifierContract::class);
    $this->app->forgetInstance(JobOutcomeRecorder::class);

    event(new JobExceptionOccurred('redis', fakeJob(), new InvalidArgumentException('bad payload')));

    expect($this->store->recorded)->toBeEmpty();
    expect($this->store->currentWindow('redis', 'default', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('still records exceptions the classifier does not exempt', function (): void {
    config()->set('queue-autoscale.fuse.ignored_exceptions', [InvalidArgumentException::class]);
    $this->app->forgetInstance(FailureClassifierContract::class);
    $this->app->forgetInstance(JobOutcomeRecorder::class);

    event(new JobExceptionOccurred('redis', fakeJob(), new RuntimeException('downstream down')));

    expect($this->store->recorded)->toHaveCount(1);
    expect($this->store->recorded[0]['failed'])->toBeTrue();
});

test('a successful job is never routed through the classifier', function (): void {
    // Only exceptions are classified; a completed job is unambiguous.
    config()->set('queue-autoscale.fuse.ignored_exceptions', [Throwable::class]);
    $this->app->forgetInstance(FailureClassifierContract::class);
    $this->app->forgetInstance(JobOutcomeRecorder::class);

    event(new JobProcessed('redis', fakeJob()));

    expect($this->store->recorded)->toHaveCount(1);
    expect($this->store->recorded[0]['failed'])->toBeFalse();
});

test('binds the null store when the fuse is disabled globally', function (): void {
    // The provider swaps in the null store, so outcomes are dropped at source
    // rather than filling the cache with counters nobody reads.
    config()->set('queue-autoscale.fuse.enabled', false);
    $this->app->forgetInstance(FailureWindowStoreContract::class);
    $this->app->forgetInstance(JobOutcomeRecorder::class);

    $store = $this->app->make(FailureWindowStoreContract::class);

    expect($store)->toBeInstanceOf(NullFailureWindowStore::class);
});
