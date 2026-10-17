<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\BeanstalkdQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\DeferredQueue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\SyncQueue;

/**
 * The built-in queue driver a connection behaves as.
 *
 * An application can register its own driver (`Queue::extend()`), usually a
 * subclass of a built-in queue that adds tenancy, encryption or routing. Its
 * driver name is then one the package has never heard of, so a check on
 * `queue.connections.*.driver` alone would treat a subclass of `SqsQueue` as
 * not SQS, or a subclass of `BackgroundQueue` as a queue workers can pop.
 * The connection is therefore recognised by the queue it resolves to, and by
 * its configured driver name only when it cannot be resolved or is no
 * subclass of a built-in queue. A built-in driver name is taken as it is,
 * so only connections on a custom driver are ever resolved here.
 */
class QueueDriver
{
    /**
     * Most specific first: `BackgroundQueue` and `DeferredQueue` extend
     * `SyncQueue`. Classes missing from older Laravel versions never match.
     *
     * @var array<class-string, string>
     */
    private const BUILT_IN = [
        BackgroundQueue::class => 'background',
        DeferredQueue::class => 'deferred',
        SyncQueue::class => 'sync',
        NullQueue::class => 'null',
        SqsQueue::class => 'sqs',
        RedisQueue::class => 'redis',
        DatabaseQueue::class => 'database',
        BeanstalkdQueue::class => 'beanstalkd',
    ];

    /**
     * The driver name, or null for a connection that is not configured.
     */
    public static function of(string $connection): ?string
    {
        $configured = config("queue.connections.{$connection}.driver");

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        if (in_array($configured, self::BUILT_IN, true)) {
            return $configured;
        }

        try {
            // The manager keeps each resolved connection, so this is resolved once per process.
            $queue = app(QueueManager::class)->connection($connection);
        } catch (\Throwable) {
            // Missing credentials, an unregistered driver or an unavailable
            // dependency: the configured name is all there is to go on.
            return $configured;
        }

        foreach (self::BUILT_IN as $class => $driver) {
            if (is_a($queue, $class)) {
                return $driver;
            }
        }

        return $configured;
    }
}
