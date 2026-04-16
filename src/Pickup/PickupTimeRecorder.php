<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Illuminate\Queue\Events\JobProcessing;

final class PickupTimeRecorder
{
    public function __construct(
        private readonly PickupTimeStoreContract $store,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $pushedAt = $payload['pushedAt'] ?? null;

        if (! is_numeric($pushedAt)) {
            return;
        }

        $now = microtime(true);
        $pickupSeconds = max(0.0, $now - (float) $pushedAt);
        $queue = $event->job->getQueue() ?: 'default';

        $this->store->record(
            connection: $event->connectionName,
            queue: $queue,
            timestamp: $now,
            pickupSeconds: $pickupSeconds,
        );
    }
}
