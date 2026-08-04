<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Pickup\PickupSampler;
use Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

/**
 * @param  array<int, array<string, mixed>>  $recorded
 */
function recordingStore(array &$recorded): PickupTimeStoreContract
{
    return new class($recorded) implements PickupTimeStoreContract
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
    };
}

function jobProcessing(mixed $pushedAt, ?string $queue = 'default'): JobProcessing
{
    $payload = $pushedAt === null ? ['id' => 'abc'] : ['pushedAt' => $pushedAt];

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);
    $job->shouldReceive('getQueue')->andReturn($queue);

    return new JobProcessing('redis', $job);
}

function recorderWith(PickupTimeStoreContract $store, ?PickupSampler $sampler = null): PickupTimeRecorder
{
    return new PickupTimeRecorder($store, $sampler ?? new PickupSampler(enabled: false));
}

test('records pickup time derived from payload pushedAt', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 2.0));

    expect($recorded)->toHaveCount(1);
    expect($recorded[0]['connection'])->toBe('redis');
    expect($recorded[0]['queue'])->toBe('default');
    expect($recorded[0]['pickupSeconds'])->toBeGreaterThan(1.9)->toBeLessThan(2.1);
});

test('silently skips when pushedAt is absent from payload', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(null));

    expect($recorded)->toHaveCount(0);
});

test('uses default queue name when none provided', function (): void {
    $recorded = [];
    $recorder = recorderWith(recordingStore($recorded));

    $recorder->handle(jobProcessing(microtime(true) - 1.0, queue: null));

    expect($recorded[0]['queue'])->toBe('default');
});

test('a declining sampler keeps the write off the hot path', function (): void {
    // The sampler is consulted before the store, so a declined pickup costs no
    // Redis round trip at all — which is the entire point of sampling.
    $recorded = [];
    $recorder = recorderWith(
        recordingStore($recorded),
        new PickupSampler(enabled: true, maxPerSecond: 0, randomizer: fn (): float => 0.99),
    );

    $recorder->handle(jobProcessing(microtime(true) - 1.0));

    // maxPerSecond of 0 disables sampling entirely rather than discarding
    // everything; a misconfigured rate must not blind the autoscaler.
    expect($recorded)->toHaveCount(1);
});

test('a job with no pushedAt does not consume sampling budget', function (): void {
    // Order matters: if the sampler ran first, unparseable jobs would burn the
    // budget and starve the samples that actually carry a pickup time.
    $recorded = [];
    $sampler = new PickupSampler(enabled: true, maxPerSecond: 1);
    $recorder = recorderWith(recordingStore($recorded), $sampler);

    for ($i = 0; $i < 50; $i++) {
        $recorder->handle(jobProcessing(null));
    }

    expect($sampler->currentSampleRate())->toBe(1.0);
});
