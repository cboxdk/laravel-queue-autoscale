<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

test('records pickup time derived from payload pushedAt', function (): void {
    $recorded = [];
    $store = new class($recorded) implements PickupTimeStoreContract
    {
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };

    $recorder = new PickupTimeRecorder($store);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['pushedAt' => microtime(true) - 2.0]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $recorder->handle(new JobProcessing('redis', $job));

    expect($recorded)->toHaveCount(1);
    expect($recorded[0]['connection'])->toBe('redis');
    expect($recorded[0]['queue'])->toBe('default');
    expect($recorded[0]['pickupSeconds'])->toBeGreaterThan(1.9)->toBeLessThan(2.1);
});

test('silently skips when pushedAt is absent from payload', function (): void {
    $recorded = [];
    $store = new class($recorded) implements PickupTimeStoreContract
    {
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };

    $recorder = new PickupTimeRecorder($store);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['id' => 'abc']);
    $job->shouldReceive('getQueue')->andReturn('default');

    $recorder->handle(new JobProcessing('redis', $job));

    expect($recorded)->toHaveCount(0);
});

test('uses default queue name when none provided', function (): void {
    $recorded = [];
    $store = new class($recorded) implements PickupTimeStoreContract
    {
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };

    $recorder = new PickupTimeRecorder($store);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['pushedAt' => microtime(true) - 1.0]);
    $job->shouldReceive('getQueue')->andReturn(null);

    $recorder->handle(new JobProcessing('redis', $job));

    expect($recorded[0]['queue'])->toBe('default');
});
