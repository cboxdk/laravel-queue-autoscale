<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;

interface SpawnLatencyTrackerContract
{
    /**
     * Record a spawn event. The per-queue ema_alpha from $config is stored in the
     * pending payload so it is available when recordFirstPickup() fires in the
     * spawned worker process.
     */
    public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void;

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void;

    /**
     * Current spawn latency in seconds for the given queue.
     *
     * Uses $config->fallbackSeconds and $config->minSamples instead of the
     * tracker's constructor defaults so per-queue overrides are honoured.
     */
    public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float;
}
