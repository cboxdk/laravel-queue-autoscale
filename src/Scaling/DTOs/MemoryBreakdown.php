<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

use Cbox\LaravelQueueAutoscale\Support\Coerce;

/**
 * The memory side of a capacity calculation, typed.
 *
 * `CapacityCalculationResult::$details` stays an array because its keys are
 * documented public API; this is the same data with names the compiler can
 * check, for callers inside the package.
 */
readonly class MemoryBreakdown
{
    public function __construct(
        public float $maxMemoryPercent,
        public float $currentMemoryPercent,
        public float $availableMemoryPercent,
        public float $totalMemoryMb,
        public float $workerMemoryMb,
        public string $estimateSource,
    ) {}

    /**
     * @param  array<array-key, mixed>  $details
     */
    public static function fromDetails(array $details): self
    {
        return new self(
            maxMemoryPercent: Coerce::toFloat($details['max_memory_percent'] ?? 0.0),
            currentMemoryPercent: Coerce::toFloat($details['current_memory_percent'] ?? 0.0),
            availableMemoryPercent: Coerce::toFloat($details['available_memory_percent'] ?? 0.0),
            totalMemoryMb: Coerce::toFloat($details['total_memory_mb'] ?? 0.0),
            workerMemoryMb: Coerce::toFloat($details['worker_memory_mb'] ?? 0.0),
            estimateSource: Coerce::toString($details['memory_estimate_source'] ?? null, 'unknown'),
        );
    }

    /** Memory actually in use, derived from the percentage and the total. */
    public function usedMb(): float
    {
        return round($this->totalMemoryMb * ($this->currentMemoryPercent / 100), 1);
    }

    public function freeMb(): float
    {
        return round(max($this->totalMemoryMb - $this->usedMb(), 0.0), 1);
    }
}
