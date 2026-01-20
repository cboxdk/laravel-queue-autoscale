<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

interface ScalingStrategyContract
{
    /**
     * Calculate target number of workers for a queue
     *
     * @param  QueueMetricsData  $metrics  Queue metrics from laravel-queue-metrics
     * @param  QueueConfiguration  $config  Queue SLA configuration
     * @return int Target worker count
     */
    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int;

    /**
     * Get human-readable reason for last scaling decision
     */
    public function getLastReason(): string;

    /**
     * Get predicted pickup time (seconds) from last calculation
     */
    public function getLastPrediction(): ?float;
}
