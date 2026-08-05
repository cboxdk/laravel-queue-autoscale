<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Pickup\PickupSampler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Every SLA decision is derived from a p95 over recorded pickup times, so a
 * missing listener registration does not fail loudly — it makes the autoscaler
 * quietly fall back to age-of-oldest for every queue, forever.
 *
 * This was previously asserted structurally, by walking Event::getListeners()
 * looking for the class name. Laravel wraps listeners in closures, so the walk
 * never matched and the spec fell through to `count($listeners) > 0` — true
 * whatever this package does, because Laravel registers its own JobProcessing
 * listeners. Deleting the registration entirely went unnoticed.
 *
 * Dispatching the event and checking a pickup was recorded tests the thing
 * that matters and cannot be satisfied by a wrapper.
 */
test('a processed job records its pickup time through the registered listener', function (): void {
    $recorded = [];

    app()->instance(PickupTimeStoreContract::class, new class($recorded) implements PickupTimeStoreContract
    {
        /**
         * @param  array<int, array<string, mixed>>  $recorded
         */
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    });

    // Sampling off, so the assertion is about registration and nothing else.
    app()->instance(PickupSampler::class, new PickupSampler(enabled: false));

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['pushedAt' => microtime(true) - 3.0]);
    $job->shouldReceive('getQueue')->andReturn('exports');

    event(new JobProcessing('redis', $job));

    expect($recorded)->toHaveCount(1)
        ->and($recorded[0]['connection'])->toBe('redis')
        ->and($recorded[0]['queue'])->toBe('exports')
        ->and($recorded[0]['pickupSeconds'])->toBeGreaterThan(2.9);
});
