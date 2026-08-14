<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;

beforeEach(function (): void {
    config()->set('app.name', 'Queue Autoscale Test');
    config()->set('app.env', 'testing');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default']);
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 15);
});

it('acquires or renews the leader lease atomically through eval for phpredis', function () {
    $connection = Mockery::mock(PhpRedisConnection::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('get');
        $mock->shouldNotReceive('setex');
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $method, array $parameters): bool {
                expect($method)->toBe('eval');
                expect($parameters)->toHaveCount(3);
                [$script, $arguments, $numberOfKeys] = $parameters;
                expect($script)->toContain("redis.call('get'");
                expect($script)->toContain('pcall(cjson.decode');
                expect($script)->toContain("decoded['manager_id'] == ARGV[3]");
                expect($script)->toContain("decoded['leader_token']");
                expect($script)->toContain("redis.call('setex'");
                expect($arguments)->toHaveCount(5);
                [$key, $payload, $ttl, $managerId, $renewedAt] = $arguments;
                expect($numberOfKeys)->toBe(1);
                expect($key)->toContain('queue-autoscale:cluster:');
                $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                expect($decodedPayload)->toMatchArray([
                    'manager_id' => 'manager-a',
                ]);
                expect($decodedPayload['leader_token'] ?? null)->toBeString();
                expect($decodedPayload['leader_token'])->not->toBe('');
                expect($decodedPayload['renewed_at'] ?? null)->toBeInt();
                expect($ttl)->toBe(15);
                expect($managerId)->toBe('manager-a');
                expect($renewedAt)->toBeInt();

                return true;
            })
            ->andReturn(1);
    });

    Redis::shouldReceive('connection')->once()->andReturn($connection);
    $store = new ClusterStore;

    expect($store->isLeader('manager-a'))->toBeTrue();
});

it('does not treat the manager as leader when the atomic lease script rejects it', function () {
    $connection = Mockery::mock(PhpRedisConnection::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('get');
        $mock->shouldNotReceive('setex');
        $mock->shouldReceive('command')
            ->once()
            ->with('eval', Mockery::type('array'))
            ->andReturn(0);
    });

    Redis::shouldReceive('connection')->once()->andReturn($connection);
    $store = new ClusterStore;

    expect($store->isLeader('manager-a'))->toBeFalse();
});

it('reads the current leader fencing token from the lease payload', function () {
    $connection = Mockery::mock(PhpRedisConnection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('get')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn(json_encode([
                'manager_id' => 'manager-a',
                'renewed_at' => 1234,
                'leader_token' => 'lease-token-a',
            ], JSON_THROW_ON_ERROR));
    });

    Redis::shouldReceive('connection')->once()->andReturn($connection);
    $store = new ClusterStore;

    expect($store->leaderToken())->toBe('lease-token-a');
});

it('fences the recommendation write on the leader token', function () {
    // The token was checked by the reader and never by the writer, which is
    // the half that does not fence anything: a deposed leader's write always
    // succeeded and was merely ignored afterwards.
    $connection = Mockery::mock(Connection::class);
    $sent = [];

    $connection->shouldReceive('command')->andReturnUsing(function (string $cmd, array $args) use (&$sent): int {
        $sent[] = [$cmd, $args];

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($connection);

    (new ClusterStore)->publishRecommendation(new ClusterRecommendation(
        managerId: 'web-01',
        issuedAt: 0,
        workloads: [],
        leaderId: 'web-01',
        leaderToken: 'token-7',
    ));

    expect($sent)->toHaveCount(1)
        ->and($sent[0][0])->toBe('eval');

    $script = is_string($sent[0][1][0]) ? $sent[0][1][0] : '';

    expect($script)->toContain('leader_token')
        ->and($script)->toContain('setex');
});

it('still writes when no token was issued', function () {
    // A rolling upgrade from a version that did not issue tokens must not lose
    // its recommendations entirely.
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('setex')->once()->andReturn(true);
    $connection->shouldReceive('command')->never();
    Redis::shouldReceive('connection')->andReturn($connection);

    (new ClusterStore)->publishRecommendation(new ClusterRecommendation(
        managerId: 'web-01',
        issuedAt: 0,
        workloads: [],
        leaderId: 'web-01',
    ));
});
