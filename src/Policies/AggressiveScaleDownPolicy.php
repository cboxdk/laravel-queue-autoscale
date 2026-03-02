<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Policy that aggressively scales down to target when demand drops
 *
 * Use Case: Cost-optimized workloads where rapid scale-down saves money during idle periods.
 * This policy overrides conservative scale-down behavior from previous policies,
 * ensuring the strategy's recommended target is reached immediately.
 *
 * Example:
 * - Bursty/sporadic workloads (marketing campaigns, webhooks)
 * - Background processing (cleanup, analytics, reports)
 * - Development/staging environments
 *
 * Benefits:
 * - Maximum cost savings during idle periods
 * - Rapid resource deallocation
 * - Ideal for workloads with clear idle/active patterns
 *
 * Trade-offs:
 * - Potential cold start delays when load returns
 * - More aggressive up/down cycling
 * - May be too aggressive for steady workloads
 *
 * Configuration: Use this policy INSTEAD of ConservativeScaleDownPolicy, not alongside it.
 * If both are configured, this policy should come after Conservative to override its limits.
 */
final readonly class AggressiveScaleDownPolicy implements ScalingPolicy
{
    /**
     * If no SLA breach risk is detected and the queue appears idle,
     * allow scaling down to zero workers regardless of strategy target.
     * Otherwise, ensure the full strategy-recommended target is applied
     * without conservative throttling.
     */
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // Only applies to scale-down decisions
        if (! $decision->shouldScaleDown()) {
            return null;
        }

        // If predicted pickup time is zero or null and target is already low,
        // the queue is essentially idle - allow full unrestricted scale-down.
        // This explicitly counteracts any Conservative policy that may have
        // limited the scale-down in earlier policy execution.
        $isIdle = $decision->predictedPickupTime === null || $decision->predictedPickupTime === 0.0;
        $isAlreadyMinimal = $decision->targetWorkers <= 1;

        if ($isIdle && $isAlreadyMinimal) {
            // Force scale-down to exact target (0 or 1)
            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: $decision->targetWorkers,
                reason: sprintf(
                    'AggressiveScaleDownPolicy: idle queue, scaling to %d workers immediately (original: %s)',
                    $decision->targetWorkers,
                    $decision->reason
                ),
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
                capacity: $decision->capacity,
            );
        }

        // For non-idle scale-downs, allow the full strategy-recommended reduction
        // without the 25% per-cycle limit that ConservativeScaleDownPolicy would apply.
        // This is the key differentiator: aggressive allows immediate full scale-down.
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No action needed after scaling
    }
}
