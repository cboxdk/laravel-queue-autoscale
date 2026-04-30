<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;

/**
 * Build a sample decision array for testing.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function makeDecision(array $overrides = []): array
{
    return array_merge([
        'workload_key' => 'queue:redis:default',
        'type' => 'queue',
        'connection' => 'redis',
        'name' => 'default',
        'from' => 1,
        'to' => 5,
        'action' => 'scale_up',
        'reason' => 'cluster:scale_up',
    ], $overrides);
}

it('records a decision and retrieves it via recentDecisions', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);

    $stored = [];
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock) use (&$stored): void {
        $mock->shouldReceive('zadd')
            ->once()
            ->withArgs(function (string $key, mixed $members) use (&$stored): bool {
                expect($key)->toContain('decisions:history');
                $stored = $members;

                return true;
            });
        $mock->shouldReceive('zremrangebyscore')->once();
        $mock->shouldReceive('zremrangebyrank')->once();
        $mock->shouldReceive('zrangebyscore')
            ->once()
            ->andReturnUsing(function () use (&$stored): array {
                return array_keys($stored);
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $decision = makeDecision();
    $store->recordDecision($decision);

    $recent = $store->recentDecisions(3600);

    expect($recent)->toHaveCount(1)
        ->and($recent[0]['workload_key'])->toBe('queue:redis:default')
        ->and($recent[0]['action'])->toBe('scale_up')
        ->and($recent[0]['from'])->toBe(1)
        ->and($recent[0]['to'])->toBe(5);
});

it('returns empty array when no decisions exist', function () {
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('zrangebyscore')
            ->once()
            ->andReturn([]);
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $recent = $store->recentDecisions(3600);

    expect($recent)->toBeArray()->toBeEmpty();
});

it('adds recorded_at timestamp to each decision', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);

    $storedJson = null;
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock) use (&$storedJson): void {
        $mock->shouldReceive('zadd')
            ->once()
            ->withArgs(function (string $key, mixed $members) use (&$storedJson): bool {
                $storedJson = array_key_first($members);

                return true;
            });
        $mock->shouldReceive('zremrangebyscore')->once();
        $mock->shouldReceive('zremrangebyrank')->once();
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $before = microtime(true);
    $store->recordDecision(makeDecision());
    $after = microtime(true);

    $decoded = json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toHaveKey('recorded_at')
        ->and($decoded['recorded_at'])->toBeGreaterThanOrEqual($before)
        ->and($decoded['recorded_at'])->toBeLessThanOrEqual($after);
});

it('accumulates decisions across multiple recording calls', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);

    $allStored = [];
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock) use (&$allStored): void {
        $mock->shouldReceive('zadd')
            ->times(3)
            ->withArgs(function (string $key, mixed $members) use (&$allStored): bool {
                $allStored[] = array_key_first($members);

                return true;
            });
        $mock->shouldReceive('zremrangebyscore')->times(3);
        $mock->shouldReceive('zremrangebyrank')->times(3);
        $mock->shouldReceive('zrangebyscore')
            ->once()
            ->andReturnUsing(function () use (&$allStored): array {
                return $allStored;
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision(['name' => 'fast', 'workload_key' => 'queue:redis:fast']));
    $store->recordDecision(makeDecision(['name' => 'slow', 'workload_key' => 'queue:redis:slow']));
    $store->recordDecision(makeDecision(['name' => 'batch', 'workload_key' => 'queue:redis:batch']));

    $recent = $store->recentDecisions(3600);

    expect($recent)->toHaveCount(3)
        ->and($recent[0]['name'])->toBe('fast')
        ->and($recent[1]['name'])->toBe('slow')
        ->and($recent[2]['name'])->toBe('batch');
});

it('calls zremrangebyscore with configured time window for pruning', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 1800);
    config()->set('queue-autoscale.cluster.decision_history_max', 500);

    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('zadd')->once();
        $mock->shouldReceive('zremrangebyscore')
            ->once()
            ->withArgs(function (string $key, string $min, string $max): bool {
                expect($min)->toBe('-inf');
                $cutoff = (float) $max;
                $expectedCutoff = microtime(true) - 1800;
                expect($cutoff)->toBeGreaterThanOrEqual($expectedCutoff - 1)
                    ->toBeLessThanOrEqual($expectedCutoff + 1);

                return true;
            });
        $mock->shouldReceive('zremrangebyrank')
            ->once()
            ->withArgs(function (string $key, int $start, int $stop): bool {
                expect($start)->toBe(0)
                    ->and($stop)->toBe(-501);

                return true;
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision());
});

it('calls zremrangebyrank with configured max entries for pruning', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 100);

    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('zadd')->once();
        $mock->shouldReceive('zremrangebyscore')->once();
        $mock->shouldReceive('zremrangebyrank')
            ->once()
            ->withArgs(function (string $key, int $start, int $stop): bool {
                expect($start)->toBe(0)
                    ->and($stop)->toBe(-101);

                return true;
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision());
});

it('uses the correct Redis key pattern for decisions history', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);
    config()->set('app.name', 'TestApp');
    config()->set('app.env', 'testing');

    $capturedKey = null;
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock) use (&$capturedKey): void {
        $mock->shouldReceive('zadd')
            ->once()
            ->withArgs(function (string $key) use (&$capturedKey): bool {
                $capturedKey = $key;

                return true;
            });
        $mock->shouldReceive('zremrangebyscore')->once();
        $mock->shouldReceive('zremrangebyrank')->once();
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision());

    expect($capturedKey)->toContain('queue-autoscale:cluster:')
        ->and($capturedKey)->toEndWith(':decisions:history');
});

it('passes correct score range to zrangebyscore', function () {
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('zrangebyscore')
            ->once()
            ->withArgs(function (string $key, string $min, string $max): bool {
                expect($key)->toContain('decisions:history');
                $minScore = (float) $min;
                $expectedMin = microtime(true) - 600;
                expect($minScore)->toBeGreaterThanOrEqual($expectedMin - 1)
                    ->toBeLessThanOrEqual($expectedMin + 1);
                expect($max)->toBe('+inf');

                return true;
            })
            ->andReturn([]);
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recentDecisions(600);
});

it('gracefully handles malformed JSON entries from Redis', function () {
    $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('zrangebyscore')
            ->once()
            ->andReturn([
                json_encode(['workload_key' => 'queue:redis:good', 'action' => 'scale_up', 'recorded_at' => microtime(true)]),
                'not-valid-json{{{',
                json_encode(['workload_key' => 'queue:redis:also-good', 'action' => 'scale_down', 'recorded_at' => microtime(true)]),
            ]);
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $recent = $store->recentDecisions(3600);

    expect($recent)->toHaveCount(2)
        ->and($recent[0]['workload_key'])->toBe('queue:redis:good')
        ->and($recent[1]['workload_key'])->toBe('queue:redis:also-good');
});
