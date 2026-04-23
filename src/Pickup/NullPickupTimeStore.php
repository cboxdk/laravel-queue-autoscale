<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;

final class NullPickupTimeStore implements PickupTimeStoreContract
{
    public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
    {
        // Intentionally disabled. In non-Redis mode we fall back to oldest-age
        // signals instead of persisting cross-process pickup samples.
    }

    public function recentSamples(string $connection, string $queue, int $windowSeconds): array
    {
        return [];
    }
}
