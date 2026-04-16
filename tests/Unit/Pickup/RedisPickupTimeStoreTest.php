<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Illuminate\Support\Facades\Redis;

test('records and retrieves pickup samples in order', function (): void {
    $now = (float) time();

    Redis::shouldReceive('lpush')->twice()->andReturn(1);
    Redis::shouldReceive('ltrim')->twice()->andReturn(true);

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

    expect($samples)->toHaveCount(2);
    expect(array_column($samples, 'pickup_seconds'))->toContain(1.5, 2.0);
});

test('caps storage at max_samples_per_queue', function (): void {
    $now = (float) time();

    Redis::shouldReceive('lpush')->times(5)->andReturn(1);
    Redis::shouldReceive('ltrim')->times(5)->andReturn(true);

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
    expect($samples)->toHaveCount(3);
});

test('filters samples outside window', function (): void {
    $now = (float) time();

    Redis::shouldReceive('lpush')->times(3)->andReturn(1);
    Redis::shouldReceive('ltrim')->times(3)->andReturn(true);

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

    Redis::shouldReceive('lpush')->twice()->andReturn(1);
    Redis::shouldReceive('ltrim')->twice()->andReturn(true);

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

    expect($store->recentSamples('redis', 'a', 60))->toHaveCount(1);
    expect($store->recentSamples('redis', 'b', 60))->toHaveCount(1);
});
