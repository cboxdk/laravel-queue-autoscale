<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\SystemMetrics\SystemMetrics;

final readonly class CapacityCalculator
{
    /**
     * Calculate maximum workers with detailed capacity breakdown
     *
     * Analyzes CPU and memory constraints separately and returns
     * comprehensive breakdown showing which factor is limiting.
     *
     * @param  int  $currentWorkers  Number of workers currently running (to add to available capacity)
     * @return CapacityCalculationResult Detailed capacity analysis
     */
    public function calculateMaxWorkers(int $currentWorkers = 0): CapacityCalculationResult
    {
        $limitsResult = SystemMetrics::limits();
        if ($limitsResult->isFailure()) {
            // Fallback: if can't get limits, allow conservative amount
            return new CapacityCalculationResult(
                maxWorkersByCpu: 5,
                maxWorkersByMemory: 5,
                maxWorkersByConfig: PHP_INT_MAX,
                finalMaxWorkers: 5,
                limitingFactor: 'system_metrics_unavailable',
                details: [
                    'cpu_explanation' => 'system metrics unavailable - using fallback',
                    'memory_explanation' => 'system metrics unavailable - using fallback',
                    'error' => 'Failed to retrieve system limits',
                ]
            );
        }

        $limits = $limitsResult->getValue();

        // CPU capacity calculation
        $maxCpuPercent = AutoscaleConfiguration::maxCpuPercent();
        // Use 1s interval for accurate CPU measurement (0.1s includes measurement overhead)
        $cpuUsageResult = SystemMetrics::cpuUsage(1.0);

        $currentCpuPercent = $cpuUsageResult->isSuccess()
            ? $cpuUsageResult->getValue()->usagePercentage()
            : 50.0;

        $availableCpuPercent = max($maxCpuPercent - $currentCpuPercent, 0);
        $reserveCores = AutoscaleConfiguration::reserveCpuCores();
        $usableCores = max($limits->availableCpuCores() - $reserveCores, 1);

        // Calculate additional workers we can add based on available CPU
        $additionalWorkersByCpu = (int) floor($usableCores * ($availableCpuPercent / 100));
        // Total capacity = current workers + additional capacity
        $maxWorkersByCpu = $currentWorkers + $additionalWorkersByCpu;

        // Memory capacity calculation
        $maxMemoryPercent = AutoscaleConfiguration::maxMemoryPercent();
        $memoryResult = SystemMetrics::memory();

        $currentMemoryPercent = $memoryResult->isSuccess()
            ? $memoryResult->getValue()->usedPercentage()
            : 50.0;

        $availableMemoryPercent = max($maxMemoryPercent - $currentMemoryPercent, 0);
        $workerMemoryMb = AutoscaleConfiguration::workerMemoryMbEstimate();
        $totalMemoryMb = $limits->availableMemoryBytes() / (1024 * 1024);

        // Calculate additional workers we can add based on available memory
        $additionalWorkersByMemory = (int) floor(
            ($totalMemoryMb * ($availableMemoryPercent / 100)) / $workerMemoryMb
        );
        // Total capacity = current workers + additional capacity
        $maxWorkersByMemory = $currentWorkers + $additionalWorkersByMemory;

        // Determine limiting factor and final capacity
        $finalMaxWorkers = max(min($maxWorkersByCpu, $maxWorkersByMemory), 0);

        $limitingFactor = match (true) {
            $maxWorkersByCpu < $maxWorkersByMemory => 'cpu',
            $maxWorkersByMemory < $maxWorkersByCpu => 'memory',
            default => 'balanced', // Both are equal
        };

        // Build detailed explanation
        $details = [
            'cpu_explanation' => sprintf(
                '%d%% of %d cores, current usage: %.1f%%',
                (int) $maxCpuPercent,
                $limits->availableCpuCores(),
                $currentCpuPercent
            ),
            'memory_explanation' => sprintf(
                '%.1fGB available, %dMB/worker',
                ($totalMemoryMb * ($availableMemoryPercent / 100)) / 1024,
                $workerMemoryMb
            ),
            'cpu_details' => [
                'max_cpu_percent' => $maxCpuPercent,
                'current_cpu_percent' => $currentCpuPercent,
                'available_cpu_percent' => $availableCpuPercent,
                'total_cores' => $limits->availableCpuCores(),
                'reserve_cores' => $reserveCores,
                'usable_cores' => $usableCores,
            ],
            'memory_details' => [
                'max_memory_percent' => $maxMemoryPercent,
                'current_memory_percent' => $currentMemoryPercent,
                'available_memory_percent' => $availableMemoryPercent,
                'total_memory_mb' => $totalMemoryMb,
                'worker_memory_mb' => $workerMemoryMb,
            ],
        ];

        return new CapacityCalculationResult(
            maxWorkersByCpu: $maxWorkersByCpu,
            maxWorkersByMemory: $maxWorkersByMemory,
            maxWorkersByConfig: PHP_INT_MAX, // Will be set by ScalingEngine
            finalMaxWorkers: $finalMaxWorkers,
            limitingFactor: $limitingFactor,
            details: $details
        );
    }
}
