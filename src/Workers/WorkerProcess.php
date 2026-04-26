<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

final class WorkerProcess
{
    /**
     * @param  string  $queue  For per-queue workers this is the queue name; for group workers it is the
     *                         comma-separated queue list exactly as passed to `queue:work --queue=`.
     * @param  string|null  $group  Name of the group this worker belongs to, or null for per-queue workers.
     */
    public function __construct(
        public readonly Process $process,
        public readonly string $connection,
        public readonly string $queue,
        public readonly Carbon $spawnedAt,
        public readonly ?string $group = null,
    ) {}

    public function pid(): ?int
    {
        return $this->process->getPid();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function isDead(): bool
    {
        return ! $this->process->isRunning();
    }

    public function uptimeSeconds(): int
    {
        return (int) $this->spawnedAt->diffInSeconds(now());
    }

    /**
     * Matches a per-queue worker. Group workers are matched via matchesGroup().
     */
    public function matches(string $connection, string $queue): bool
    {
        return $this->group === null
            && $this->connection === $connection
            && $this->queue === $queue;
    }

    public function matchesGroup(string $connection, string $group): bool
    {
        return $this->group === $group && $this->connection === $connection;
    }

    public function isGroupWorker(): bool
    {
        return $this->group !== null;
    }

    public function getIncrementalOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    public function getIncrementalErrorOutput(): string
    {
        return $this->process->getIncrementalErrorOutput();
    }
}
