<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
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
                expect($script)->toContain("redis.call('setex'");
                expect($arguments)->toHaveCount(4);
                [$key, $payload, $ttl, $managerId] = $arguments;
                expect($numberOfKeys)->toBe(1);
                expect($key)->toContain('queue-autoscale:cluster:');
                expect(json_decode($payload, true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
                    'manager_id' => 'manager-a',
                ]);
                expect($ttl)->toBe(15);
                expect($managerId)->toBe('manager-a');

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
