<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\DataTransferObjects;

use DateTimeImmutable;

final readonly class JobActivity
{
    /**
     * @param  string  $status  'processing', 'processed', 'failed'
     */
    public function __construct(
        public int $workerId,
        public string $jobClass,
        public string $status,
        public ?int $durationMs,
        public DateTimeImmutable $timestamp,
    ) {}

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function shortClassName(): string
    {
        return class_basename($this->jobClass);
    }
}
