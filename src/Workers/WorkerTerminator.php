<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Illuminate\Support\Facades\Log;

readonly class WorkerTerminator
{
    /**
     * Terminate a whole pool under ONE shared deadline.
     *
     * Terminating serially — SIGTERM a worker, wait up to
     * shutdown_timeout_seconds for it, then move to the next — costs
     * workers x timeout in the worst case. Twenty busy workers on the default
     * 30-second timeout is up to ten minutes, so systemd's TimeoutStopSec or
     * Kubernetes' terminationGracePeriodSeconds SIGKILLs the manager long
     * before it reaches the end of the list. Every worker it had not got to is
     * then orphaned: its PID lived only in the manager's memory, so nothing
     * reaps it and it runs until --max-time. The supervisor restarts the
     * manager, which spawns a fresh set on top of the orphans.
     *
     * Signalling everything first and then polling one deadline bounds total
     * shutdown at the timeout regardless of pool size.
     *
     * @param  iterable<WorkerProcess>  $workers
     * @param  (callable(WorkerProcess): void)|null  $onTerminate  Invoked per worker as it is signalled.
     * @return int Workers that had to be force-killed.
     */
    public function terminateAll(iterable $workers, ?callable $onTerminate = null): int
    {
        $timeout = AutoscaleConfiguration::shutdownTimeoutSeconds();
        $pending = [];

        foreach ($workers as $worker) {
            if ($onTerminate !== null) {
                $onTerminate($worker);
            }

            $pid = $worker->pid();

            if ($pid === null || ! $worker->isRunning()) {
                continue;
            }

            if (posix_kill($pid, SIGTERM)) {
                $pending[$pid] = $worker;

                continue;
            }

            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Failed to send SIGTERM to worker',
                ['pid' => $pid]
            );
        }

        $deadline = time() + $timeout;

        while ($pending !== [] && time() < $deadline) {
            foreach ($pending as $pid => $worker) {
                if (! $worker->isRunning()) {
                    unset($pending[$pid]);
                }
            }

            if ($pending !== []) {
                usleep(100_000);
            }
        }

        foreach ($pending as $pid => $worker) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Worker did not stop within the shutdown deadline, sending SIGKILL',
                ['pid' => $pid]
            );

            posix_kill($pid, SIGKILL);
        }

        return count($pending);
    }

    public function requestTermination(WorkerProcess $worker): bool
    {
        if ($worker->isTerminating()) {
            return true;
        }

        $pid = $worker->pid();

        if ($pid === null || ! $worker->isRunning()) {
            return true;
        }

        if (! posix_kill($pid, SIGTERM)) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Failed to send SIGTERM to worker',
                ['pid' => $pid]
            );

            return false;
        }

        $worker->markTerminationRequested(now(), AutoscaleConfiguration::shutdownTimeoutSeconds());

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Worker termination requested',
            ['pid' => $pid]
        );

        return true;
    }

    public function forceKillIfExpired(WorkerProcess $worker): bool
    {
        if (! $worker->isTerminating()) {
            return false;
        }

        $pid = $worker->pid();

        if ($pid === null || ! $worker->isRunning()) {
            return false;
        }

        if (! $worker->terminationDeadlinePassed(now())) {
            return false;
        }

        Log::channel(AutoscaleConfiguration::logChannel())->warning(
            'Worker termination deadline exceeded, sending SIGKILL',
            ['pid' => $pid]
        );

        posix_kill($pid, SIGKILL);

        return true;
    }

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
