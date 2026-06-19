<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers\SpawnLatency;

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

final class EmaSpawnLatencyTracker implements SpawnLatencyTrackerContract
{
    private const float MIN_LATENCY = 0.1;

    private const float MAX_LATENCY = 30.0;

    private const int PENDING_TTL = 300;

    public function __construct(
        private readonly float $alpha = 0.2,
    ) {}

    /**
     * Record a spawn event. The per-queue ema_alpha from $config is stored in the
     * pending payload so that recordFirstPickup() — which runs in the spawned worker
     * process — can apply the correct smoothing factor without accessing the config.
     */
    public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void
    {
        $payload = json_encode([
            'ts' => microtime(true),
            'connection' => $connection,
            'queue' => $queue,
            'alpha' => $config->emaAlpha,
        ], JSON_THROW_ON_ERROR);

        Redis::setex($this->pendingKey($workerId), self::PENDING_TTL, $payload);
    }

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void
    {
        $raw = Redis::get($this->pendingKey($workerId));

        if (! is_string($raw)) {
            return;
        }

        /** @var array{ts: float, connection: string, queue: string, alpha?: float} $payload */
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

        $rawLatency = $pickupTimestamp - (float) $payload['ts'];
        $latency = max(self::MIN_LATENCY, min($rawLatency, self::MAX_LATENCY));

        $emaKey = $this->emaKey($payload['connection'], $payload['queue']);
        $countKey = $this->countKey($payload['connection'], $payload['queue']);

        // Use the per-queue alpha stored at spawn time, falling back to the
        // constructor default if the field is absent (e.g. legacy payloads
        // recorded before per-queue alpha support was added).
        $storedAlpha = $payload['alpha'] ?? null;
        $alpha = ($storedAlpha !== null && $storedAlpha > 0.0 && $storedAlpha <= 1.0)
            ? $storedAlpha
            : $this->alpha;

        $this->recordLatencyAtomically(
            pendingKey: $this->pendingKey($workerId),
            emaKey: $emaKey,
            countKey: $countKey,
            latency: $latency,
            alpha: $alpha,
        );
    }

    /**
     * Returns the current EMA spawn latency for the queue.
     *
     * Uses $config->fallbackSeconds and $config->minSamples so that per-queue
     * overrides are honoured — the constructor defaults serve as a global baseline
     * only when no QueueConfiguration is available.
     */
    public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
    {
        $rawCount = Redis::get($this->countKey($connection, $queue));
        $count = is_numeric($rawCount) ? (int) $rawCount : 0;

        if ($count < $config->minSamples) {
            return $config->fallbackSeconds;
        }

        $ema = Redis::get($this->emaKey($connection, $queue));

        return is_numeric($ema) ? (float) $ema : $config->fallbackSeconds;
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

    private function recordLatencyAtomically(
        string $pendingKey,
        string $emaKey,
        string $countKey,
        float $latency,
        float $alpha,
    ): void {
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == false then
    return 0
end

local current = tonumber(redis.call('get', KEYS[2]))
local latency = tonumber(ARGV[1])
local alpha = tonumber(ARGV[2])
local next = latency

if current ~= nil then
    next = (alpha * latency) + ((1 - alpha) * current)
end

redis.call('set', KEYS[2], tostring(next))
redis.call('incr', KEYS[3])
redis.call('del', KEYS[1])

return 1
LUA;

        $redis = $this->redis();

        if ($redis instanceof PhpRedisConnection) {
            $redis->command('eval', [$script, [$pendingKey, $emaKey, $countKey, (string) $latency, (string) $alpha], 3]);

            return;
        }

        $redis->command('eval', [$script, 3, $pendingKey, $emaKey, $countKey, (string) $latency, (string) $alpha]);
    }

    private function redis(): Connection
    {
        return Redis::connection();
    }
}
