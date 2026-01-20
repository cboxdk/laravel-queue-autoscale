<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Policy that limits scale-down to a maximum of 1 worker per evaluation cycle
 *
 * Use Case: Workloads that benefit from gradual scaling to avoid oscillation and thrashing.
 * This policy prevents aggressive scale-down that could cause rapid up/down cycles.
 *
 * Example:
 * - High-volume email sending
 * - Batch processing with variable load
 * - General web application queues
 *
 * Benefits:
 * - Prevents scaling thrashing (rapid up/down cycles)
 * - Smoother resource utilization
 * - More predictable behavior
 * - Better for workloads with variable but persistent demand
 *
 * Trade-offs:
 * - Slower cost reduction when load drops
 * - May maintain excess workers longer than needed
 */
final readonly class ConservativeScaleDownPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // Only modify scale-down decisions
        if (! $decision->shouldScaleDown()) {
            return null;
        }

        $workersToRemove = $decision->workersToRemove();

        // Calculate max removable workers (25% of current, minimum 1)
        $maxRemovable = max(1, (int) ceil($decision->currentWorkers * 0.25));

        // If removing more than the allowed limit, clamp it
        if ($workersToRemove > $maxRemovable) {
            $conservativeTarget = $decision->currentWorkers - $maxRemovable;

            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: $conservativeTarget,
                reason: sprintf(
                    'ConservativeScaleDownPolicy limited scale-down from %d to %d worker(s) (25%% limit, original: %s)',
                    $workersToRemove,
                    $maxRemovable,
                    $decision->reason
                ),
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
            );
        }

        // Within limits, allow it
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No action needed after scaling
    }
}
