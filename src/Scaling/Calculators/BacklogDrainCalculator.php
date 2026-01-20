<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

final readonly class BacklogDrainCalculator
{
    /**
     * Calculate workers needed to drain backlog before SLA breach
     *
     * Progressive aggressiveness approach:
     * - 50%-80% of SLA: Start preparing (1.2x multiplier)
     * - 80%-90% of SLA: Scale aggressively (1.5x multiplier)
     * - 90%-100% of SLA: Emergency scaling (2.0x multiplier)
     * - 100%+ of SLA: Maximum aggression (3.0x multiplier)
     *
     * @param  int  $backlog  Number of pending jobs
     * @param  int  $oldestJobAge  Age of oldest job in seconds
     * @param  int  $slaTarget  Max pickup time SLA in seconds
     * @param  float  $avgJobTime  Average processing time per job in seconds
     * @param  float  $breachThreshold  Threshold (0-1) to trigger action (typically 0.5 = 50%)
     * @return float Required workers (fractional, caller should ceil())
     */
    public function calculateRequiredWorkers(
        int $backlog,
        int $oldestJobAge,
        int $slaTarget,
        float $avgJobTime,
        float $breachThreshold,
    ): float {
        if ($backlog === 0 || $avgJobTime <= 0) {
            return 0.0;
        }

        // Fallback: If oldest job age is unavailable (0) but we have backlog,
        // assume we should start processing. Not all queue drivers can provide age data.
        if ($oldestJobAge === 0 && $backlog > 0) {
            // Use conservative estimate: process backlog within full SLA window
            $jobsPerWorker = max($slaTarget / $avgJobTime, 1.0);

            return $backlog / $jobsPerWorker;
        }

        // Calculate how far through SLA we are (as percentage)
        $slaProgress = min($oldestJobAge / $slaTarget, 1.5); // Cap at 150% for extreme cases

        // Act proactively at threshold (e.g., 50% of SLA)
        if ($slaProgress < $breachThreshold) {
            return 0.0; // No urgent action needed yet
        }

        // Time until SLA breach
        $timeUntilBreach = $slaTarget - $oldestJobAge;

        // Calculate base workers needed
        $baseWorkers = $timeUntilBreach > 0
            ? $backlog / max($timeUntilBreach / $avgJobTime, 1.0)
            : $backlog / max($avgJobTime, 0.1);

        // Apply progressive aggressiveness multiplier based on SLA progress
        $multiplier = $this->getAggressivenessMultiplier($slaProgress);

        return $baseWorkers * $multiplier;
    }

    /**
     * Get aggressiveness multiplier based on how close we are to SLA breach
     *
     * Uses a continuous exponential function to avoid discrete jumps that
     * could cause scaling instability.
     *
     * Formula: multiplier = 1.0 + k * (slaProgress - threshold)^2
     * where k is calibrated so that:
     * - At 50% (threshold): 1.0x (start responding)
     * - At 80%: ~1.5x
     * - At 100%: ~2.5x
     * - At 150% (cap): ~5.0x
     *
     * The quadratic curve provides smooth acceleration as urgency increases.
     */
    private function getAggressivenessMultiplier(float $slaProgress): float
    {
        // Below threshold - no action
        if ($slaProgress < 0.5) {
            return 0.0;
        }

        // Continuous quadratic function starting at threshold
        // f(x) = 1 + k(x - 0.5)² where x is slaProgress
        // Calibrated: k = 8.0 gives us ~2.5x at 100% and ~5.0x at 150%
        $k = 8.0;
        $progressAboveThreshold = $slaProgress - 0.5;
        $multiplier = 1.0 + $k * ($progressAboveThreshold * $progressAboveThreshold);

        // Cap at 5.0 to prevent extreme over-provisioning
        return min($multiplier, 5.0);
    }
}
