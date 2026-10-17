<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Fixtures;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Queue;
use LogicException;
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
        // Adding the contract to a queue that already implements it makes
        // Mockery redeclare pop() from the contract, which older Laravel
        // versions declare without the $index the built-in queues add.
        $queue = is_subclass_of($queueClass, QueueContract::class)
            ? Mockery::mock($queueClass)->makePartial()
            : Mockery::mock($queueClass, QueueContract::class)->makePartial();

        if (! $queue instanceof QueueContract) {
            throw new LogicException("A mock of {$queueClass} must implement the queue contract.");
        }

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
