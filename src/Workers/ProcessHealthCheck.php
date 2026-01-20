<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

final readonly class ProcessHealthCheck
{
    /**
     * Check if a process is still alive
     */
    public function isAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    /**
     * Check if a worker process is healthy
     */
    public function isHealthy(WorkerProcess $worker): bool
    {
        if ($worker->isDead()) {
            return false;
        }

        $pid = $worker->pid();

        if ($pid === null) {
            return false;
        }

        return $this->isAlive($pid);
    }
}
