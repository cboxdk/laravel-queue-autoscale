<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Conservative scaling strategy with safety buffers
 *
 * Uses the same multi-algorithm approach as PredictiveStrategy but adds
 * safety buffers to ensure SLA compliance even under uncertainty.
 *
 * Differences from PredictiveStrategy:
 * - Adds 25% buffer to calculated worker count
 * - Uses 75% breach threshold (more proactive)
 * - Rounds up more aggressively
 *
 * Best for:
 * - Mission-critical queues
 * - Strict SLA requirements
 * - Workloads where over-provisioning is acceptable
 * - When cost is less important than reliability
 *
 * Not recommended for:
 * - Cost-sensitive environments
 * - Queues with low priority
 * - When resource efficiency is critical
 */
final class ConservativeStrategy implements ScalingStrategyContract
{
    private const SAFETY_BUFFER = 1.25; // 25% extra workers

    private const BREACH_THRESHOLD = 0.75; // Act at 75% of SLA

    /**
     * @var array<string, float|int|string>
     */
    private array $lastCalculation = [];

    public function __construct(
        private readonly LittlesLawCalculator $littles,
        private readonly BacklogDrainCalculator $backlog,
    ) {}

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        // Convert throughput_per_minute to jobs/second
        $processingRate = $metrics->throughputPerMinute / 60.0;
        $backlogSize = $metrics->pending;
        $oldestJobAge = $metrics->oldestJobAge;

        // Determine average job time
        $avgJobTime = $this->determineJobTime($metrics, $processingRate, $metrics->activeWorkers);

        // 1. Rate-based calculation
        $steadyStateWorkers = $this->littles->calculate($processingRate, $avgJobTime);

        // 2. Backlog-based calculation with conservative threshold
        $backlogDrainWorkers = $this->backlog->calculateRequiredWorkers(
            backlog: $backlogSize,
            oldestJobAge: $oldestJobAge,
            slaTarget: $config->maxPickupTimeSeconds,
            avgJobTime: $avgJobTime,
            breachThreshold: self::BREACH_THRESHOLD, // More proactive than default
        );

        // Take maximum, then add safety buffer
        $baseWorkers = max($steadyStateWorkers, $backlogDrainWorkers);
        $targetWorkers = $baseWorkers * self::SAFETY_BUFFER;

        $this->lastCalculation = [
            'steady_state' => $steadyStateWorkers,
            'backlog_drain' => $backlogDrainWorkers,
            'base_workers' => $baseWorkers,
            'target_workers' => $targetWorkers,
            'processing_rate' => $processingRate,
            'avg_job_time' => $avgJobTime,
            'backlog' => $backlogSize,
        ];

        return (int) ceil(max($targetWorkers, 0));
    }

    public function getLastReason(): string
    {
        if (empty($this->lastCalculation)) {
            return 'No calculation performed yet';
        }

        $calc = $this->lastCalculation;
        $parts = [];

        if ($calc['backlog_drain'] > 0) {
            $parts[] = sprintf(
                'backlog=%d requires %.1f workers',
                $calc['backlog'],
                $calc['backlog_drain']
            );
        }

        if (empty($parts)) {
            $parts[] = sprintf(
                'steady state: rate=%.2f/s × time=%.1fs = %.1f workers',
                $calc['processing_rate'],
                $calc['avg_job_time'],
                $calc['steady_state']
            );
        }

        $parts[] = sprintf(
            '%.1f base + %.0f%% buffer = %.1f workers',
            $calc['base_workers'],
            (self::SAFETY_BUFFER - 1.0) * 100,
            $calc['target_workers']
        );

        return implode('; ', $parts);
    }

    public function getLastPrediction(): ?float
    {
        if (empty($this->lastCalculation)) {
            return null;
        }

        $calc = $this->lastCalculation;

        if ($calc['target_workers'] <= 0 || $calc['backlog'] === 0) {
            return 0.0;
        }

        // Time to process backlog with target workers
        $jobsPerWorker = (float) $calc['backlog'] / (float) $calc['target_workers'];
        $timeToProcess = $jobsPerWorker * (float) $calc['avg_job_time'];

        return $timeToProcess;
    }

    /**
     * Determine average job time from metrics
     */
    private function determineJobTime(
        QueueMetricsData $metrics,
        float $processingRate,
        int $activeWorkers
    ): float {
        // Use metrics average duration if available (convert ms to seconds)
        if ($metrics->avgDuration > 0) {
            $avgDurationSeconds = $metrics->avgDuration / 1000.0;

            // Sanity check: reject unreasonably low values
            if ($avgDurationSeconds >= 0.01) {
                return $avgDurationSeconds;
            }
        }

        // Estimate from throughput and active workers
        if ($activeWorkers > 0 && $processingRate > 0) {
            return $activeWorkers / $processingRate;
        }

        // Fallback: assume 1 second per job
        return 1.0;
    }
}
