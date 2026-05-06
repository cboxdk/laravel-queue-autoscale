<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;

/**
 * Build a sample decision array for testing.
 *
 * @param  array<string, mixed>  $overrides
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

/**
 * Extract the member JSON string from a recordDecision eval call.
 *
 * For a generic Connection, command('eval', [...]) receives:
 *   [script, numkeys, key, score, member, cutoff, rankStop, ttl]
 *
 * @param  array<int, mixed>  $evalArgs
 */
function extractMemberFromEvalArgs(array $evalArgs): string
{
    return (string) $evalArgs[4];
}

it('records a decision and retrieves it via recentDecisions', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);

    $storedMember = null;
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock) use (&$storedMember): void {
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $cmd, array $args) use (&$storedMember): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $storedMember = extractMemberFromEvalArgs($args);

                return true;
            });
        $mock->shouldReceive('zrangebyscore')
            ->once()
            ->andReturnUsing(function () use (&$storedMember): array {
                return [$storedMember];
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
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock): void {
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
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock) use (&$storedJson): void {
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $cmd, array $args) use (&$storedJson): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $storedJson = extractMemberFromEvalArgs($args);

                return true;
            });
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
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock) use (&$allStored): void {
        $mock->shouldReceive('command')
            ->times(3)
            ->withArgs(function (string $cmd, array $args) use (&$allStored): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $allStored[] = extractMemberFromEvalArgs($args);

                return true;
            });
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

it('passes correct time-based pruning cutoff to Lua script', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 1800);
    config()->set('queue-autoscale.cluster.decision_history_max', 500);

    $connection = Mockery::mock(Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $cmd, array $args): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $cutoff = (float) $args[5];
                $expectedCutoff = microtime(true) - 1800;
                expect($cutoff)->toBeGreaterThanOrEqual($expectedCutoff - 1)
                    ->toBeLessThanOrEqual($expectedCutoff + 1);

                $rankStop = (int) $args[6];
                expect($rankStop)->toBe(-501);

                return true;
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision());
});

it('passes correct count-based pruning limit to Lua script', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 100);

    $connection = Mockery::mock(Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $cmd, array $args): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $rankStop = (int) $args[6];
                expect($rankStop)->toBe(-101);

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
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock) use (&$capturedKey): void {
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $cmd, array $args) use (&$capturedKey): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $capturedKey = $args[2];

                return true;
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision());

    expect($capturedKey)->toContain('queue-autoscale:cluster:')
        ->and($capturedKey)->toEndWith(':decisions:history');
});

it('passes correct score range to zrangebyscore', function () {
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock): void {
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
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock): void {
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

it('uses atomic Lua script for record and prune operations', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 3600);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);

    $capturedScript = null;
    $connection = Mockery::mock(Connection::class, function (MockInterface $mock) use (&$capturedScript): void {
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $cmd, array $args) use (&$capturedScript): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $capturedScript = $args[0];

                return true;
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision());

    expect($capturedScript)->toContain('zadd')
        ->and($capturedScript)->toContain('zremrangebyscore')
        ->and($capturedScript)->toContain('zremrangebyrank')
        ->and($capturedScript)->toContain('expire');
});

it('sets TTL on the sorted set key matching decision_history_seconds', function () {
    config()->set('queue-autoscale.cluster.decision_history_seconds', 1800);
    config()->set('queue-autoscale.cluster.decision_history_max', 10000);

    $connection = Mockery::mock(Connection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $cmd, array $args): bool {
                if ($cmd !== 'eval') {
                    return false;
                }

                $ttl = (int) $args[7];
                expect($ttl)->toBe(1800);

                return true;
            });
    });

    Redis::shouldReceive('connection')->andReturn($connection);
    $store = new ClusterStore;

    $store->recordDecision(makeDecision());
});
