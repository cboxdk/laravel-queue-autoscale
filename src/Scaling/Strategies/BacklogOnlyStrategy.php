<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Backlog-focused scaling strategy
 *
 * Scales based solely on current backlog and SLA requirements,
 * ignoring arrival rate predictions and trends.
 *
 * Best for:
 * - Batch processing workloads
 * - Queues with irregular arrival patterns
 * - Processing accumulated backlogs
 * - When you only care about clearing current work
 *
 * Not recommended for:
 * - Real-time processing requirements
 * - Proactive scaling needs
 * - Workloads requiring trend predictions
 */
final class BacklogOnlyStrategy implements ScalingStrategyContract
{
    /**
     * @var array<string, float|int|string>
     */
    private array $lastCalculation = [];

    public function __construct(
        private readonly BacklogDrainCalculator $backlog,
    ) {}

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        $backlogSize = $metrics->pending;
        $oldestJobAge = $metrics->oldestJobAge;

        // Determine average job time
        $avgJobTime = $this->determineJobTime($metrics);

        // Calculate workers needed to drain backlog within SLA
        $targetWorkers = $this->backlog->calculateRequiredWorkers(
            backlog: $backlogSize,
            oldestJobAge: $oldestJobAge,
            slaTarget: $config->sla->targetSeconds,
            avgJobTime: $avgJobTime,
            breachThreshold: AutoscaleConfiguration::breachThreshold(),
        );

        $this->lastCalculation = [
            'backlog' => $backlogSize,
            'oldest_job_age' => $oldestJobAge,
            'sla_target' => $config->sla->targetSeconds,
            'avg_job_time' => $avgJobTime,
            'target_workers' => $targetWorkers,
        ];

        return (int) ceil(max($targetWorkers, 0));
    }

    public function getLastReason(): string
    {
        if (empty($this->lastCalculation)) {
            return 'No calculation performed yet';
        }

        $calc = $this->lastCalculation;

        if ($calc['backlog'] === 0) {
            return 'No backlog to process';
        }

        return sprintf(
            'Backlog drain: %d jobs, oldest=%ds, SLA=%ds, time=%.1fs → %.1f workers',
            $calc['backlog'],
            $calc['oldest_job_age'],
            $calc['sla_target'],
            $calc['avg_job_time'],
            $calc['target_workers']
        );
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
    private function determineJobTime(QueueMetricsData $metrics): float
    {
        // Use metrics average duration if available (convert ms to seconds)
        if ($metrics->avgDuration > 0) {
            $avgDurationSeconds = $metrics->avgDuration / 1000.0;

            // Sanity check: reject unreasonably low values
            if ($avgDurationSeconds >= 0.01) {
                return $avgDurationSeconds;
            }
        }

        // Estimate from current throughput if available
        $processingRate = $metrics->throughputPerMinute / 60.0;
        if ($metrics->activeWorkers > 0 && $processingRate > 0) {
            return $metrics->activeWorkers / $processingRate;
        }

        // Fallback: assume 1 second per job
        return 1.0;
    }
}
