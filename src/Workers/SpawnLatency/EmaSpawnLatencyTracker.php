<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers\SpawnLatency;

use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Illuminate\Support\Facades\Redis;

final class EmaSpawnLatencyTracker implements SpawnLatencyTrackerContract
{
    private const float MIN_LATENCY = 0.1;

    private const float MAX_LATENCY = 30.0;

    private const int PENDING_TTL = 300;

    public function __construct(
        private readonly float $fallbackSeconds = 2.0,
        private readonly int $minSamples = 5,
        private readonly float $alpha = 0.2,
    ) {}

    public function recordSpawn(string $workerId, string $connection, string $queue): void
    {
        $payload = json_encode([
            'ts' => microtime(true),
            'connection' => $connection,
            'queue' => $queue,
        ], JSON_THROW_ON_ERROR);

        Redis::setex($this->pendingKey($workerId), self::PENDING_TTL, $payload);
    }

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void
    {
        $raw = Redis::get($this->pendingKey($workerId));

        if (! is_string($raw)) {
            return;
        }

        /** @var array{ts: float, connection: string, queue: string} $payload */
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

        $rawLatency = $pickupTimestamp - (float) $payload['ts'];
        $latency = max(self::MIN_LATENCY, min($rawLatency, self::MAX_LATENCY));

        $emaKey = $this->emaKey($payload['connection'], $payload['queue']);
        $countKey = $this->countKey($payload['connection'], $payload['queue']);

        $currentEma = Redis::get($emaKey);
        $newEma = is_numeric($currentEma)
            ? ($this->alpha * $latency) + ((1 - $this->alpha) * (float) $currentEma)
            : $latency;

        Redis::set($emaKey, (string) $newEma);
        Redis::incr($countKey);
        Redis::del($this->pendingKey($workerId));
    }

    public function currentLatency(string $connection, string $queue): float
    {
        $rawCount = Redis::get($this->countKey($connection, $queue));
        $count = is_numeric($rawCount) ? (int) $rawCount : 0;

        if ($count < $this->minSamples) {
            return $this->fallbackSeconds;
        }

        $ema = Redis::get($this->emaKey($connection, $queue));

        return is_numeric($ema) ? (float) $ema : $this->fallbackSeconds;
    }

    private function pendingKey(string $workerId): string
    {
        return sprintf('autoscale:spawn:pending:%s', $workerId);
    }

    private function emaKey(string $connection, string $queue): string
    {
        return sprintf('autoscale:spawn:ema:%s:%s', $connection, $queue);
    }

    private function countKey(string $connection, string $queue): string
    {
        return sprintf('autoscale:spawn:count:%s:%s', $connection, $queue);
    }
}
