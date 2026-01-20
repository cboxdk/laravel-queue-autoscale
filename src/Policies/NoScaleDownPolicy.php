<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Policy that prevents scaling down workers
 *
 * Use Case: Critical/mission-critical workloads where maintaining capacity is more important than cost.
 * This policy ensures workers are never removed, keeping the queue always ready to process jobs.
 *
 * Exception: Allows scale-down when system resources (CPU/memory) are constrained to prevent
 * system instability. Resource constraints are detected by checking if the current worker count
 * exceeds the maximum capacity allowed by available system resources.
 *
 * Example:
 * - Payment processing systems
 * - Order fulfillment queues
 * - Real-time notification systems
 *
 * Trade-offs:
 * - Higher cost (workers stay running even when idle)
 * - Excellent reliability (zero cold start delays)
 * - Predictable performance
 */
final readonly class NoScaleDownPolicy implements ScalingPolicy
{
    public function __construct(
        private CapacityCalculator $capacity,
    ) {}

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // If not scaling down, allow the decision to pass through
        if (! $decision->shouldScaleDown()) {
            return null;
        }

        // Check if scale-down is forced by resource constraints
        // If current workers exceed system capacity, we must scale down for stability
        $capacityResult = $this->capacity->calculateMaxWorkers();
        if ($decision->currentWorkers > $capacityResult->finalMaxWorkers) {
            // Let resource-constrained scale-down proceed to maintain system stability
            return null;
        }

        // Prevent normal scale-down (maintain capacity for critical workloads)
        return new ScalingDecision(
            connection: $decision->connection,
            queue: $decision->queue,
            currentWorkers: $decision->currentWorkers,
            targetWorkers: $decision->currentWorkers, // Prevent scale-down
            reason: 'NoScaleDownPolicy prevented scale-down - maintaining capacity',
            predictedPickupTime: $decision->predictedPickupTime,
            slaTarget: $decision->slaTarget,
            capacity: $decision->capacity,
        );
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No action needed after scaling
    }
}
