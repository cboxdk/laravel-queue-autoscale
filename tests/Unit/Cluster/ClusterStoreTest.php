<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;

it('acquires a new leader lease through eval so it works with phpredis', function () {
    config()->set('app.name', 'Queue Autoscale Test');
    config()->set('app.env', 'testing');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default']);
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 15);

    $connection = Mockery::mock(PhpRedisConnection::class, function (MockInterface $mock): void {
        $mock->shouldReceive('get')->once()->andReturn(null);
        $mock->shouldReceive('command')
            ->once()
            ->withArgs(function (string $method, array $parameters): bool {
                expect($method)->toBe('eval');
                expect($parameters)->toHaveCount(3);
                [$script, $arguments, $numberOfKeys] = $parameters;
                expect($script)->toContain("redis.call('setex'");
                expect($arguments)->toHaveCount(3);
                [$key, $payload, $ttl] = $arguments;
                expect($numberOfKeys)->toBe(1);
                expect($key)->toContain('queue-autoscale:cluster:');
                expect(json_decode($payload, true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
                    'manager_id' => 'manager-a',
                ]);
                expect($ttl)->toBe(15);

                return true;
            })
            ->andReturn(1);
    });

    Redis::shouldReceive('connection')->twice()->andReturn($connection);
    $store = new ClusterStore;

    expect($store->isLeader('manager-a'))->toBeTrue();
});
