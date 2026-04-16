<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

final readonly class WorkerSpawner
{
    public function __construct(
        private SpawnLatencyTrackerContract $spawnLatencyTracker,
    ) {}

    /**
     * Spawn N queue:work worker processes
     *
     * @param  string  $connection  Queue connection name
     * @param  string  $queue  Queue name
     * @param  int  $count  Number of workers to spawn
     * @param  SpawnCompensationConfiguration  $spawnConfig  Per-queue spawn compensation settings
     * @return Collection<int, WorkerProcess> Spawned workers
     */
    public function spawn(string $connection, string $queue, int $count, SpawnCompensationConfiguration $spawnConfig): Collection
    {
        $workers = collect();

        for ($i = 0; $i < $count; $i++) {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'queue:work',
                $connection,
                '--queue='.$queue,
                '--tries='.AutoscaleConfiguration::workerTries(),
                '--max-time='.AutoscaleConfiguration::workerTimeoutSeconds(),
                '--sleep='.AutoscaleConfiguration::workerSleepSeconds(),
            ]);

            // Inject environment variables for monitoring
            $process->setEnv([
                'LARAVEL_AUTOSCALE_WORKER' => 'true',
                'AUTOSCALE_MANAGER_ID' => AutoscaleConfiguration::managerId(),
            ]);

            try {
                $process->start();

                // Record the spawn timestamp keyed by the OS PID so the spawned
                // worker can identify itself via getmypid() when its first job fires.
                // We record after start() so the PID is available.
                $pid = $process->getPid();

                if ($pid !== null) {
                    $this->spawnLatencyTracker->recordSpawn((string) $pid, $connection, $queue, $spawnConfig);
                }

                // Brief pause to allow process to fail fast if command is invalid
                usleep(50000); // 50ms

                if (! $process->isRunning()) {
                    Log::channel(AutoscaleConfiguration::logChannel())->error(
                        'Failed to spawn worker',
                        [
                            'connection' => $connection,
                            'queue' => $queue,
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
                );

                $workers->push($worker);

                Log::channel(AutoscaleConfiguration::logChannel())->info(
                    'Worker spawned',
                    [
                        'connection' => $connection,
                        'queue' => $queue,
                        'pid' => $process->getPid(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Exception during worker spawn',
                    [
                        'connection' => $connection,
                        'queue' => $queue,
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }

        return $workers;
    }
}
