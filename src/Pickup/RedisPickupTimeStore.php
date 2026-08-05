<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

class RedisPickupTimeStore implements PickupTimeStoreContract
{
    public function __construct(
        private readonly int $maxSamplesPerQueue = 1000,
    ) {}

    public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
    {
        $key = $this->key($connection, $queue);
        $entry = sprintf('%.6f|%.6f', $timestamp, $pickupSeconds);
        $trimTo = $this->maxSamplesPerQueue - 1;

        // Push and trim travel together. Issued as two commands they are two
        // round trips on the hot path of every job the application runs,
        // against the same Redis the queue itself is using — and they leave a
        // window in which the list is longer than the configured cap.
        $script = <<<'LUA'
redis.call('lpush', KEYS[1], ARGV[1])
redis.call('ltrim', KEYS[1], 0, tonumber(ARGV[2]))

return 1
LUA;

        $redis = $this->redis();

        $redis instanceof PhpRedisConnection
            ? $redis->command('eval', [$script, [$key, $entry, $trimTo], 1])
            : $redis->command('eval', [$script, 1, $key, $entry, $trimTo]);
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

    private function redis(): Connection
    {
        return Redis::connection();
    }
}
