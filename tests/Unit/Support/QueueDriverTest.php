<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\QueueDriver;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\CustomQueueDriver;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\DeferredQueue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\SyncQueue;

test('a custom driver is recognised by the built-in queue it extends', function (string $queueClass, string $expected): void {
    CustomQueueDriver::register('under-test', $queueClass);

    expect(QueueDriver::of('under-test'))->toBe($expected);
})->with([
    'sqs' => [SqsQueue::class, 'sqs'],
    'redis' => [RedisQueue::class, 'redis'],
    'database' => [DatabaseQueue::class, 'database'],
    'beanstalkd' => [BeanstalkdQueue::class, 'beanstalkd'],
    'sync' => [SyncQueue::class, 'sync'],
    'deferred' => [DeferredQueue::class, 'deferred'],
    'background' => [BackgroundQueue::class, 'background'],
    'null' => [NullQueue::class, 'null'],
]);

test('a custom driver that extends no built-in queue keeps its name', function (): void {
    CustomQueueDriver::register('under-test', Queue::class, driver: 'rabbitmq');

    expect(QueueDriver::of('under-test'))->toBe('rabbitmq');
});

test('a custom driver that cannot be resolved keeps its name', function (): void {
    config()->set('queue.connections.under-test', ['driver' => 'unregistered']);

    expect(QueueDriver::of('under-test'))->toBe('unregistered');
});

test('a built-in driver is taken by name without resolving the connection', function (): void {
    // No region or credentials: resolving this connection would throw.
    config()->set('queue.connections.under-test', ['driver' => 'sqs']);

    expect(QueueDriver::of('under-test'))->toBe('sqs')
        ->and(app('queue')->connected('under-test'))->toBeFalse();
});

test('an unconfigured connection has no driver', function (?array $config): void {
    config()->set('queue.connections.under-test', $config);

    expect(QueueDriver::of('under-test'))->toBeNull();
})->with([
    'missing' => [null],
    'no driver' => [['queue' => 'default']],
    'empty driver' => [['driver' => '']],
]);

test('a connection that fails to resolve is not retried on every call', function (): void {
    $attempts = 0;
    app('queue')->extend('eager-broker', function () use (&$attempts): never {
        $attempts++;

        throw new RuntimeException('broker is down');
    });
    config()->set('queue.connections.under-test', ['driver' => 'eager-broker']);

    QueueDriver::of('under-test');
    QueueDriver::of('under-test');
    QueueDriver::of('under-test');

    expect(QueueDriver::of('under-test'))->toBe('eager-broker')
        ->and($attempts)->toBe(1);
});
