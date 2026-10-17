<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Pickup\NullPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\CustomQueueDriver;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\DeferredQueue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\SyncQueue;

test('the shipped config carries the v4.4 defaults', function (): void {
    expect(config('queue-autoscale.limits.memory_limit_mb'))->toBeNull()
        ->and(config('queue-autoscale.limits.worker_settle_seconds'))->toBe(30)
        ->and(config('queue-autoscale.limits.oom_backoff_seconds'))->toBe(300)
        ->and(config('queue-autoscale.pickup_time.max_sample_seconds'))->toBe(3600)
        ->and(config('queue-autoscale.pickup_time.stamp_pushed_at'))->toBeFalse();

    expect(AutoscaleConfiguration::memoryLimitMb())->toBeNull()
        ->and(AutoscaleConfiguration::workerSettleSeconds())->toBe(30)
        ->and(AutoscaleConfiguration::oomBackoffSeconds())->toBe(300)
        ->and(AutoscaleConfiguration::pickupMaxSampleSeconds())->toBe(3600.0)
        ->and(AutoscaleConfiguration::stampPushedAt())->toBeFalse();
});

test('a memory limit is read as megabytes only when it is a positive number', function (mixed $configured, ?float $expected): void {
    config()->set('queue-autoscale.limits.memory_limit_mb', $configured);

    expect(AutoscaleConfiguration::memoryLimitMb())->toBe($expected);
})->with([
    'integer' => [4096, 4096.0],
    'env string' => ['3072', 3072.0],
    'fractional' => [1536.5, 1536.5],
    'null' => [null, null],
    'zero' => [0, null],
    'negative' => [-1, null],
    'empty env value' => ['', null],
    'not a number' => ['4G', null],
]);

test('the settle window and OOM backoff are never negative', function (): void {
    config()->set('queue-autoscale.limits.worker_settle_seconds', -10);
    config()->set('queue-autoscale.limits.oom_backoff_seconds', -60);

    expect(AutoscaleConfiguration::workerSettleSeconds())->toBe(0)
        ->and(AutoscaleConfiguration::oomBackoffSeconds())->toBe(0);
});

test('zero turns the settle window and OOM backoff off', function (): void {
    config()->set('queue-autoscale.limits.worker_settle_seconds', 0);
    config()->set('queue-autoscale.limits.oom_backoff_seconds', 0);

    expect(AutoscaleConfiguration::workerSettleSeconds())->toBe(0)
        ->and(AutoscaleConfiguration::oomBackoffSeconds())->toBe(0);
});

test('the pickup sample cap is null when disabled or not a positive number', function (mixed $configured, ?float $expected): void {
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', $configured);

    expect(AutoscaleConfiguration::pickupMaxSampleSeconds())->toBe($expected);
})->with([
    'integer' => [7200, 7200.0],
    'env string' => ['900', 900.0],
    'null' => [null, null],
    'zero' => [0, null],
    'negative' => [-5, null],
    'not a number' => ['never', null],
]);

test('stamping pushedAt follows the truthiness of the flag', function (mixed $configured, bool $expected): void {
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', $configured);

    expect(AutoscaleConfiguration::stampPushedAt())->toBe($expected);
})->with([
    'true' => [true, true],
    'env "1"' => ['1', true],
    'false' => [false, false],
    'env "0"' => ['0', false],
    'null' => [null, false],
]);

test('a connection can be worked unless its driver runs or discards jobs in-process', function (string $driver, bool $expected): void {
    config()->set('queue.connections.under-test', ['driver' => $driver]);

    expect(AutoscaleConfiguration::connectionCanBeWorked('under-test'))->toBe($expected);
})->with([
    'redis' => ['redis', true],
    'database' => ['database', true],
    'sqs' => ['sqs', true],
    'beanstalkd' => ['beanstalkd', true],
    'a custom driver' => ['rabbitmq', true],
    'sync' => ['sync', false],
    'null' => ['null', false],
    'deferred' => ['deferred', false],
    'background' => ['background', false],
]);

test('an unconfigured connection is not judged unworkable', function (): void {
    expect(AutoscaleConfiguration::connectionCanBeWorked('does-not-exist'))->toBeTrue();
});

test('a custom driver is workable unless it is built on a queue that runs or discards jobs in-process', function (string $queueClass, bool $expected): void {
    CustomQueueDriver::register('under-test', $queueClass);

    expect(AutoscaleConfiguration::connectionCanBeWorked('under-test'))->toBe($expected);
})->with([
    'sqs' => [SqsQueue::class, true],
    'redis' => [RedisQueue::class, true],
    'sync' => [SyncQueue::class, false],
    'null' => [NullQueue::class, false],
    'deferred' => [DeferredQueue::class, false],
    'background' => [BackgroundQueue::class, false],
]);

test('the pickup-time store class follows the store setting and cluster mode', function (string $store, bool $cluster, string $expected): void {
    config()->set('queue-autoscale.pickup_time.store', $store);
    config()->set('queue-autoscale.cluster.enabled', $cluster);

    expect(AutoscaleConfiguration::pickupTimeStoreClass())->toBe($expected);
})->with([
    'auto on a single host' => ['auto', false, NullPickupTimeStore::class],
    'auto in a cluster' => ['auto', true, RedisPickupTimeStore::class],
    'blank on a single host' => ['  ', false, NullPickupTimeStore::class],
    'redis on a single host' => ['redis', false, RedisPickupTimeStore::class],
    'null in a cluster' => ['null', true, NullPickupTimeStore::class],
    'a class name' => [InMemoryPickupTimeStore::class, false, InMemoryPickupTimeStore::class],
]);
