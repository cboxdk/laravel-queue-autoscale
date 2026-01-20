<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

final class WorkerProcess
{
    public function __construct(
        public readonly Process $process,
        public readonly string $connection,
        public readonly string $queue,
        public readonly Carbon $spawnedAt,
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

    public function matches(string $connection, string $queue): bool
    {
        return $this->connection === $connection && $this->queue === $queue;
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
