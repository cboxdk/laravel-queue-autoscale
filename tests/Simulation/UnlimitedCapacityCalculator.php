<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Simulation;

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;

/**
 * Capacity calculator that always returns unlimited capacity.
 *
 * Used in simulations to isolate scaling algorithm behavior
 * from real system resource constraints.
 */
final class UnlimitedCapacityCalculator extends CapacityCalculator
{
    public function calculateMaxWorkers(int $currentWorkers = 0): CapacityCalculationResult
    {
        return new CapacityCalculationResult(
            maxWorkersByCpu: PHP_INT_MAX,
            maxWorkersByMemory: PHP_INT_MAX,
            maxWorkersByConfig: PHP_INT_MAX,
            finalMaxWorkers: PHP_INT_MAX,
            limitingFactor: 'none',
            details: [
                'cpu_explanation' => 'unlimited (simulation)',
                'memory_explanation' => 'unlimited (simulation)',
            ]
        );
    }
}
