<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

function workerTerminationPool(AutoscaleManager $manager): WorkerPool
{
    $property = new ReflectionProperty($manager, 'pool');

    return $property->getValue($manager);
}

it('requests scale-down termination without using the blocking terminator path', function () {
    config()->set('queue-autoscale.workers.shutdown_timeout_seconds', 2);
    Event::fake();

    $manager = app(AutoscaleManager::class);
    $pool = workerTerminationPool($manager);
    $process = new Process([
        PHP_BINARY,
        '-r',
        'pcntl_async_signals(true); pcntl_signal(SIGTERM, function () {}); while (true) { usleep(100000); }',
    ]);
    $process->start();
    usleep(100_000);
    $pool->add(new WorkerProcess(
        process: $process,
        connection: 'redis',
        queue: 'default',
        spawnedAt: now(),
    ));

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 1,
        targetWorkers: 0,
        reason: 'test:scale_down',
    );

    $method = new ReflectionMethod($manager, 'scaleDown');
    $start = microtime(true);
    $method->invoke($manager, $decision);
    $elapsed = microtime(true) - $start;

    expect($pool->count('redis', 'default'))->toBe(0)
        ->and($pool->getTerminatingWorkers())->toHaveCount(1)
        ->and($elapsed)->toBeLessThan(1.0);

    $process->stop(0, SIGKILL);
});

it('enforces termination deadlines on workers already shutting down', function () {
    $manager = app(AutoscaleManager::class);
    $pool = workerTerminationPool($manager);
    $process = new Process(['sleep', '5']);
    $process->start();
    $worker = new WorkerProcess(
        process: $process,
        connection: 'redis',
        queue: 'default',
        spawnedAt: now(),
    );
    $worker->markTerminationRequested(now()->subSeconds(31), 30);
    $pool->add($worker);

    $method = new ReflectionMethod($manager, 'enforceTerminationDeadlines');
    $method->invoke($manager);

    usleep(100_000);

    expect($process->isRunning())->toBeFalse();
});

it('does not spawn a replacement for a worker that is still draining', function () {
    // A pinned queue exists because two workers on it at once would corrupt
    // state. count() hides terminating workers so a scale-down is not
    // repeated; if the spawn path used that number, a target that came back
    // up while a worker was still finishing its job would put a second worker
    // on the queue for the length of the drain.
    $pool = new WorkerPool;

    $process = new Process([PHP_BINARY, '-r', 'sleep(30);']);
    $process->start();

    $worker = new WorkerProcess($process, 'redis', 'exports', now());
    $pool->add($worker);
    $worker->markTerminationRequested(now(), 30);

    expect($pool->count('redis', 'exports'))->toBe(0)
        ->and($pool->liveCount('redis', 'exports'))->toBe(1);

    $process->stop(0, SIGKILL);
});

it('counts a draining worker toward the host total', function () {
    // Host ceilings are about resources, and the machine is still running the
    // process regardless of whether it is on its way out.
    $pool = new WorkerPool;

    $process = new Process([PHP_BINARY, '-r', 'sleep(30);']);
    $process->start();

    $worker = new WorkerProcess($process, 'redis', 'exports', now());
    $pool->add($worker);
    $worker->markTerminationRequested(now(), 30);

    expect($pool->totalCount())->toBe(0)
        ->and($pool->liveTotalCount())->toBe(1);

    $process->stop(0, SIGKILL);
});
