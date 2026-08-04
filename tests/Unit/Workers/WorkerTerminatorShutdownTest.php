<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Symfony\Component\Process\Process;

/**
 * Terminating serially cost workers x shutdown_timeout_seconds in the worst
 * case, so a supervisor's stop deadline killed the manager before it reached
 * the end of the pool and every worker it had not signalled was orphaned.
 * What matters is that total shutdown is bounded by ONE deadline no matter
 * how many workers there are.
 */
function stubbornWorker(): WorkerProcess
{
    // Traps SIGTERM and keeps looping, so only SIGKILL at the deadline ends it.
    $process = new Process([
        PHP_BINARY, '-r',
        'pcntl_async_signals(true); pcntl_signal(SIGTERM, static fn () => null); echo "ready\n"; while (true) { usleep(50000); }',
    ]);
    $process->start();

    // Wait until the child has actually exec'd, so the signal is not sent
    // into a process that has not installed its handler yet.
    $deadline = microtime(true) + 5.0;
    while ($process->getIncrementalOutput() === '' && microtime(true) < $deadline) {
        usleep(20_000);
    }

    return new WorkerProcess($process, 'redis', 'default', now());
}

beforeEach(function (): void {
    config()->set('queue-autoscale.workers.shutdown_timeout_seconds', 1);
    $this->terminator = new WorkerTerminator;
});

test('bounds total shutdown by one deadline, not one per worker', function (): void {
    $workers = [stubbornWorker(), stubbornWorker(), stubbornWorker()];

    $start = microtime(true);
    $forced = $this->terminator->terminateAll($workers);
    $elapsed = microtime(true) - $start;

    // Serial termination would have cost 3 x 1s; one shared deadline costs 1s.
    // The generous ceiling keeps this about the bound, not about the machine.
    expect($forced)->toBe(3)
        ->and($elapsed)->toBeLessThan(2.5);
})->skip(! extension_loaded('pcntl') || ! extension_loaded('posix'), 'requires pcntl and posix');

test('force-kills the workers that outlived the deadline', function (): void {
    $worker = stubbornWorker();

    $this->terminator->terminateAll([$worker]);

    // SIGKILL is asynchronous — the kernel has not necessarily reaped the
    // child by the time terminateAll returns, so poll rather than assert into
    // the race.
    $deadline = microtime(true) + 5.0;
    while ($worker->isRunning() && microtime(true) < $deadline) {
        usleep(20_000);
    }

    expect($worker->isRunning())->toBeFalse();
})->skip(! extension_loaded('pcntl') || ! extension_loaded('posix'), 'requires pcntl and posix');

test('reports each worker as it is signalled', function (): void {
    $worker = stubbornWorker();
    $seen = [];

    $this->terminator->terminateAll([$worker], function (WorkerProcess $w) use (&$seen): void {
        $seen[] = $w->pid();
    });

    expect($seen)->toHaveCount(1)
        ->and($seen[0])->toBeInt();
})->skip(! extension_loaded('pcntl') || ! extension_loaded('posix'), 'requires pcntl and posix');

test('tolerates an empty pool', function (): void {
    expect($this->terminator->terminateAll([]))->toBe(0);
});

test('skips workers that already exited', function (): void {
    $process = new Process([PHP_BINARY, '-r', 'exit(0);']);
    $process->start();
    $process->wait();

    expect($this->terminator->terminateAll([new WorkerProcess($process, 'redis', 'default', now())]))->toBe(0);
});
