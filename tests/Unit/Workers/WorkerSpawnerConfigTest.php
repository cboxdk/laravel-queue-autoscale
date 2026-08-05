<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;

/**
 * The profile is the only worker config surface. There used to be a second,
 * global `queue-autoscale.workers` block holding the same four keys; once
 * every spawn site passed the profile's values it became dead config, so it
 * is gone. These specs pin the argv the spawner builds, which is the only
 * place these settings become real.
 */
function spawnedCommandLine(?WorkerConfiguration $workerConfig, string $queue = 'default'): string
{
    $spawner = new WorkerSpawner(app(SpawnLatencyTrackerContract::class));

    // Reads the argv the spawner builds rather than starting a process and
    // reading it back. The old form depended on `queue:work` surviving the
    // spawner's fifty-millisecond liveness check — true on a laptop, false in
    // CI, where the worker exits at once and is correctly discarded before the
    // spec can see it.
    $workerConfig ??= QueueConfiguration::fromConfig('redis', $queue)->workers;

    return implode(' ', $spawner->buildCommand('redis', $queue, $workerConfig));
}

test('falls back to the queue profile when the caller resolved none', function (): void {
    config()->set('queue-autoscale.queues.reports', [
        'workers' => ['tries' => 9, 'sleep_seconds' => 7],
    ]);

    $line = spawnedCommandLine(null, 'reports');

    expect($line)->toContain('--tries=9')
        ->and($line)->toContain('--sleep=7');
});

test('uses the worker configuration it is given', function (): void {
    $line = spawnedCommandLine(new WorkerConfiguration(
        min: 1,
        max: 5,
        tries: 2,
        maxTimeSeconds: 600,
        timeoutSeconds: 120,
        sleepSeconds: 1,
        shutdownTimeoutSeconds: 30,
    ));

    expect($line)->toContain('--tries=2')
        ->and($line)->toContain('--sleep=1');
});

test('emits the process lifetime and the job timeout as separate flags', function (): void {
    // These were one key. --max-time recycles the worker PROCESS; --timeout
    // bounds a single JOB. Conflating them meant an operator who set 3600
    // believed they had allowed hour-long jobs, while the real job timeout
    // stayed at Laravel's default.
    $line = spawnedCommandLine(new WorkerConfiguration(
        min: 1,
        max: 5,
        tries: 3,
        maxTimeSeconds: 3600,
        timeoutSeconds: 90,
        sleepSeconds: 3,
        shutdownTimeoutSeconds: 30,
    ));

    expect($line)->toContain('--max-time=3600')
        ->and($line)->toContain('--timeout=90');
});
