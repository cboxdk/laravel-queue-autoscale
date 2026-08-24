<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * What one queue's completed jobs revealed about the resources a worker on it
 * actually consumes, aggregated over the metrics window.
 *
 * CPU and memory are counted separately because a job can report one without
 * the other: sample counts of zero mean that dimension was never observed, and
 * the corresponding average is meaningless rather than zero.
 */
readonly class MeasuredResourceSample
{
    public function __construct(
        public string $connection,
        public string $queue,
        public float $cpuCores,
        public int $cpuSamples,
        public float $memoryMb,
        public int $memorySamples,
    ) {}

    public function hasCpu(): bool
    {
        return $this->cpuSamples > 0;
    }

    public function hasMemory(): bool
    {
        return $this->memorySamples > 0;
    }

    public function workloadKey(): string
    {
        return "{$this->connection}:{$this->queue}";
    }
}
