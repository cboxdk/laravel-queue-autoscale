<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Simple rate-based scaling using Little's Law only
 *
 * Best for:
 * - Stable, predictable workloads
 * - Queues with consistent processing patterns
 * - Low-complexity scaling requirements
 * - When you want minimal overhead and simple logic
 *
 * Not recommended for:
 * - Bursty traffic patterns
 * - Strict SLA requirements
 * - Workloads requiring proactive scaling
 */
final class SimpleRateStrategy implements ScalingStrategyContract
{
    /**
     * @var array<string, float|int|string>
     */
    private array $lastCalculation = [];

    public function __construct(
        private readonly LittlesLawCalculator $littles,
    ) {}

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        // Convert throughput_per_minute to jobs/second
        $processingRate = $metrics->throughputPerMinute / 60.0;

        // Determine average job time
        $avgJobTime = $this->determineJobTime($metrics);

        // Calculate steady-state workers using Little's Law (L = λW)
        $targetWorkers = $this->littles->calculate($processingRate, $avgJobTime);

        $this->lastCalculation = [
            'processing_rate' => $processingRate,
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

        return sprintf(
            'Little\'s Law: rate=%.2f/s × time=%.1fs = %.1f workers',
            $calc['processing_rate'],
            $calc['avg_job_time'],
            $calc['target_workers']
        );
    }

    public function getLastPrediction(): ?float
    {
        if (empty($this->lastCalculation)) {
            return null;
        }

        // Simple rate-based strategy doesn't track backlog,
        // so we can't predict pickup time
        return null;
    }

    /**
     * Determine average job time from metrics
     */
    private function determineJobTime(QueueMetricsData $metrics): float
    {
        if ($metrics->avgDuration > 0) {
            if ($metrics->avgDuration >= 0.01) {
                return $metrics->avgDuration;
            }
        }

        return AutoscaleConfiguration::fallbackJobTimeSeconds();
    }
}
