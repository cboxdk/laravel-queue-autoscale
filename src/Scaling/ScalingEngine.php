<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

final readonly class ScalingEngine
{
    public function __construct(
        private ScalingStrategyContract $strategy,
        private CapacityCalculator $capacity,
    ) {}

    /**
     * Evaluate scaling decision for a queue
     *
     * @param  QueueMetricsData  $metrics  Queue metrics from laravel-queue-metrics
     * @param  QueueConfiguration  $config  Queue SLA configuration
     * @param  int  $currentWorkers  Current worker count for this queue
     * @param  int  $totalPoolWorkers  Total workers across all queues (for accurate capacity sharing)
     * @return ScalingDecision Scaling decision with target workers
     */
    public function evaluate(
        QueueMetricsData $metrics,
        QueueConfiguration $config,
        int $currentWorkers,
        int $totalPoolWorkers = 0,
    ): ScalingDecision {
        // 1. Calculate target workers based on strategy
        $strategyRecommendation = $this->strategy->calculateTargetWorkers($metrics, $config);
        $targetWorkers = $strategyRecommendation;

        // 2. Get system capacity using total pool workers for accurate measurement.
        // This ensures capacity is calculated against ALL workers, not just this queue's,
        // preventing each queue from assuming it has all remaining system capacity.
        $effectiveTotalWorkers = max($totalPoolWorkers, $currentWorkers);
        $capacityResult = $this->capacity->calculateMaxWorkers($effectiveTotalWorkers);

        // 3. Apply resource constraints: this queue's share of system capacity
        // System can support capacityResult->finalMaxWorkers total. Other queues
        // already consume (totalPoolWorkers - currentWorkers), so this queue's
        // ceiling is systemMax minus what other queues are using.
        $otherQueuesWorkers = $effectiveTotalWorkers - $currentWorkers;
        $availableForThisQueue = max($capacityResult->finalMaxWorkers - $otherQueuesWorkers, 0);
        $targetWorkers = min($targetWorkers, $availableForThisQueue);

        // 4. Apply config bounds (min/max workers)
        $beforeConfigBounds = $targetWorkers;
        $targetWorkers = max($targetWorkers, $config->workers->min);
        $targetWorkers = min($targetWorkers, $config->workers->max);

        // 5. Determine final limiting factor after all constraints
        $finalLimitingFactor = $this->determineFinalLimitingFactor(
            $capacityResult,
            $strategyRecommendation,
            $beforeConfigBounds,
            $targetWorkers,
            $config->workers->min,
            $config->workers->max
        );

        // 6. Create final capacity result with config constraint applied
        $finalCapacityResult = new CapacityCalculationResult(
            maxWorkersByCpu: $capacityResult->maxWorkersByCpu,
            maxWorkersByMemory: $capacityResult->maxWorkersByMemory,
            maxWorkersByConfig: $config->workers->max,
            finalMaxWorkers: $targetWorkers,
            limitingFactor: $finalLimitingFactor,
            details: $capacityResult->details
        );

        return new ScalingDecision(
            connection: $config->connection,
            queue: $config->queue,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: $this->strategy->getLastReason(),
            predictedPickupTime: $this->strategy->getLastPrediction(),
            slaTarget: $config->sla->targetSeconds,
            capacity: $finalCapacityResult,
        );
    }

    /**
     * Determine which constraint is the final limiting factor
     */
    private function determineFinalLimitingFactor(
        CapacityCalculationResult $capacityResult,
        int $strategyRecommendation,
        int $afterSystemCapacity,
        int $finalTarget,
        int $configMinWorkers,
        int $configMaxWorkers,
    ): string {
        // If config max_workers capped the target
        if ($finalTarget < $afterSystemCapacity && $finalTarget === $configMaxWorkers) {
            return 'config';
        }

        // If config min_workers raised the target above what strategy/capacity allowed
        // This means we have low/no demand, not a capacity constraint
        if ($finalTarget > $afterSystemCapacity && $finalTarget === $configMinWorkers) {
            return 'strategy';
        }

        // If system capacity reduced the strategy recommendation
        if ($afterSystemCapacity < $strategyRecommendation) {
            return $capacityResult->limitingFactor; // Actually limited by CPU or memory
        }

        // Strategy recommendation was within capacity and config bounds
        return 'strategy';
    }
}
