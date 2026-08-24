<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;
use Illuminate\Support\Facades\Redis;

/**
 * Redis Cluster compatibility tests.
 *
 * These run against whichever Redis the environment provides: a single node
 * normally, or a real cluster when REDIS_CLUSTER_HOSTS_AND_PORTS is set (see the
 * cluster CI job). The same assertions must hold in both modes.
 */
beforeEach(function (): void {
    if (! getenv('REDIS_AVAILABLE')) {
        $this->markTestSkipped('Requires Redis - run with the redis group');
    }

    config()->set('app.name', 'Queue Autoscale Cluster Test');
    config()->set('app.env', 'testing');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default']);

    Redis::connection('default')->flushdb();
});

// A cluster client has no keyless PING, so the store must key-route it.
it('pings the coordination connection', function (): void {
    expect((new ClusterStore)->ping())->toBeTruthy();
})->group('redis');

// The atomic EMA update spans three keys that must share a slot on a cluster.
it('records spawn latency without crossslot', function (): void {
    $tracker = new EmaSpawnLatencyTracker;
    $config = new SpawnCompensationConfiguration(
        enabled: true,
        fallbackSeconds: 2.5,
        minSamples: 1,
        emaAlpha: 0.2,
    );

    $tracker->recordSpawn('worker-1', 'redis', 'default', $config);
    $tracker->recordFirstPickup('worker-1', microtime(true) + 1.0);

    expect($tracker->currentLatency('redis', 'default', $config))->toBeGreaterThan(0.0);
})->group('redis');

// The two-key fencing EVAL must write when the token holds and refuse it otherwise.
it('publishes a recommendation without crossslot and fences a stale token', function (): void {
    $store = new ClusterStore;

    expect($store->isLeader('manager-a'))->toBeTrue();

    $token = $store->leaderToken();
    expect($token)->toBeString()->not->toBe('');

    $recommendation = new ClusterRecommendation(
        managerId: 'manager-a',
        issuedAt: (int) round(microtime(true) * 1000),
        workloads: [ClusterRecommendation::queueWorkloadKey('redis', 'default') => 3],
        leaderId: 'manager-a',
        leaderToken: $token,
    );

    $store->publishRecommendation($recommendation);

    $stored = $store->recommendationFor('manager-a');
    expect($stored)->not->toBeNull();
    expect($stored->targetForQueue('redis', 'default'))->toBe(3);

    $stale = new ClusterRecommendation(
        managerId: 'manager-a',
        issuedAt: (int) round(microtime(true) * 1000),
        workloads: [ClusterRecommendation::queueWorkloadKey('redis', 'default') => 9],
        leaderId: 'manager-a',
        leaderToken: 'stale-token',
    );

    $store->publishRecommendation($stale);

    expect($store->recommendationFor('manager-a')->targetForQueue('redis', 'default'))->toBe(3);
})->group('redis');
