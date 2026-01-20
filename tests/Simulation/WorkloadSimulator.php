<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Simulation;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\HealthStats;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Simulates queue workloads over time for E2E testing
 *
 * Models realistic queue behavior including:
 * - Job arrivals (configurable patterns)
 * - Job processing by workers
 * - Backlog accumulation/drain
 * - Job aging
 */
final class WorkloadSimulator
{
    private int $currentTick = 0;

    private float $backlog = 0;

    private float $oldestJobAge = 0;

    private int $activeWorkers = 0;

    private float $totalProcessed = 0;

    private float $avgJobTime;

    /** @var array<int, float> Job ages in queue (tick when added) */
    private array $jobQueue = [];

    /** @var array<int, array{tick: int, arrivals: float, processed: float, backlog: float, workers: int, oldestAge: float}> */
    private array $history = [];

    public function __construct(
        private readonly float $baseArrivalRate = 5.0,  // jobs/second base rate
        float $avgJobTime = 1.0,  // seconds per job
        private readonly string $connection = 'redis',
        private readonly string $queue = 'default',
    ) {
        $this->avgJobTime = $avgJobTime;
    }

    /**
     * Reset simulator to initial state
     */
    public function reset(): void
    {
        $this->currentTick = 0;
        $this->backlog = 0;
        $this->oldestJobAge = 0;
        $this->activeWorkers = 0;
        $this->totalProcessed = 0;
        $this->jobQueue = [];
        $this->history = [];
    }

    /**
     * Set number of active workers (simulates autoscaler decision)
     */
    public function setWorkers(int $workers): void
    {
        $this->activeWorkers = max(0, $workers);
    }

    /**
     * Advance simulation by one tick (1 second)
     *
     * @param  float  $arrivalMultiplier  Multiplier for arrival rate (1.0 = normal, 2.0 = double, etc.)
     */
    public function tick(float $arrivalMultiplier = 1.0): void
    {
        $this->currentTick++;

        // Calculate arrivals this tick
        $arrivals = $this->baseArrivalRate * $arrivalMultiplier;

        // Add jobs to queue
        for ($i = 0; $i < (int) ceil($arrivals); $i++) {
            $this->jobQueue[] = $this->currentTick;
        }
        $this->backlog += $arrivals;

        // Calculate processing capacity
        $processingCapacity = $this->activeWorkers / $this->avgJobTime;
        $processed = min($processingCapacity, $this->backlog);

        // Remove processed jobs (oldest first)
        $jobsToRemove = (int) floor($processed);
        for ($i = 0; $i < $jobsToRemove && count($this->jobQueue) > 0; $i++) {
            array_shift($this->jobQueue);
        }

        $this->backlog = max(0, $this->backlog - $processed);
        $this->totalProcessed += $processed;

        // Update oldest job age
        if (count($this->jobQueue) > 0) {
            $oldestTick = $this->jobQueue[0];
            $this->oldestJobAge = $this->currentTick - $oldestTick;
        } else {
            $this->oldestJobAge = 0;
        }

        // Record history
        $this->history[$this->currentTick] = [
            'tick' => $this->currentTick,
            'arrivals' => $arrivals,
            'processed' => $processed,
            'backlog' => $this->backlog,
            'workers' => $this->activeWorkers,
            'oldestAge' => $this->oldestJobAge,
        ];
    }

    /**
     * Get current metrics snapshot (as would be returned by queue metrics package)
     */
    public function getMetrics(): QueueMetricsData
    {
        // Calculate throughput from recent history (last 60 ticks = 1 minute)
        $recentTicks = array_slice($this->history, -60, null, true);
        $recentProcessed = array_sum(array_column($recentTicks, 'processed'));
        $throughputPerMinute = count($recentTicks) > 0
            ? ($recentProcessed / count($recentTicks)) * 60
            : 0.0;

        $health = new HealthStats(
            status: $this->oldestJobAge > 30 ? 'warning' : 'healthy',
            score: max(0, 100 - ($this->oldestJobAge * 2)),
            depth: (int) $this->backlog,
            oldestJobAge: (int) $this->oldestJobAge,
            failureRate: 0.0,
            utilizationRate: $this->activeWorkers > 0
                ? min(1.0, ($this->backlog * $this->avgJobTime) / $this->activeWorkers)
                : 0.0,
        );

        return new QueueMetricsData(
            connection: $this->connection,
            queue: $this->queue,
            depth: (int) $this->backlog,
            pending: (int) $this->backlog,
            scheduled: 0,
            reserved: min($this->activeWorkers, (int) $this->backlog),
            oldestJobAge: (int) $this->oldestJobAge,
            ageStatus: $health->status,
            throughputPerMinute: $throughputPerMinute,
            avgDuration: $this->avgJobTime,
            failureRate: 0.0,
            utilizationRate: $health->utilizationRate,
            activeWorkers: $this->activeWorkers,
            driver: 'redis',
            health: $health,
            calculatedAt: Carbon::now(),
        );
    }

    /**
     * Get current simulation state
     *
     * @return array{tick: int, backlog: float, oldestJobAge: float, workers: int, totalProcessed: float}
     */
    public function getState(): array
    {
        return [
            'tick' => $this->currentTick,
            'backlog' => $this->backlog,
            'oldestJobAge' => $this->oldestJobAge,
            'workers' => $this->activeWorkers,
            'totalProcessed' => $this->totalProcessed,
        ];
    }

    /**
     * Get simulation history
     *
     * @return array<int, array{tick: int, arrivals: float, processed: float, backlog: float, workers: int, oldestAge: float}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Get current tick
     */
    public function getCurrentTick(): int
    {
        return $this->currentTick;
    }

    /**
     * Get current backlog
     */
    public function getBacklog(): float
    {
        return $this->backlog;
    }

    /**
     * Get oldest job age
     */
    public function getOldestJobAge(): float
    {
        return $this->oldestJobAge;
    }

    /**
     * Get active workers
     */
    public function getActiveWorkers(): int
    {
        return $this->activeWorkers;
    }

    /**
     * Calculate SLA compliance (percentage of time oldest job was under SLA)
     */
    public function calculateSlaCompliance(int $slaSeconds): float
    {
        if (count($this->history) === 0) {
            return 100.0;
        }

        $compliantTicks = 0;
        foreach ($this->history as $tick) {
            if ($tick['oldestAge'] <= $slaSeconds) {
                $compliantTicks++;
            }
        }

        return ($compliantTicks / count($this->history)) * 100;
    }

    /**
     * Calculate average backlog over simulation
     */
    public function calculateAverageBacklog(): float
    {
        if (count($this->history) === 0) {
            return 0.0;
        }

        return array_sum(array_column($this->history, 'backlog')) / count($this->history);
    }

    /**
     * Calculate average workers used
     */
    public function calculateAverageWorkers(): float
    {
        if (count($this->history) === 0) {
            return 0.0;
        }

        return array_sum(array_column($this->history, 'workers')) / count($this->history);
    }

    /**
     * Get peak backlog during simulation
     */
    public function getPeakBacklog(): float
    {
        if (count($this->history) === 0) {
            return 0.0;
        }

        return max(array_column($this->history, 'backlog'));
    }

    /**
     * Get peak oldest job age during simulation
     */
    public function getPeakJobAge(): float
    {
        if (count($this->history) === 0) {
            return 0.0;
        }

        return max(array_column($this->history, 'oldestAge'));
    }
}
