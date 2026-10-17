<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Diagnostics\QueueDiscovery;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\CustomQueueDriver;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\SqsQueue;

/**
 * @param  array<string, array<string, mixed>>  $metrics
 */
function discoveryReading(array $metrics): QueueDiscovery
{
    return new class($metrics) extends QueueDiscovery
    {
        /**
         * @param  array<string, array<string, mixed>>  $metrics
         */
        public function __construct(private array $metrics) {}

        protected function fromMetrics(): array
        {
            return $this->metrics;
        }
    };
}

beforeEach(function (): void {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.sync', ['driver' => 'sync']);
    config()->set('queue.connections.null', ['driver' => 'null']);
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('queue.connections.background', ['driver' => 'background']);
    config()->set('queue.connections.inline', ['driver' => 'sync']);
});

test('queues on connections no worker can pop from are not discovered', function (): void {
    $discovery = discoveryReading([
        'redis:emails' => ['queue' => 'emails', 'connection' => 'redis'],
        'sync:sync' => ['queue' => 'sync', 'connection' => 'sync'],
        'null:dropped' => ['queue' => 'dropped', 'connection' => 'null'],
        'deferred:later' => ['queue' => 'later', 'connection' => 'deferred'],
        'background:bg' => ['queue' => 'bg', 'connection' => 'background'],
    ]);

    expect($discovery->names())->toBe(['emails']);
});

test('the connection is judged by its driver, not its name', function (): void {
    // A real queue named "sync" on a worked connection is still a queue,
    // and a sync-driver connection under another name is still skipped.
    $discovery = discoveryReading([
        'redis:sync' => ['queue' => 'sync', 'connection' => 'redis'],
        'inline:reports' => ['queue' => 'reports', 'connection' => 'inline'],
    ]);

    expect($discovery->names())->toBe(['sync']);
});

test('the connection is read from the key when the payload lacks it', function (): void {
    $discovery = discoveryReading([
        'sync:sync' => ['queue' => 'sync'],
        'redis:emails' => [],
    ]);

    expect($discovery->names())->toBe(['emails']);
});

test('names on a driver come from the connection each queue was seen on', function (): void {
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue.connections.sqs-eu', ['driver' => 'sqs']);

    $discovery = discoveryReading([
        'sqs:invoices' => ['queue' => 'invoices', 'connection' => 'sqs'],
        'sqs-eu:reports' => ['queue' => 'reports', 'connection' => 'sqs-eu'],
        'redis:emails' => ['queue' => 'emails', 'connection' => 'redis'],
        'sqs:tenant:42' => ['queue' => 'tenant:42', 'connection' => 'sqs'],
    ]);

    expect($discovery->namesOnDriver('sqs'))->toBe(['invoices', 'reports', 'tenant:42'])
        ->and($discovery->namesOnDriver('redis'))->toBe(['emails']);
});

test('names on a driver include configured queues on that driver', function (): void {
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue.default', 'redis');
    config()->set('queue-autoscale.queues', [
        'orders' => ['connection' => 'sqs'],
        'emails' => [],
        'tenant-*' => ['connection' => 'sqs'],
    ]);

    $discovery = discoveryReading([]);

    expect($discovery->namesOnDriver('sqs'))->toBe(['orders'])
        ->and($discovery->namesOnDriver('redis'))->toBe(['emails']);
});

test('an entry whose connection is unknown is on no driver', function (): void {
    $discovery = discoveryReading([
        'ghost:jobs' => ['queue' => 'jobs', 'connection' => 'ghost'],
    ]);

    expect($discovery->namesOnDriver('sqs'))->toBe([])
        ->and($discovery->names())->toBe(['jobs']);
});

test('a connection on a custom driver is judged by the queue it extends', function (): void {
    CustomQueueDriver::register('tenant-background', BackgroundQueue::class, driver: 'tenant-background');
    CustomQueueDriver::register('tenant-sqs', SqsQueue::class, driver: 'tenant-sqs');

    $discovery = discoveryReading([
        'tenant-background:sync' => ['queue' => 'sync', 'connection' => 'tenant-background'],
        'tenant-sqs:invoices' => ['queue' => 'invoices', 'connection' => 'tenant-sqs'],
    ]);

    expect($discovery->names())->toBe(['invoices'])
        ->and($discovery->namesOnDriver('sqs'))->toBe(['invoices']);
});
