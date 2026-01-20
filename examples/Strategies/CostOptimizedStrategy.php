<?php

declare(strict_types=1);

namespace App\QueueAutoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

/**
 * Example: Cost-optimized scaling strategy
 *
 * Prioritizes cost efficiency by scaling more conservatively.
 * Tolerates slightly longer queue times to minimize worker costs.
 *
 * Usage:
 * In config/queue-autoscale.php:
 * 'strategy' => \App\QueueAutoscale\Strategies\CostOptimizedStrategy::class,
 */
class CostOptimizedStrategy implements ScalingStrategyContract
{
    private string $lastReason = 'No calculation performed yet';

    private ?float $lastPrediction = null;

    public function __construct(
        private float $utilizationTarget = 0.85,  // Target 85% worker utilization
        private float $scaleUpThreshold = 0.90,   // Scale up at 90% utilization
        private float $scaleDownThreshold = 0.60, // Scale down at 60% utilization
    ) {}

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        $processingRate = $metrics->processingRate ?? 0.0;
        $activeWorkers = $metrics->activeWorkerCount ?? 0;
        $backlog = $metrics->depth->pending ?? 0;

        if ($processingRate <= 0) {
            return 0;
        }

        // Calculate current utilization
        $currentUtilization = $activeWorkers > 0
            ? $processingRate / $activeWorkers
            : 0.0;

        // Determine if we need to scale
        if ($currentUtilization >= $this->scaleUpThreshold) {
            // Scale up: approaching capacity
            $targetWorkers = $this->scaleUpCalculation($processingRate, $activeWorkers);
            $this->lastReason = sprintf(
                'Cost-optimized: Scaling UP (utilization: %.0f%% >= %.0f%%)',
                $currentUtilization * 100,
                $this->scaleUpThreshold * 100
            );
        } elseif ($currentUtilization <= $this->scaleDownThreshold) {
            // Scale down: underutilized
            $targetWorkers = $this->scaleDownCalculation($processingRate);
            $this->lastReason = sprintf(
                'Cost-optimized: Scaling DOWN (utilization: %.0f%% <= %.0f%%)',
                $currentUtilization * 100,
                $this->scaleDownThreshold * 100
            );
        } else {
            // Hold steady: within acceptable range
            $targetWorkers = $activeWorkers;
            $this->lastReason = sprintf(
                'Cost-optimized: HOLDING (utilization: %.0f%% in acceptable range)',
                $currentUtilization * 100
            );
        }

        // SLA breach protection: override cost optimization if needed
        if ($backlog > 0) {
            $oldestJobAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
            $slaBreachThreshold = $config->maxPickupTimeSeconds * 0.9;

            if ($oldestJobAge >= $slaBreachThreshold) {
                $emergencyWorkers = $this->calculateEmergencyWorkers($backlog, $config);
                if ($emergencyWorkers > $targetWorkers) {
                    $targetWorkers = $emergencyWorkers;
                    $this->lastReason = 'Cost-optimized: SLA BREACH OVERRIDE - scaling aggressively';
                }
            }
        }

        $this->lastPrediction = $backlog > 0 && $targetWorkers > 0
            ? $backlog / $targetWorkers
            : 0.0;

        return max(0, (int) ceil($targetWorkers));
    }

    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    public function getLastPrediction(): ?float
    {
        return $this->lastPrediction;
    }

    private function scaleUpCalculation(float $rate, int $currentWorkers): int
    {
        // Scale up conservatively: add 20% capacity
        return (int) ceil($currentWorkers * 1.2);
    }

    private function scaleDownCalculation(float $rate): int
    {
        // Scale down to target utilization
        return (int) ceil($rate / $this->utilizationTarget);
    }

    private function calculateEmergencyWorkers(int $backlog, QueueConfiguration $config): int
    {
        // Emergency mode: process backlog within remaining SLA time
        $avgJobTime = 1.0; // Conservative estimate
        $timeRemaining = max($config->maxPickupTimeSeconds * 0.1, 1); // 10% SLA time remaining

        return (int) ceil($backlog / ($timeRemaining / $avgJobTime));
    }
}
