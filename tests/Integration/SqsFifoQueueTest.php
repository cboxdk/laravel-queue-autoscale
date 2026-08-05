<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;

/**
 * FIFO queues change what a worker count means.
 *
 * A standard queue delivers to as many consumers as ask, so N workers give N
 * concurrent jobs. A FIFO queue delivers at most one message per *message
 * group* at a time: whatever the worker count, parallelism is capped by the
 * number of distinct groups in the backlog. Scaling a FIFO queue without
 * knowing that produces workers that poll and never work.
 *
 * These specs pin the behaviour the autoscaler has to reason about, using the
 * same ElasticMQ container as the standard-queue specs.
 */
class FifoProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $group) {}

    /**
     * Laravel derives MessageGroupId from this. Without it the SQS driver
     * sends no group and AWS rejects the message outright.
     */
    public function messageGroup(): string
    {
        return $this->group;
    }

    public function handle(): void {}
}

class GrouplessProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}

function createFifoQueue(string $endpoint, string $name): void
{
    @file_get_contents(
        $endpoint."/?Action=CreateQueue&QueueName={$name}"
        .'&Attribute.1.Name=FifoQueue&Attribute.1.Value=true'
        .'&Attribute.2.Name=ContentBasedDeduplication&Attribute.2.Value=true'
    );
}

beforeEach(function (): void {
    if (! elasticMqReachable()) {
        test()->markTestSkipped('ElasticMQ is not running on '.elasticMqEndpoint());
    }

    $endpoint = elasticMqEndpoint();

    $this->fifoQueue = 'probe-'.bin2hex(random_bytes(6)).'.fifo';

    config()->set('queue.connections.sqs', [
        'driver' => 'sqs',
        'key' => 'x',
        'secret' => 'x',
        'prefix' => $endpoint.'/000000000000',
        'queue' => $this->fifoQueue,
        'suffix' => '',
        'region' => 'elasticmq',
        'endpoint' => $endpoint,
    ]);

    createFifoQueue($endpoint, $this->fifoQueue);
});

test('a queued job reaches a fifo queue and shows up as depth', function (): void {
    for ($i = 0; $i < 5; $i++) {
        Queue::connection('sqs')->push(new FifoProbeJob('tenant-42'), '', $this->fifoQueue);
    }

    expect(QueueMetrics::getQueueDepth('sqs', $this->fifoQueue)->pendingJobs)->toBeGreaterThan(0);
});

test('a job without a message group is rejected by the queue', function (): void {
    // Worth pinning because the failure is at dispatch, not at processing: a
    // job class that forgets messageGroup() throws the moment it is queued.
    expect(fn () => Queue::connection('sqs')->push(new GrouplessProbeJob, '', $this->fifoQueue))
        ->toThrow(Exception::class, 'MessageGroupId');
});

test('a queue name ending in .fifo can be configured at all', function (): void {
    // Every FIFO queue name ends in '.fifo' by AWS requirement, so the dotted
    // config lookup made per-queue settings unreachable for ALL of them. This
    // is where that fix actually bites.
    config()->set('queue-autoscale.queues', [
        $this->fifoQueue => ['workers' => ['max' => 3]],
    ]);

    expect(QueueConfiguration::fromConfig('sqs', $this->fifoQueue)->workers->max)->toBe(3);
});

test('a glob covers fifo queues alongside standard ones', function (): void {
    config()->set('queue-autoscale.queues', [
        'probe-*' => ['workers' => ['max' => 4]],
    ]);

    expect(QueueConfiguration::fromConfig('sqs', $this->fifoQueue)->workers->max)->toBe(4);
});

test('one message group delivers one job at a time, however many ask', function (): void {
    // The property that decides whether scaling a FIFO queue does anything at
    // all. Five jobs, one group, two consumers: the second gets nothing until
    // the first message is done.
    for ($i = 0; $i < 5; $i++) {
        Queue::connection('sqs')->push(new FifoProbeJob('tenant-42'), '', $this->fifoQueue);
    }

    $first = Queue::connection('sqs')->pop($this->fifoQueue);
    $second = Queue::connection('sqs')->pop($this->fifoQueue);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

test('parallelism comes from the number of groups, not the number of workers', function (): void {
    // The converse, and the design lever: to get five concurrent callers for a
    // tenant on a FIFO queue, the application must shard that tenant's work
    // across five message groups. Worker count alone cannot produce it.
    foreach (['tenant-42-0', 'tenant-42-1', 'tenant-42-2'] as $group) {
        Queue::connection('sqs')->push(new FifoProbeJob($group), '', $this->fifoQueue);
    }

    $popped = array_filter([
        Queue::connection('sqs')->pop($this->fifoQueue),
        Queue::connection('sqs')->pop($this->fifoQueue),
        Queue::connection('sqs')->pop($this->fifoQueue),
    ]);

    expect($popped)->toHaveCount(3);
});
