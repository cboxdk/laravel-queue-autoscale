<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Simulation;

/**
 * Predefined workload scenarios for simulation testing
 *
 * Each scenario returns an array of tick => arrival_multiplier
 * that models realistic traffic patterns.
 */
final class WorkloadScenarios
{
    /**
     * Steady state - constant load
     *
     * Tests: Basic steady-state handling, worker count stability
     * Expected: Workers should stabilize, no thrashing
     *
     * @return array<int, float>
     */
    public static function steadyState(int $duration = 300): array
    {
        return array_fill(1, $duration, 1.0);
    }

    /**
     * Sudden traffic spike - 5x load increase
     *
     * Tests: Rapid scale-up response, SLA protection during spike
     * Expected: Quick scale-up, minimal SLA breach
     *
     * Pattern: 60s normal → 60s spike (5x) → 180s normal
     *
     * @return array<int, float>
     */
    public static function suddenSpike(int $duration = 300): array
    {
        $pattern = [];

        // Normal load (first 60 seconds)
        for ($t = 1; $t <= 60; $t++) {
            $pattern[$t] = 1.0;
        }

        // Spike (5x load for 60 seconds)
        for ($t = 61; $t <= 120; $t++) {
            $pattern[$t] = 5.0;
        }

        // Return to normal
        for ($t = 121; $t <= $duration; $t++) {
            $pattern[$t] = 1.0;
        }

        return $pattern;
    }

    /**
     * Gradual growth - 10% increase every 30 seconds
     *
     * Tests: Trend detection, proactive scaling
     * Expected: Gradual scale-up following the trend
     *
     * @return array<int, float>
     */
    public static function gradualGrowth(int $duration = 300): array
    {
        $pattern = [];
        $multiplier = 1.0;
        $lastIncrease = 0;

        for ($t = 1; $t <= $duration; $t++) {
            // Increase by 10% every 30 seconds
            if ($t - $lastIncrease >= 30) {
                $multiplier *= 1.1;
                $lastIncrease = $t;
            }
            $pattern[$t] = $multiplier;
        }

        return $pattern;
    }

    /**
     * Traffic decline - load decreases over time
     *
     * Tests: Scale-down behavior, avoiding over-provisioning
     * Expected: Gradual scale-down without premature reduction
     *
     * Pattern: Start at 3x, decrease to 0.5x over time
     *
     * @return array<int, float>
     */
    public static function trafficDecline(int $duration = 300): array
    {
        $pattern = [];
        $startMultiplier = 3.0;
        $endMultiplier = 0.5;

        for ($t = 1; $t <= $duration; $t++) {
            $progress = $t / $duration;
            $pattern[$t] = $startMultiplier - (($startMultiplier - $endMultiplier) * $progress);
        }

        return $pattern;
    }

    /**
     * Bursty traffic - on/off pattern
     *
     * Tests: Avoiding thrashing, handling intermittent load
     * Expected: Stable response without constant scaling up/down
     *
     * Pattern: 30s high (3x) → 30s low (0.2x), repeated
     *
     * @return array<int, float>
     */
    public static function burstyTraffic(int $duration = 300): array
    {
        $pattern = [];
        $cycleLength = 60; // 30s on, 30s off

        for ($t = 1; $t <= $duration; $t++) {
            $positionInCycle = ($t - 1) % $cycleLength;
            // First half: high load, second half: low load
            $pattern[$t] = $positionInCycle < 30 ? 3.0 : 0.2;
        }

        return $pattern;
    }

    /**
     * Cold start - from zero to full load
     *
     * Tests: Bootstrap behavior, handling initial surge
     * Expected: Quick ramp-up from minimum workers
     *
     * Pattern: 0 load for 30s → sudden jump to 3x
     *
     * @return array<int, float>
     */
    public static function coldStart(int $duration = 300): array
    {
        $pattern = [];

        // No load initially
        for ($t = 1; $t <= 30; $t++) {
            $pattern[$t] = 0.0;
        }

        // Sudden load
        for ($t = 31; $t <= $duration; $t++) {
            $pattern[$t] = 3.0;
        }

        return $pattern;
    }

    /**
     * SLA pressure - backlog builds up slowly
     *
     * Tests: SLA breach prevention, proactive intervention
     * Expected: Scale-up before SLA breach
     *
     * Pattern: Slightly more arrivals than processing capacity (1.3x)
     *
     * @return array<int, float>
     */
    public static function slaPressure(int $duration = 300): array
    {
        return array_fill(1, $duration, 1.3);
    }

