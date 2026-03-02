<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\SystemMetrics\SystemMetrics;

final class CapacityCalculator
{
    /**
     * Cached system metrics to avoid repeated blocking measurements within
     * the same evaluation tick. CPU measurement blocks for 1 second, so
     * calling it once per queue would waste 1s * N queues per tick.
     */
    private ?float $cachedCpuPercent = null;

    private ?float $cachedMemoryPercent = null;

    private ?float $cachedTotalMemoryMb = null;

    private ?int $cachedAvailableCores = null;

    private ?float $cacheTimestamp = null;

    /**
     * How long cached metrics remain valid (seconds).
     * Should be shorter than the evaluation interval to ensure fresh data each tick.
     */
    private const CACHE_TTL_SECONDS = 4.0;

    /**
     * Calculate maximum workers with detailed capacity breakdown
     *
     * Analyzes CPU and memory constraints separately and returns
     * comprehensive breakdown showing which factor is limiting.
     *
     * System metrics are cached per evaluation tick to avoid redundant
     * blocking measurements when evaluating multiple queues.
     *
     * @param  int  $currentWorkers  Total workers currently running across all queues (for accurate capacity math)
     * @return CapacityCalculationResult Detailed capacity analysis with system-wide max workers
     */
    public function calculateMaxWorkers(int $currentWorkers = 0): CapacityCalculationResult
    {
        // Refresh system metrics if cache is stale or empty
        if (! $this->isCacheValid()) {
            $this->refreshSystemMetrics();
        }

        // If metrics refresh failed, return fallback
        if ($this->cachedAvailableCores === null) {
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

        // CPU capacity calculation
        $maxCpuPercent = AutoscaleConfiguration::maxCpuPercent();
        $currentCpuPercent = $this->cachedCpuPercent ?? 50.0;

        $availableCpuPercent = max($maxCpuPercent - $currentCpuPercent, 0);
        $reserveCores = AutoscaleConfiguration::reserveCpuCores();
        $usableCores = max($this->cachedAvailableCores - $reserveCores, 1);

        // Calculate additional workers we can add based on available CPU
        $additionalWorkersByCpu = (int) floor($usableCores * ($availableCpuPercent / 100));
        // Total capacity = current workers + additional capacity
        $maxWorkersByCpu = $currentWorkers + $additionalWorkersByCpu;

        // Memory capacity calculation
        $maxMemoryPercent = AutoscaleConfiguration::maxMemoryPercent();
        $currentMemoryPercent = $this->cachedMemoryPercent ?? 50.0;

        $availableMemoryPercent = max($maxMemoryPercent - $currentMemoryPercent, 0);
        $workerMemoryMb = AutoscaleConfiguration::workerMemoryMbEstimate();
        $totalMemoryMb = $this->cachedTotalMemoryMb ?? 4096.0;

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
                $this->cachedAvailableCores,
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
                'total_cores' => $this->cachedAvailableCores,
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

    /**
     * Invalidate the cached metrics, forcing a fresh measurement on next call.
     * Useful when evaluation tick boundaries need explicit control.
     */
    public function invalidateCache(): void
    {
        $this->cacheTimestamp = null;
    }

    private function isCacheValid(): bool
    {
        if ($this->cacheTimestamp === null) {
            return false;
        }

        return (microtime(true) - $this->cacheTimestamp) < self::CACHE_TTL_SECONDS;
    }

    private function refreshSystemMetrics(): void
    {
        $limitsResult = SystemMetrics::limits();
        if ($limitsResult->isFailure()) {
            $this->cachedAvailableCores = null;
            $this->cachedTotalMemoryMb = null;
            $this->cachedCpuPercent = null;
            $this->cachedMemoryPercent = null;
            $this->cacheTimestamp = null;

            return;
        }

        $limits = $limitsResult->getValue();
        $this->cachedAvailableCores = $limits->availableCpuCores();
        $this->cachedTotalMemoryMb = $limits->availableMemoryBytes() / (1024 * 1024);

        // CPU measurement - this is the expensive blocking call (1 second)
        $cpuUsageResult = SystemMetrics::cpuUsage(1.0);
        $this->cachedCpuPercent = $cpuUsageResult->isSuccess()
            ? $cpuUsageResult->getValue()->usagePercentage()
            : 50.0;

        // Memory measurement
        $memoryResult = SystemMetrics::memory();
        $this->cachedMemoryPercent = $memoryResult->isSuccess()
            ? $memoryResult->getValue()->usedPercentage()
            : 50.0;

        $this->cacheTimestamp = microtime(true);
    }
}
