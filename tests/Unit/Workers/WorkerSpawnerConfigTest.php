<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;

/**
 * Per-queue worker settings were parsed into WorkerConfiguration and then
 * never read — the spawner always used the global block, so a profile's
 * tries/sleep/timeout never reached a worker. These specs pin the argv the
 * spawner builds, since that is the only place the setting becomes real.
 */
function spawnedArgv(?WorkerConfiguration $workerConfig): array
{
    $spawner = new WorkerSpawner(app(SpawnLatencyTrackerContract::class));

    $workers = $spawner->spawn(
        connection: 'redis',
        queue: 'default',
        count: 1,
        spawnConfig: new SpawnCompensationConfiguration(false, 2.0, 5, 0.2),
        workerConfig: $workerConfig,
    );

    $worker = $workers->first();
    $argv = $worker?->process->getCommandLine() ?? '';

    // Stop the child immediately — these specs care about the command line,
    // not about running a worker.
    $worker?->process->stop(0);

    return ['line' => $argv];
}

test('uses the global worker block when no per-queue config is given', function (): void {
    config()->set('queue-autoscale.workers.tries', 7);
    config()->set('queue-autoscale.workers.sleep_seconds', 9);

    $argv = spawnedArgv(null);

    expect($argv['line'])->toContain('--tries=7')
        ->and($argv['line'])->toContain('--sleep=9');
});

test('per-queue settings override the global block', function (): void {
    config()->set('queue-autoscale.workers.tries', 7);
    config()->set('queue-autoscale.workers.sleep_seconds', 9);

    $argv = spawnedArgv(new WorkerConfiguration(
        min: 1,
        max: 5,
        tries: 2,
        timeoutSeconds: 600,
        sleepSeconds: 1,
        shutdownTimeoutSeconds: 30,
    ));

    expect($argv['line'])->toContain('--tries=2')
        ->and($argv['line'])->toContain('--sleep=1')
        ->and($argv['line'])->toContain('--max-time=600')
        ->and($argv['line'])->not->toContain('--tries=7');
});
