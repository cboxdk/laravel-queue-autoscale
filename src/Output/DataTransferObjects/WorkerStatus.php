<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\DataTransferObjects;

final readonly class WorkerStatus
{
    /**
     * @param  string  $status  'running', 'idle', 'paused', 'dead'
     */
    public function __construct(
        public int $id,
        public ?int $pid,
        public string $connection,
        public string $queue,
        public string $status,
        public int $uptimeSeconds,
        public ?string $currentJob = null,
        public int $jobsProcessed = 0,
        public float $idlePercentage = 0.0,
        public ?float $memoryMb = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isDead(): bool
    {
        return $this->status === 'dead';
    }

    public function key(): string
    {
        return "{$this->connection}:{$this->queue}";
    }
}
