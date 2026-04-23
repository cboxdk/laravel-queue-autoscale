<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers\SpawnLatency;

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;

final class NullSpawnLatencyTracker implements SpawnLatencyTrackerContract
{
    public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void
    {
        // No-op in non-Redis mode.
    }

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void
    {
        // No-op in non-Redis mode.
    }

    public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
    {
        return $config->fallbackSeconds;
    }
}
