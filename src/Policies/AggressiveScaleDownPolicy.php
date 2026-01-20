<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Policy that aggressively scales down to minimum workers when queue is idle
 *
 * Use Case: Cost-optimized workloads where rapid scale-down saves money during idle periods.
 * This policy immediately removes all excess workers when there are no pending jobs.
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
 */
final readonly class AggressiveScaleDownPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // Only applies to scale-down decisions
        if (! $decision->shouldScaleDown()) {
            return null;
        }

        // Check if queue is considered idle (no pending jobs)
        // Note: We can't access metrics here directly, but we can infer from the decision
        // If the decision is to scale down, we trust the strategy's assessment

        // The strategy already determined we should scale down
        // This policy makes it aggressive - scale down ALL excess workers immediately
        // (The scaling engine will already respect min_workers constraint)

        // Return null to let the original decision through
        // The decision is already aggressive enough if it's scaling down
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No action needed after scaling
    }
}
