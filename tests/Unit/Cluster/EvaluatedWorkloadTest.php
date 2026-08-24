<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\EvaluatedWorkload;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;

function evaluatedWorkload(array $overrides = []): EvaluatedWorkload
{
    return new EvaluatedWorkload(
        isGroup: $overrides['isGroup'] ?? false,
        connection: $overrides['connection'] ?? 'redis',
        name: $overrides['name'] ?? 'default',
        driver: $overrides['driver'] ?? 'redis',
        config: $overrides['config'] ?? makeQueueConfig(['slaTarget' => 30]),
        currentWorkers: $overrides['currentWorkers'] ?? 2,
        metrics: $overrides['metrics'] ?? createMetrics(),
        memberQueues: $overrides['memberQueues'] ?? ['default'],
    );
}

test('a queue and a group report their own type', function (): void {
    expect(evaluatedWorkload()->type())->toBe('queue')
        ->and(evaluatedWorkload(['isGroup' => true])->type())->toBe('group');
});

test('the breach key namespaces groups so they cannot collide with a queue of the same name', function (): void {
    $queue = evaluatedWorkload(['connection' => 'redis', 'name' => 'exports']);
    $group = evaluatedWorkload(['isGroup' => true, 'connection' => 'redis', 'name' => 'exports']);

    expect($queue->breachKey())->toBe('redis:exports')
        ->and($group->breachKey())->toBe('group:redis:exports')
        ->and($queue->breachKey())->not->toBe($group->breachKey());
});

test('a job older than the SLA target is breaching', function (): void {
    $workload = evaluatedWorkload([
        'config' => makeQueueConfig(['slaTarget' => 30]),
        'metrics' => createMetrics(['oldest_job_age' => 45]),
    ]);

    expect($workload->isBreaching())->toBeTrue();
});

test('an age exactly at the target counts as breaching', function (): void {
    $workload = evaluatedWorkload([
        'config' => makeQueueConfig(['slaTarget' => 30]),
        'metrics' => createMetrics(['oldest_job_age' => 30]),
    ]);

    expect($workload->isBreaching())->toBeTrue();
});

/**
 * The guard that matters: an empty queue reports an oldest-job age of zero,
 * which without the non-zero check reads as "infinitely late" against any
 * target and would breach every idle queue on every cycle.
 */
test('an empty queue reporting age zero is not breaching', function (): void {
    $workload = evaluatedWorkload([
        'config' => makeQueueConfig(['slaTarget' => 30]),
        'metrics' => createMetrics(['oldest_job_age' => 0]),
    ]);

    expect($workload->isBreaching())->toBeFalse();
});

/**
 * `scalable` is an explicit configuration flag, not something inferred from
 * min == max. Setting it false additionally *requires* min == max — a
 * supervised queue has one fixed worker count — but an equal min and max on
 * its own leaves the queue scalable.
 */
test('scalability is the configured flag, not an inference from min equalling max', function (): void {
    $base = makeQueueConfig(['minWorkers' => 4, 'maxWorkers' => 4]);

    $equalBounds = evaluatedWorkload(['config' => $base]);

    $supervised = evaluatedWorkload(['config' => new QueueConfiguration(
        connection: $base->connection,
        queue: $base->queue,
        sla: $base->sla,
        forecast: $base->forecast,
        spawnCompensation: $base->spawnCompensation,
        workers: new WorkerConfiguration(
            min: 4,
            max: 4,
            tries: $base->workers->tries,
            maxTimeSeconds: $base->workers->maxTimeSeconds,
            timeoutSeconds: $base->workers->timeoutSeconds,
            sleepSeconds: $base->workers->sleepSeconds,
            shutdownTimeoutSeconds: $base->workers->shutdownTimeoutSeconds,
            scalable: false,
        ),
        fuse: $base->fuse,
    )]);

    expect($equalBounds->isScalable())->toBeTrue()
        ->and($supervised->isScalable())->toBeFalse();
});
