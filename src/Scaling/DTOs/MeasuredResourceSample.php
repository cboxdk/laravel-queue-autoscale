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
 *
 * Every field defaults, so `new MeasuredResourceSample` is a valid "nothing
 * observed" instance — readonly means it cannot be mocked, and it is returned by
 * a public method a consumer may want to stub.
 */
readonly class MeasuredResourceSample
{
    public function __construct(
        public string $connection = '',
        public string $queue = '',
        public float $cpuCores = 0.0,
        public int $cpuSamples = 0,
        public float $memoryMb = 0.0,
        public int $memorySamples = 0,
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
