<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Fixtures;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Queue;
use Mockery;

/**
 * A connection on an application-defined driver, the way `Queue::extend()`
 * adds tenancy, encryption or routing on top of a built-in queue: its driver
 * name is the application's own, and it resolves to a subclass of `$queueClass`.
 */
class CustomQueueDriver
{
    /**
     * @param  class-string<Queue>  $queueClass
     */
    public static function register(string $connection, string $queueClass, string $driver = 'custom'): void
    {
        $queue = Mockery::mock($queueClass, QueueContract::class)->makePartial();

        assert($queue instanceof QueueContract);

        app('queue')->extend($driver, fn (): ConnectorInterface => new class($queue) implements ConnectorInterface
        {
            public function __construct(private readonly QueueContract $queue) {}

            /**
             * @param  array<string, mixed>  $config
             */
            public function connect(array $config): QueueContract
            {
                return $this->queue;
            }
        });

        config()->set("queue.connections.{$connection}", ['driver' => $driver]);
    }
}
