<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Illuminate\Support\Facades\Queue;

/**
 * SQS support was a claim nobody had tested.
 *
 * The queue inspector has explicit branches for Redis and database queues and
 * nothing for SQS, so whether depth reads at all rested on an untested
 * fallback. SQS is a large share of the Laravel installed base, and a silent
 * zero would leave the autoscaler holding every SQS queue at its minimum
 * forever while reporting nothing wrong.
 *
 * These run against ElasticMQ, which speaks the SQS wire protocol:
 *
 *     docker run -d --name autoscale-elasticmq -p 9324:9324 \
 *         softwaremill/elasticmq-native:1.6.11
 *
 * They skip when it is not reachable, so the default suite stays hermetic.
 */
function pushToSqs(string $queue, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        Queue::connection('sqs')->pushRaw(
            json_encode(['id' => "probe-{$i}", 'job' => 'noop', 'data' => []]),
            $queue,
        );
    }
}

beforeEach(function (): void {
    if (! elasticMqReachable()) {
        test()->markTestSkipped('ElasticMQ is not running on '.elasticMqEndpoint());
    }

    $endpoint = elasticMqEndpoint();

    // A fresh queue per spec. ElasticMQ persists queues for the life of the
    // container, so a shared name would carry depth between specs and make
    // every assertion depend on execution order.
    $this->sqsQueue = 'probe-'.bin2hex(random_bytes(6));

    config()->set('queue.connections.sqs', [
        'driver' => 'sqs',
        'key' => 'x',
        'secret' => 'x',
        'prefix' => $endpoint.'/000000000000',
        'queue' => $this->sqsQueue,
        'suffix' => '',
        'region' => 'elasticmq',
        'endpoint' => $endpoint,
    ]);

    @file_get_contents($endpoint."/?Action=CreateQueue&QueueName={$this->sqsQueue}");
});

test('the driver resolves and accepts a job', function (): void {
    // Establish the connection works before asking about depth — otherwise a
    // zero is ambiguous between "empty" and "broken".
    pushToSqs($this->sqsQueue, 1);

    expect(Queue::connection('sqs')->size($this->sqsQueue))->toBe(1);
});

test('queue depth is readable through the metrics facade', function (): void {
    pushToSqs($this->sqsQueue, 5);

    $depth = QueueMetrics::getQueueDepth('sqs', $this->sqsQueue);

    // SQS reports ApproximateNumberOfMessages, so this is a range rather than
    // an equality — scaling only needs the magnitude to be right.
    expect($depth->pendingJobs)->toBeGreaterThan(0)
        ->and($depth->connection)->toBe('sqs')
        ->and($depth->queue)->toBe($this->sqsQueue);
});

test('an empty queue reads as zero rather than failing', function (): void {
    expect(QueueMetrics::getQueueDepth('sqs', $this->sqsQueue)->pendingJobs)->toBe(0);
});

test('depth tracks what was actually enqueued', function (): void {
    // The number has to move with the backlog, not just be non-zero — a
    // constant would satisfy the specs above and scale nothing.
    $before = QueueMetrics::getQueueDepth('sqs', $this->sqsQueue)->pendingJobs;

    pushToSqs($this->sqsQueue, 12);

    $after = QueueMetrics::getQueueDepth('sqs', $this->sqsQueue)->pendingJobs;

    expect($before)->toBe(0)
        ->and($after)->toBeGreaterThanOrEqual(10);
});
