<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Symfony\Component\Process\Process;

/**
 * Per-queue workers.shutdown_timeout_seconds was parsed into
 * WorkerConfiguration and then never read, so a queue whose jobs genuinely
 * need longer to finish could not be given more time.
 */
function resolveTimeout(WorkerProcess $worker): int
{
    $method = new ReflectionMethod(WorkerTerminator::class, 'shutdownTimeoutFor');

    return $method->invoke(new WorkerTerminator, $worker);
}

function idleWorker(string $queue = 'default', ?string $group = null): WorkerProcess
{
    return new WorkerProcess(new Process([PHP_BINARY, '-r', 'exit(0);']), 'redis', $queue, now(), $group);
}

beforeEach(function (): void {
    config()->set('queue-autoscale.workers.shutdown_timeout_seconds', 30);
});

test('uses the profile window when the queue sets none of its own', function (): void {
    // Every shipped profile sets shutdown_timeout_seconds, so an unconfigured
    // queue gets its profile's value rather than the global block's.
    expect(resolveTimeout(idleWorker()))->toBe(30);
});

test('honours a per-queue window', function (): void {
    config()->set('queue-autoscale.queues.slow-reports', [
        'workers' => ['shutdown_timeout_seconds' => 300],
    ]);

    expect(resolveTimeout(idleWorker('slow-reports')))->toBe(300);
});

test('group workers use the global window', function (): void {
    // A group worker polls a comma-separated list, which is not a
    // configurable queue name.
    config()->set('queue-autoscale.workers.shutdown_timeout_seconds', 45);

    expect(resolveTimeout(idleWorker('email,sms', group: 'notifications')))->toBe(45)
        ->and(resolveTimeout(idleWorker('email,sms')))->toBe(45);
});

test('a whole-pool shutdown waits for the slowest queue, not the shortest', function (): void {
    // One shared deadline covers every worker, so it must be the longest any
    // of them asked for — the shortest would cut the slow queue off mid-job.
    config()->set('queue-autoscale.queues.quick', [
        'workers' => ['shutdown_timeout_seconds' => 1],
    ]);
    config()->set('queue-autoscale.queues.slow-reports', [
        'workers' => ['shutdown_timeout_seconds' => 2],
    ]);

    $fast = idleWorker('quick');
    $slow = idleWorker('slow-reports');

    // Both processes already exited, so terminateAll returns immediately —
    // what is asserted here is the resolution, not the wait.
    expect(resolveTimeout($fast))->toBe(1)
        ->and(resolveTimeout($slow))->toBe(2)
        ->and((new WorkerTerminator)->terminateAll([$fast, $slow]))->toBe(0);
});
