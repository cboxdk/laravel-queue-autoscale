<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Illuminate\Support\Facades\Redis;

final class RedisPickupTimeStore implements PickupTimeStoreContract
{
    public function __construct(
        private readonly int $maxSamplesPerQueue = 1000,
    ) {}

    public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
    {
        $key = $this->key($connection, $queue);
        $entry = sprintf('%.6f|%.6f', $timestamp, $pickupSeconds);

        Redis::lpush($key, $entry);
        Redis::ltrim($key, 0, $this->maxSamplesPerQueue - 1);
    }

    public function recentSamples(string $connection, string $queue, int $windowSeconds): array
    {
        $key = $this->key($connection, $queue);
        /** @var list<string> $entries */
        $entries = Redis::lrange($key, 0, -1);
        $cutoff = microtime(true) - $windowSeconds;

        $samples = [];

        foreach ($entries as $entry) {
            $parts = explode('|', $entry, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $ts = (float) $parts[0];

            if ($ts < $cutoff) {
                continue;
            }

            $samples[] = [
                'timestamp' => $ts,
                'pickup_seconds' => (float) $parts[1],
            ];
        }

        return $samples;
    }

    private function key(string $connection, string $queue): string
    {
        return sprintf('autoscale:pickup:%s:%s', $connection, $queue);
    }
}
