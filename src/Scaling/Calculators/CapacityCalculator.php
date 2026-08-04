<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\SystemMetrics\DTO\Metrics\Cpu\CpuSnapshot;
use Cbox\SystemMetrics\SystemMetrics;

class CapacityCalculator
{
    /**
     * Cached system metrics so a tick that evaluates many queues reads the
     * host once rather than once per queue.
     */
    private ?float $cachedCpuPercent = null;

    private ?float $cachedMemoryPercent = null;

    private ?float $cachedTotalMemoryMb = null;

    private ?float $cachedAvailableCores = null;

    private ?float $cacheTimestamp = null;

    /**
     * Previous CPU counter snapshot, diffed against the current one to get a
     * usage percentage without sleeping. See measureCpuPercent().
     */
    private ?CpuSnapshot $previousCpuSnapshot = null;

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
     * System metrics are cached per evaluation tick, so evaluating many
     * queues reads the host once.
     *
     * @param  int  $currentWorkers  Total workers currently running across all queues (for accurate capacity math)
     * @return CapacityCalculationResult Detailed capacity analysis with system-wide max workers
     */
    public function calculateMaxWorkers(int $currentWorkers, ResourceEstimate $estimate): CapacityCalculationResult
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
        $usableCores = max($this->cachedAvailableCores - $reserveCores, 0);

        $workerCpuCoreEstimate = max($estimate->cpuCoresPerWorker, 0.01);
        $cpuEstimateSource = $estimate->cpuSource->value;

        $availableCoreEquivalents = $usableCores * ($availableCpuPercent / 100);
        $additionalWorkersByCpu = (int) floor($availableCoreEquivalents / $workerCpuCoreEstimate);
        $maxWorkersByCpu = $currentWorkers + $additionalWorkersByCpu;

        // Memory capacity calculation
        $maxMemoryPercent = AutoscaleConfiguration::maxMemoryPercent();
        $currentMemoryPercent = $this->cachedMemoryPercent ?? 50.0;

        $availableMemoryPercent = max($maxMemoryPercent - $currentMemoryPercent, 0);
        $workerMemoryMb = max($estimate->memoryMbPerWorker, 1.0);
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
                'worker_cpu_core_estimate' => $workerCpuCoreEstimate,
                'cpu_estimate_source' => $cpuEstimateSource,
            ],
            'memory_details' => [
                'max_memory_percent' => $maxMemoryPercent,
                'current_memory_percent' => $currentMemoryPercent,
                'available_memory_percent' => $availableMemoryPercent,
                'total_memory_mb' => $totalMemoryMb,
                'worker_memory_mb' => $workerMemoryMb,
                'memory_estimate_source' => $estimate->memorySource->value,
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

    /**
     * CPU usage since the previous evaluation tick.
     *
     * CPU counters are cumulative, so a usage percentage needs two snapshots
     * with time between them. SystemMetrics::cpuUsage() gets that time by
     * sleeping — a full blocking second inside the manager's control loop,
     * every cycle. On the default 5-second interval that burned a fifth of the
     * manager's wall clock and left every scaling decision computed from
     * metrics a second out of date, while making intervals below ~1.5s
     * impossible.
     *
     * Holding the previous snapshot gets the same measurement for free: the
     * gap between ticks is both cheaper AND a longer, steadier sample window
     * than the one-second sleep it replaces.
     *
     * The first tick of a process has nothing to diff against, so it falls
     * back to the neutral 50% the failure path already used rather than
     * reintroducing the sleep. One slightly-off cycle at start-up is a better
     * trade than a permanent per-cycle stall.
     */
    private function measureCpuPercent(): float
    {
        $snapshotResult = SystemMetrics::cpu();

        if ($snapshotResult->isFailure()) {
            return $this->cachedCpuPercent ?? 50.0;
        }

        $snapshot = $snapshotResult->getValue();
        $previous = $this->previousCpuSnapshot;
        $this->previousCpuSnapshot = $snapshot;

        if ($previous === null) {
            return 50.0;
        }

        $delta = CpuSnapshot::calculateDelta($previous, $snapshot);

        // Two snapshots taken within the same instant carry no signal; keep the
        // last known value rather than reporting a meaningless 0%.
        if ($delta->durationSeconds <= 0.0) {
            return $this->cachedCpuPercent ?? 50.0;
        }

        return $delta->usagePercentage();
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

        $this->cachedCpuPercent = $this->measureCpuPercent();

        // Memory measurement
        $memoryResult = SystemMetrics::memory();
        $this->cachedMemoryPercent = $memoryResult->isSuccess()
            ? $memoryResult->getValue()->usedPercentage()
            : 50.0;

        $this->cacheTimestamp = microtime(true);
    }
}
