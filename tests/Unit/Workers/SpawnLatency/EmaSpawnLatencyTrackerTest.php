<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;
use Illuminate\Support\Facades\Redis;

// Returns a closure-based in-memory Redis fake for use with andReturnUsing.
// The $store array is passed by reference so all closures share state.
function buildRedisStore(): array
{
    return [];
}

function makeSpawnConfig(float $fallback = 2.5, int $minSamples = 5, float $alpha = 0.2): SpawnCompensationConfiguration
{
    return new SpawnCompensationConfiguration(
        enabled: true,
        fallbackSeconds: $fallback,
        minSamples: $minSamples,
        emaAlpha: $alpha,
    );
}

test('returns fallback when fewer than 5 samples recorded', function (): void {
    Redis::shouldReceive('get')
        ->with('autoscale:spawn:count:redis:default')
        ->once()
        ->andReturn(null);

    $tracker = new EmaSpawnLatencyTracker(alpha: 0.2);
    $config = makeSpawnConfig(fallback: 2.5, minSamples: 5);

    expect($tracker->currentLatency('redis', 'default', $config))->toBe(2.5);
});

test('converges toward true latency after multiple samples', function (): void {
    // Use an in-memory store to simulate Redis state across recordSpawn / recordFirstPickup calls.
    $store = [];
    $knownSpawnTs = microtime(true) - 1.0;

    Redis::shouldReceive('setex')
        ->times(20)
        ->andReturnUsing(function (string $key, int $ttl, string $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

    Redis::shouldReceive('get')
        ->andReturnUsing(function (string $key) use (&$store, $knownSpawnTs): mixed {
            // For pending keys, return a payload with our controlled timestamp and alpha.
            if (str_starts_with($key, 'autoscale:spawn:pending:')) {
                if (! array_key_exists($key, $store)) {
                    return null;
                }

                // Override the ts field with a known value so latency is deterministic.
                return json_encode([
                    'ts' => $knownSpawnTs,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'alpha' => 0.2,
                ], JSON_THROW_ON_ERROR);
            }

            return $store[$key] ?? null;
        });

    Redis::shouldReceive('set')
        ->andReturnUsing(function (string $key, string $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

    Redis::shouldReceive('incr')
        ->andReturnUsing(function (string $key) use (&$store): int {
            $store[$key] = (string) ((int) ($store[$key] ?? 0) + 1);

            return (int) $store[$key];
        });

    Redis::shouldReceive('del')
        ->andReturnUsing(function (string $key) use (&$store): int {
            unset($store[$key]);

            return 1;
        });

    $tracker = new EmaSpawnLatencyTracker(alpha: 0.2);
    $config = makeSpawnConfig(fallback: 10.0, minSamples: 5, alpha: 0.2);

    $pickupTs = $knownSpawnTs + 1.0;

    for ($i = 0; $i < 20; $i++) {
        $tracker->recordSpawn("w{$i}", 'redis', 'default', $config);
        $tracker->recordFirstPickup("w{$i}", $pickupTs);
    }

    $latency = $tracker->currentLatency('redis', 'default', $config);
    expect($latency)->toBeGreaterThan(0.9)->toBeLessThan(1.1);
});

test('ignores pickup for unknown worker id', function (): void {
    Redis::shouldReceive('get')
        ->with('autoscale:spawn:pending:nonexistent-worker')
        ->once()
        ->andReturn(null);

    Redis::shouldReceive('get')
        ->with('autoscale:spawn:count:redis:default')
        ->once()
        ->andReturn(null);

    $tracker = new EmaSpawnLatencyTracker(alpha: 0.2);
    $config = makeSpawnConfig(fallback: 2.5, minSamples: 5);

    $tracker->recordFirstPickup('nonexistent-worker', microtime(true));

    expect($tracker->currentLatency('redis', 'default', $config))->toBe(2.5);
});

test('clamps extreme latencies into safe bounds', function (): void {
    $store = [];
    // Use a spawn ts 500 seconds ago so raw latency would be ~500s (well above 30s cap).
    $ancientSpawnTs = microtime(true) - 500.0;

    Redis::shouldReceive('setex')
        ->times(3)
        ->andReturnUsing(function (string $key, int $ttl, string $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

    Redis::shouldReceive('get')
        ->andReturnUsing(function (string $key) use (&$store, $ancientSpawnTs): mixed {
            if (str_starts_with($key, 'autoscale:spawn:pending:')) {
                if (! array_key_exists($key, $store)) {
                    return null;
                }

                return json_encode([
                    'ts' => $ancientSpawnTs,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'alpha' => 1.0,
                ], JSON_THROW_ON_ERROR);
            }

            return $store[$key] ?? null;
        });

    Redis::shouldReceive('set')
        ->andReturnUsing(function (string $key, string $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

    Redis::shouldReceive('incr')
        ->andReturnUsing(function (string $key) use (&$store): int {
            $store[$key] = (string) ((int) ($store[$key] ?? 0) + 1);

            return (int) $store[$key];
        });

    Redis::shouldReceive('del')
        ->andReturnUsing(function (string $key) use (&$store): int {
            unset($store[$key]);

            return 1;
        });

    // minSamples=1 and alpha=1.0 so the EMA becomes the last sample immediately.
    $tracker = new EmaSpawnLatencyTracker(alpha: 1.0);
    $config = makeSpawnConfig(fallback: 2.5, minSamples: 1, alpha: 1.0);

    $pickupTs = $ancientSpawnTs + 500.0;

    for ($i = 0; $i < 3; $i++) {
        $tracker->recordSpawn("w{$i}", 'redis', 'default', $config);
        $tracker->recordFirstPickup("w{$i}", $pickupTs);
    }

    expect($tracker->currentLatency('redis', 'default', $config))->toBeLessThanOrEqual(30.0);
});

test('isolates queues from one another', function (): void {
    $store = [];
    $spawnTs = microtime(true) - 1.0;
    $pickupTs = $spawnTs + 1.0;

    Redis::shouldReceive('setex')
        ->times(10)
        ->andReturnUsing(function (string $key, int $ttl, string $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

    Redis::shouldReceive('get')
        ->andReturnUsing(function (string $key) use (&$store, $spawnTs): mixed {
            if (str_starts_with($key, 'autoscale:spawn:pending:')) {
                if (! array_key_exists($key, $store)) {
                    return null;
                }

                return json_encode([
                    'ts' => $spawnTs,
                    'connection' => 'redis',
                    'queue' => 'queue-a',
                    'alpha' => 0.2,
                ], JSON_THROW_ON_ERROR);
            }

            return $store[$key] ?? null;
        });

    Redis::shouldReceive('set')
        ->andReturnUsing(function (string $key, string $value) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

    Redis::shouldReceive('incr')
        ->andReturnUsing(function (string $key) use (&$store): int {
            $store[$key] = (string) ((int) ($store[$key] ?? 0) + 1);

            return (int) $store[$key];
        });

    Redis::shouldReceive('del')
        ->andReturnUsing(function (string $key) use (&$store): int {
            unset($store[$key]);

            return 1;
        });

    $tracker = new EmaSpawnLatencyTracker(alpha: 0.2);
    $config = makeSpawnConfig(fallback: 2.5, minSamples: 5, alpha: 0.2);
    $configB = makeSpawnConfig(fallback: 2.5, minSamples: 5, alpha: 0.2);

    for ($i = 0; $i < 10; $i++) {
        $tracker->recordSpawn("a{$i}", 'redis', 'queue-a', $config);
        $tracker->recordFirstPickup("a{$i}", $pickupTs);
    }

    // queue-b has no samples — count key absent → falls back.
    expect($tracker->currentLatency('redis', 'queue-b', $configB))->toBe(2.5);
});
