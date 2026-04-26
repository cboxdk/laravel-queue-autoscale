<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

final readonly class BacklogDrainCalculator
{
    /**
     * Calculate workers needed to drain backlog before SLA breach
     *
     * Progressive aggressiveness via continuous quadratic curve:
     * - At 50% of SLA (threshold): 1.0x (start responding)
     * - At 80% of SLA: ~1.7x
     * - At 100% of SLA: 3.0x
     * - At 150%+ of SLA: capped at 5.0x
     *
     * @param  int  $backlog  Number of pending jobs
     * @param  int  $oldestJobAge  Age of oldest job in seconds
     * @param  int  $slaTarget  Max pickup time SLA in seconds
     * @param  float  $avgJobTime  Average processing time per job in seconds
     * @param  float  $breachThreshold  Threshold (0-1) to trigger action (typically 0.5 = 50%)
     * @param  float|null  $effectiveSlaSeconds  Effective SLA after spawn compensation (optional, defaults to slaTarget)
     * @return float Required workers (fractional, caller should ceil())
     */
    public function calculateRequiredWorkers(
        int $backlog,
        int $oldestJobAge,
        int $slaTarget,
        float $avgJobTime,
        float $breachThreshold,
        ?float $effectiveSlaSeconds = null,
    ): float {
        if ($backlog === 0 || $avgJobTime <= 0) {
            return 0.0;
        }

        // Use effective SLA if provided, otherwise default to slaTarget
        $effectiveSla = $effectiveSlaSeconds ?? (float) $slaTarget;

        // Fallback: If oldest job age is unavailable (0) but we have backlog,
        // assume we should start processing. Not all queue drivers can provide age data.
        if ($oldestJobAge === 0 && $backlog > 0) {
            // Use conservative estimate: process backlog within full SLA window
            $jobsPerWorker = max($effectiveSla / $avgJobTime, 1.0);

            return $backlog / $jobsPerWorker;
        }

        // Calculate how far through SLA we are (as percentage)
        $slaProgress = min($oldestJobAge / $effectiveSla, 1.5); // Cap at 150% for extreme cases

        // Act proactively at threshold (e.g., 50% of SLA)
        if ($slaProgress < $breachThreshold) {
            return 0.0; // No urgent action needed yet
        }

        // Time until SLA breach (using effective SLA)
        $timeUntilBreach = $effectiveSla - $oldestJobAge;

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
     * Formula: multiplier = 1.0 + k * (slaProgress - 0.5)^2
     * where k = 8.0, producing:
     * - At 50% (threshold): 1.0x
     * - At 80%: ~1.72x
     * - At 100%: 3.0x
     * - Beyond 100%: continues rising, capped at 5.0x
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
        // k = 8.0 gives 3.0x at 100%, capped at 5.0x
        $k = 8.0;
        $progressAboveThreshold = $slaProgress - 0.5;
        $multiplier = 1.0 + $k * ($progressAboveThreshold * $progressAboveThreshold);

        // Cap at 5.0 to prevent extreme over-provisioning
        return min($multiplier, 5.0);
    }
}
