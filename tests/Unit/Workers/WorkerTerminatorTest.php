<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Symfony\Component\Process\Process;

it('requests worker termination without waiting for the shutdown timeout', function () {
    config()->set('queue-autoscale.workers.shutdown_timeout_seconds', 30);

    $process = new Process(['sleep', '5']);
    $process->start();

    $worker = new WorkerProcess(
        process: $process,
        connection: 'redis',
        queue: 'default',
        spawnedAt: now(),
    );

    $start = microtime(true);
    $result = (new WorkerTerminator)->requestTermination($worker);
    $elapsed = microtime(true) - $start;

    expect($result)->toBeTrue()
        ->and($worker->isTerminating())->toBeTrue()
        ->and($elapsed)->toBeLessThan(1.0);

    $process->stop(0);
});