    /**
     * Wave pattern - sinusoidal load variation
     *
     * Tests: Smooth response to varying load
     * Expected: Workers follow the wave pattern
     *
     * Pattern: Sine wave between 0.5x and 2.5x with 60s period
     *
     * @return array<int, float>
     */
    public static function wavePattern(int $duration = 300): array
    {
        $pattern = [];
        $period = 60; // seconds per cycle

        for ($t = 1; $t <= $duration; $t++) {
            // Sine wave: amplitude 1.0, offset 1.5 → range [0.5, 2.5]
            $pattern[$t] = 1.5 + sin(2 * M_PI * $t / $period);
        }

        return $pattern;
    }

    /**
     * Morning rush - exponential ramp-up then plateau
     *
     * Tests: Handling rapid growth then stabilization
     * Expected: Scale up during ramp, stabilize at plateau
     *
     * Pattern: Exponential growth for 60s, then stable at 4x
     *
     * @return array<int, float>
     */
    public static function morningRush(int $duration = 300): array
    {
        $pattern = [];
        $rampDuration = 60;
        $plateauMultiplier = 4.0;

        for ($t = 1; $t <= $duration; $t++) {
            if ($t <= $rampDuration) {
                // Exponential ramp-up
                $progress = $t / $rampDuration;
                $pattern[$t] = 0.5 + ($plateauMultiplier - 0.5) * ($progress ** 2);
            } else {
                // Plateau
                $pattern[$t] = $plateauMultiplier;
            }
        }

        return $pattern;
    }

    /**
     * End of day - gradual wind-down
     *
     * Tests: Graceful scale-down, draining backlog
     * Expected: Smooth reduction, backlog cleared
     *
     * Pattern: Start at 2x, exponential decay to 0.1x
     *
     * @return array<int, float>
     */
    public static function endOfDay(int $duration = 300): array
    {
        $pattern = [];
        $startMultiplier = 2.0;
        $endMultiplier = 0.1;

        for ($t = 1; $t <= $duration; $t++) {
            $progress = $t / $duration;
            // Exponential decay
            $pattern[$t] = $endMultiplier + ($startMultiplier - $endMultiplier) * exp(-3 * $progress);
        }

        return $pattern;
    }

    /**
     * Extreme spike - 10x load for short period
     *
     * Tests: Handling extreme load, max worker utilization
     * Expected: Hit max workers, recover after spike
     *
     * Pattern: 30s normal → 30s extreme (10x) → 240s recovery
     *
     * @return array<int, float>
     */
    public static function extremeSpike(int $duration = 300): array
    {
        $pattern = [];

        // Normal load
        for ($t = 1; $t <= 30; $t++) {
            $pattern[$t] = 1.0;
        }

        // Extreme spike
        for ($t = 31; $t <= 60; $t++) {
            $pattern[$t] = 10.0;
        }

        // Recovery
        for ($t = 61; $t <= $duration; $t++) {
            $pattern[$t] = 1.0;
        }

        return $pattern;
    }

    /**
     * Flapping load - rapid oscillation
     *
     * Tests: Anti-thrashing behavior
     * Expected: Stable worker count despite oscillation
     *
     * Pattern: Alternates between 0.5x and 2x every 5 seconds
     *
     * @return array<int, float>
     */
    public static function flappingLoad(int $duration = 300): array
    {
        $pattern = [];
        $cycleLength = 10; // 5s high, 5s low

        for ($t = 1; $t <= $duration; $t++) {
            $positionInCycle = ($t - 1) % $cycleLength;
            $pattern[$t] = $positionInCycle < 5 ? 2.0 : 0.5;
        }

        return $pattern;
    }

    /**
     * Get all scenarios for comprehensive testing
     *
     * @return array<string, array<int, float>>
     */
    public static function all(int $duration = 300): array
    {
        return [
            'steady_state' => self::steadyState($duration),
            'sudden_spike' => self::suddenSpike($duration),
            'gradual_growth' => self::gradualGrowth($duration),
            'traffic_decline' => self::trafficDecline($duration),
            'bursty_traffic' => self::burstyTraffic($duration),
            'cold_start' => self::coldStart($duration),
            'sla_pressure' => self::slaPressure($duration),
            'wave_pattern' => self::wavePattern($duration),
            'morning_rush' => self::morningRush($duration),
            'end_of_day' => self::endOfDay($duration),
            'extreme_spike' => self::extremeSpike($duration),
            'flapping_load' => self::flappingLoad($duration),
        ];
    }
}
