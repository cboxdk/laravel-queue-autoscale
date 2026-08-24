<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

use Cbox\LaravelQueueAutoscale\Support\Coerce;

/**
 * The CPU side of a capacity calculation, typed.
 *
 * `CapacityCalculationResult::$details` stays an array because its keys are
 * documented public API; this is the same data with names the compiler can
 * check, for callers inside the package.
 */
readonly class CpuBreakdown
{
    public function __construct(
        public float $maxCpuPercent,
        public float $currentCpuPercent,
        public float $availableCpuPercent,
        public float $totalCores,
        public float $reserveCores,
        public float $usableCores,
        public float $workerCpuCoreEstimate,
        public string $estimateSource,
    ) {}

    /**
     * @param  array<array-key, mixed>  $details
     */
    public static function fromDetails(array $details): self
    {
        return new self(
            maxCpuPercent: Coerce::toFloat($details['max_cpu_percent'] ?? 0.0),
            currentCpuPercent: Coerce::toFloat($details['current_cpu_percent'] ?? 0.0),
            availableCpuPercent: Coerce::toFloat($details['available_cpu_percent'] ?? 0.0),
            totalCores: Coerce::toFloat($details['total_cores'] ?? 0.0),
            reserveCores: Coerce::toFloat($details['reserve_cores'] ?? 0.0),
            usableCores: Coerce::toFloat($details['usable_cores'] ?? 0.0),
            workerCpuCoreEstimate: Coerce::toFloat($details['worker_cpu_core_estimate'] ?? 0.0),
            estimateSource: Coerce::toString($details['cpu_estimate_source'] ?? null, 'unknown'),
        );
    }
}
