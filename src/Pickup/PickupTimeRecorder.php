<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Illuminate\Queue\Events\JobProcessing;

class PickupTimeRecorder
{
    public function __construct(
        private readonly PickupTimeStoreContract $store,
        private readonly PickupSampler $sampler,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $pushedAt = $payload['pushedAt'] ?? null;

        if (! is_numeric($pushedAt)) {
            return;
        }

        $queue = $event->job->getQueue() ?: 'default';

        // Sampled per queue, not per process: a group worker polls several at
        // once, and one shared counter let a busy queue set the probability
        // for the quiet queue beside it.
        if (! $this->sampler->shouldRecord($event->connectionName, $queue)) {
            return;
        }

        $now = microtime(true);
        $pickupSeconds = max(0.0, $now - (float) $pushedAt);

        $this->store->record(
            connection: $event->connectionName,
            queue: $queue,
            timestamp: $now,
            pickupSeconds: $pickupSeconds,
        );
    }
}
