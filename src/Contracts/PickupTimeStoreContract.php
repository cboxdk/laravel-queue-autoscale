<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface PickupTimeStoreContract
{
    public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void;

    /**
     * @return list<array{timestamp: float, pickup_seconds: float}>
     */
    public function recentSamples(string $connection, string $queue, int $windowSeconds): array;
}
