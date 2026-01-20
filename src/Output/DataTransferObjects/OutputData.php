<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\DataTransferObjects;

use DateTimeImmutable;

final readonly class OutputData
{
    /**
     * @param  array<string, QueueStats>  $queueStats  Keyed by "connection:queue"
     * @param  array<int, WorkerStatus>  $workers
     * @param  array<int, JobActivity>  $recentJobs
     * @param  array<int, string>  $scalingLog  Recent scaling decision messages
     */
    public function __construct(
        public array $queueStats,
        public array $workers,
        public array $recentJobs,
        public array $scalingLog,
        public DateTimeImmutable $timestamp,
    ) {}

    public function totalWorkers(): int
    {
        return count($this->workers);
    }

    public function runningWorkers(): int
    {
        return count(array_filter($this->workers, fn (WorkerStatus $w) => $w->isRunning()));
    }

    public function hasBreachingQueues(): bool
    {
        foreach ($this->queueStats as $stats) {
            if ($stats->isBreaching()) {
                return true;
            }
        }

        return false;
    }
}
