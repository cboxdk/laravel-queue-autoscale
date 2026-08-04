<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

readonly class WorkerSpawner
{
    public function __construct(
        private SpawnLatencyTrackerContract $spawnLatencyTracker,
    ) {}

    /**
     * Spawn N queue:work worker processes.
     *
     * @param  string  $connection  Queue connection name
     * @param  string  $queue  Queue name OR comma-separated queue list (for group workers)
     * @param  int  $count  Number of workers to spawn
     * @param  SpawnCompensationConfiguration  $spawnConfig  Spawn compensation settings
     * @param  string|null  $group  Optional group name — tags spawned WorkerProcess instances
     * @param  WorkerConfiguration|null  $workerConfig  Per-queue worker settings; falls back to the global block
     * @return Collection<int, WorkerProcess> Spawned workers
     */
    public function spawn(
        string $connection,
        string $queue,
        int $count,
        SpawnCompensationConfiguration $spawnConfig,
        ?string $group = null,
        ?WorkerConfiguration $workerConfig = null,
    ): Collection {
        $workers = new Collection;

        // Per-queue settings when the caller resolved them, otherwise the
        // global block. These used to be parsed per queue and then silently
        // ignored — the spawner only ever read the global values, so a
        // profile's tries/sleep/timeout never reached a worker.
        $tries = $workerConfig !== null ? $workerConfig->tries : AutoscaleConfiguration::workerTries();
        $maxTime = $workerConfig !== null ? $workerConfig->timeoutSeconds : AutoscaleConfiguration::workerTimeoutSeconds();
        $sleep = $workerConfig !== null ? $workerConfig->sleepSeconds : AutoscaleConfiguration::workerSleepSeconds();

        for ($i = 0; $i < $count; $i++) {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'queue:work',
                $connection,
                '--queue='.$queue,
                '--tries='.$tries,
                '--max-time='.$maxTime,
                '--sleep='.$sleep,
            ]);

            // Inject environment variables for monitoring
            $env = [
                'LARAVEL_AUTOSCALE_WORKER' => 'true',
                'AUTOSCALE_MANAGER_ID' => AutoscaleConfiguration::managerId(),
            ];

            if ($group !== null) {
                $env['AUTOSCALE_WORKER_GROUP'] = $group;
            }

            $process->setEnv($env);

            try {
                $process->start();

                // Record the spawn timestamp keyed by the OS PID so the spawned
                // worker can identify itself via getmypid() when its first job fires.
                // We record after start() so the PID is available.
                $pid = $process->getPid();

                if ($pid !== null) {
                    // For group workers the "queue" identity used by the spawn tracker
                    // is the group name so pickup-time EMA is shared across member queues.
                    $trackingQueue = $group ?? $queue;
                    $this->spawnLatencyTracker->recordSpawn((string) $pid, $connection, $trackingQueue, $spawnConfig);
                }

                // Brief pause to allow process to fail fast if command is invalid
                usleep(50000); // 50ms

                if (! $process->isRunning()) {
                    Log::channel(AutoscaleConfiguration::logChannel())->error(
                        'Failed to spawn worker',
                        [
                            'connection' => $connection,
                            'queue' => $queue,
                            'group' => $group,
                            'error' => $process->getErrorOutput(),
                            'output' => $process->getOutput(),
                        ]
                    );

                    continue;
                }

                $worker = new WorkerProcess(
                    process: $process,
                    connection: $connection,
                    queue: $queue,
                    spawnedAt: now(),
                    group: $group,
                );

                $workers->push($worker);

                Log::channel(AutoscaleConfiguration::logChannel())->info(
                    'Worker spawned',
                    [
                        'connection' => $connection,
                        'queue' => $queue,
                        'group' => $group,
                        'pid' => $process->getPid(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Exception during worker spawn',
                    [
                        'connection' => $connection,
                        'queue' => $queue,
                        'group' => $group,
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }

        return $workers;
    }
}
