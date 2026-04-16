<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface SpawnLatencyTrackerContract
{
    public function recordSpawn(string $workerId, string $connection, string $queue): void;

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void;

    /** Current spawn latency in seconds for the given queue. */
    public function currentLatency(string $connection, string $queue): float;
}
