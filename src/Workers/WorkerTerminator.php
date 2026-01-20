<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Illuminate\Support\Facades\Log;

final readonly class WorkerTerminator
{
    /**
     * Terminate a worker process gracefully (SIGTERM then SIGKILL)
     *
     * @param  WorkerProcess  $worker  Worker to terminate
     * @return bool True if graceful shutdown, false if forced
     */
    public function terminate(WorkerProcess $worker): bool
    {
        $pid = $worker->pid();

        if ($pid === null || ! $worker->isRunning()) {
            return true;
        }

        $timeout = AutoscaleConfiguration::shutdownTimeoutSeconds();

        // 1. Send SIGTERM for graceful shutdown
        if (! posix_kill($pid, SIGTERM)) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Failed to send SIGTERM to worker',
                ['pid' => $pid]
            );

            return false;
        }

        // 2. Wait for graceful completion
        $deadline = time() + $timeout;
        while ($worker->isRunning() && time() < $deadline) {
            usleep(100_000); // 100ms
        }

        // 3. Force kill if still running
        if ($worker->isRunning()) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Worker did not stop gracefully, sending SIGKILL',
                ['pid' => $pid]
            );

            posix_kill($pid, SIGKILL);

            return false; // Forced termination
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Worker terminated gracefully',
            ['pid' => $pid]
        );

        return true; // Graceful termination
    }
}
