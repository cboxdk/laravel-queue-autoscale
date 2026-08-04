<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Captures every command the store sends, one entry per round trip.
 *
 * Push and trim are issued as a single Lua call, so asserting on the facade's
 * lpush/ltrim no longer describes what the store does. Recording the raw
 * command lets the specs below pin both what is sent and how many trips it
 * takes.
 *
 * @param  array<int, array<int, mixed>>  $roundTrips
 */
function captureRedisCommands(array &$roundTrips): void
{
    $connection = Mockery::mock(Connection::class);

    $connection->shouldReceive('command')->andReturnUsing(function (string $command, array $args) use (&$roundTrips): int {
        $roundTrips[] = [$command, $args];

        return 1;
    });

    Redis::shouldReceive('connection')->andReturn($connection);
}

/**
 * The eval argument order differs between the PhpRedis and Predis connections,
 * so the specs read the script arguments through this rather than by index.
 *
 * @param  array<int, mixed>  $roundTrip
 * @return array{script: string, key: string, entry: string, trimTo: int}
 */
function evalArguments(array $roundTrip): array
{
    /** @var array<int, mixed> $args */
    $args = $roundTrip[1];

    $script = is_string($args[0]) ? $args[0] : '';

    // Predis takes (script, numKeys, key, ...argv); PhpRedis takes
    // (script, [key, ...argv], numKeys).
    $flat = is_array($args[1]) ? array_values($args[1]) : array_slice($args, 2);

    return [
        'script' => $script,
        'key' => is_string($flat[0]) ? $flat[0] : '',
        'entry' => is_string($flat[1]) ? $flat[1] : '',
        'trimTo' => is_numeric($flat[2]) ? (int) $flat[2] : -1,
    ];
}

test('records and retrieves pickup samples in order', function (): void {
    $now = (float) time();
    $roundTrips = [];
    captureRedisCommands($roundTrips);

    Redis::shouldReceive('lrange')
        ->once()
        ->with('autoscale:pickup:redis:default', 0, -1)
        ->andReturn([
            sprintf('%.6f|%.6f', $now, 2.0),
            sprintf('%.6f|%.6f', $now - 1, 1.5),
        ]);

    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    $store->record('redis', 'default', $now - 1, 1.5);
    $store->record('redis', 'default', $now, 2.0);

    $samples = $store->recentSamples('redis', 'default', 60);

    expect($roundTrips)->toHaveCount(2);
    expect($samples)->toHaveCount(2);
    expect(array_column($samples, 'pickup_seconds'))->toContain(1.5, 2.0);
});

test('a recorded pickup costs exactly one round trip', function (): void {
    // Issued separately, push and trim are two round trips on the hot path of
    // every job the application runs. This is the whole optimisation.
    $roundTrips = [];
    captureRedisCommands($roundTrips);

    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    $store->record('redis', 'default', (float) time(), 1.5);

    expect($roundTrips)->toHaveCount(1)
        ->and($roundTrips[0][0])->toBe('eval');

    $sent = evalArguments($roundTrips[0]);

    expect($sent['script'])->toContain('lpush')
        ->and($sent['script'])->toContain('ltrim');
});

test('trims to the configured sample cap', function (): void {
    $roundTrips = [];
    captureRedisCommands($roundTrips);

    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 3);

    $store->record('redis', 'default', (float) time(), 1.0);

    // LTRIM bounds are inclusive, so keeping three entries trims to index 2.
    expect(evalArguments($roundTrips[0])['trimTo'])->toBe(2);
});

test('caps storage at max_samples_per_queue', function (): void {
    $now = (float) time();
    $roundTrips = [];
    captureRedisCommands($roundTrips);

    // Only 3 entries returned — simulating that ltrim has kept max 3
    Redis::shouldReceive('lrange')
        ->once()
        ->with('autoscale:pickup:redis:default', 0, -1)
        ->andReturn([
            sprintf('%.6f|%.6f', $now, 4.0),
            sprintf('%.6f|%.6f', $now - 1, 3.0),
            sprintf('%.6f|%.6f', $now - 2, 2.0),
        ]);

    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 3);

    for ($i = 0; $i < 5; $i++) {
        $store->record('redis', 'default', $now - (4 - $i), (float) $i);
    }

    $samples = $store->recentSamples('redis', 'default', 60);

    expect($roundTrips)->toHaveCount(5);
    expect($samples)->toHaveCount(3);
});

test('filters samples outside window', function (): void {
    $now = (float) time();
    $roundTrips = [];
    captureRedisCommands($roundTrips);

    Redis::shouldReceive('lrange')
        ->once()
        ->with('autoscale:pickup:redis:default', 0, -1)
        ->andReturn([
            sprintf('%.6f|%.6f', $now, 3.0),
            sprintf('%.6f|%.6f', $now - 30, 2.0),
            sprintf('%.6f|%.6f', $now - 500, 1.0),
        ]);

    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    $store->record('redis', 'default', $now - 500, 1.0);
    $store->record('redis', 'default', $now - 30, 2.0);
    $store->record('redis', 'default', $now, 3.0);

    $samples = $store->recentSamples('redis', 'default', 60);

    expect($samples)->toHaveCount(2);
    expect(array_column($samples, 'pickup_seconds'))->toContain(2.0, 3.0);
});

test('returns empty list for queue with no recorded samples', function (): void {
    Redis::shouldReceive('lrange')
        ->once()
        ->with('autoscale:pickup:redis:empty', 0, -1)
        ->andReturn([]);

    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    expect($store->recentSamples('redis', 'empty', 60))->toBe([]);
});

test('different queues have isolated storage', function (): void {
    $now = (float) time();
    $roundTrips = [];
    captureRedisCommands($roundTrips);

    Redis::shouldReceive('lrange')
        ->once()
        ->with('autoscale:pickup:redis:a', 0, -1)
        ->andReturn([
            sprintf('%.6f|%.6f', $now, 1.0),
        ]);

    Redis::shouldReceive('lrange')
        ->once()
        ->with('autoscale:pickup:redis:b', 0, -1)
        ->andReturn([
            sprintf('%.6f|%.6f', $now, 2.0),
        ]);

    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    $store->record('redis', 'a', $now, 1.0);
    $store->record('redis', 'b', $now, 2.0);

    expect(evalArguments($roundTrips[0])['key'])->toBe('autoscale:pickup:redis:a')
        ->and(evalArguments($roundTrips[1])['key'])->toBe('autoscale:pickup:redis:b');
    expect($store->recentSamples('redis', 'a', 60))->toHaveCount(1);
    expect($store->recentSamples('redis', 'b', 60))->toHaveCount(1);
});
