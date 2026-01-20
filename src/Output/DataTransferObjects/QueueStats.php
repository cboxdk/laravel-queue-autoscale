<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\DataTransferObjects;

final readonly class QueueStats
{
    /**
     * @param  string  $slaStatus  'ok', 'warning', 'breached'
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public int $depth,
        public int $pending,
        public float $throughputPerMinute,
        public int $oldestJobAge,
        public int $slaTarget,
        public string $slaStatus,
        public int $activeWorkers,
        public int $targetWorkers,
        public int $reserved = 0,
        public int $scheduled = 0,
    ) {}

    public function key(): string
    {
        return "{$this->connection}:{$this->queue}";
    }

    public function isBreaching(): bool
    {
        return $this->slaStatus === 'breached';
    }

    public function isWarning(): bool
    {
        return $this->slaStatus === 'warning';
    }
}
