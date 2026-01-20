<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

final readonly class TrendPredictor
{
    /**
     * Predict future arrival rate based on current rate and trend direction
     *
     * Uses simple linear extrapolation:
     * - If trend is "up" with forecast, use the forecast value
     * - If trend is "down", reduce by trend factor
     * - If trend is "stable", use current rate
     *
     * @param  float  $currentRate  Current jobs per second
     * @param  object|null  $trend  Trend object from QueueMetricsData (direction, forecast)
     * @param  int  $horizonSeconds  How far ahead to predict (unused in simple model)
     * @return float Predicted arrival rate
     */
    public function predictArrivalRate(
        float $currentRate,
        ?object $trend,
        int $horizonSeconds,
    ): float {
        if ($trend === null || ! isset($trend->direction)) {
            return $currentRate;
        }

        return match ($trend->direction) {
            'up' => isset($trend->forecast) ? max((float) $trend->forecast, $currentRate) : $currentRate * 1.2,
            'down' => $currentRate * 0.8,
            default => $currentRate,
        };
    }
}
