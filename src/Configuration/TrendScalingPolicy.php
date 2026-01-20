<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

/**
 * Trend-based predictive scaling policy
 *
 * Controls how aggressively the autoscaler responds to predicted load increases.
 *
 * - DISABLED: Ignore trend predictions, scale based on current load only
 * - HINT: Use trends as gentle hints, small weight in decision (conservative)
 * - MODERATE: Balanced approach, trends have equal weight with current metrics (default)
 * - AGGRESSIVE: Proactive scaling, trends dominate decision-making
 */
enum TrendScalingPolicy: string
{
    case DISABLED = 'disabled';
    case HINT = 'hint';
    case MODERATE = 'moderate';
    case AGGRESSIVE = 'aggressive';

    /**
     * Get the trend weight multiplier
     *
     * Controls how much influence trend predictions have on scaling decisions.
     * Higher values make the autoscaler more proactive.
     */
    public function trendWeight(): float
    {
        return match ($this) {
            self::DISABLED => 0.0,      // No trend influence
            self::HINT => 0.3,          // 30% trend, 70% current
            self::MODERATE => 0.5,      // 50% trend, 50% current
            self::AGGRESSIVE => 0.8,    // 80% trend, 20% current
        };
    }

    /**
     * Minimum confidence required to use trend predictions
     *
     * Lower confidence threshold = more willing to use uncertain predictions
     */
    public function minConfidence(): float
    {
        return match ($this) {
            self::DISABLED => 1.0,      // Impossible threshold (disabled)
            self::HINT => 0.8,          // High confidence required
            self::MODERATE => 0.7,      // Moderate confidence required
            self::AGGRESSIVE => 0.5,    // Low confidence acceptable
        };
    }

    /**
     * Check if trend scaling is enabled
     */
    public function isEnabled(): bool
    {
        return $this !== self::DISABLED;
    }

    /**
     * Get human-readable description
     */
    public function description(): string
    {
        return match ($this) {
            self::DISABLED => 'Trend predictions disabled - reactive scaling only',
            self::HINT => 'Conservative - trends provide gentle hints',
            self::MODERATE => 'Balanced - equal weight to trends and current metrics',
            self::AGGRESSIVE => 'Proactive - aggressive scaling based on predictions',
        };
    }
}
