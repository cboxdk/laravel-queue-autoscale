<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

/**
 * Estimates job arrival rate from backlog changes over time
 *
 * Solves the problem where throughput (processing rate) != arrival rate during spikes.
 * Uses a sliding window of snapshots and weighted average for noise reduction.
 *
 * Formula: arrivalRate = processingRate + weightedAverageBacklogGrowthRate
 *
 * This is crucial because Little's Law requires arrival rate, not processing rate.
 * During a spike, processing rate stays constant while arrival rate increases.
 */
final class ArrivalRateEstimator
{
    /**
     * Historical backlog snapshots per queue (sliding window)
     *
     * @var array<string, list<array{backlog: int, timestamp: float}>>
     */
    private array $history = [];

    private ?ForecasterContract $forecaster = null;

    private ?ForecastPolicyContract $policy = null;

    private int $forecastHorizonSeconds = 60;

    /**
     * Maximum number of snapshots to retain per queue
     */
    private const MAX_SNAPSHOTS = 30;

    /**
     * Minimum interval between measurements (seconds) to avoid noise
     */
    private const MIN_INTERVAL = 1.0;

    /**
     * Maximum age of historical data before it's considered stale (seconds)
     */
    private const MAX_HISTORY_AGE = 300.0;

    /**
     * Estimate arrival rate for a queue
     *
     * Uses a sliding window of recent snapshots to calculate a weighted average
     * growth rate, giving more weight to recent observations while smoothing
     * single-point outliers.
     *
     * @param  string  $queueKey  Unique identifier for the queue (connection:queue)
     * @param  int  $currentBacklog  Current number of pending jobs
     * @param  float  $processingRate  Current processing rate (jobs/second)
     * @return array{rate: float, confidence: float, source: string}
     */
    public function estimate(
        string $queueKey,
        int $currentBacklog,
        float $processingRate,
    ): array {
        $now = microtime(true);

        // Get previous snapshots
        $snapshots = $this->history[$queueKey] ?? [];

        // Store current snapshot
        $snapshots[] = [
            'backlog' => $currentBacklog,
            'timestamp' => $now,
        ];

        // Prune stale snapshots (older than MAX_HISTORY_AGE)
        $snapshots = array_values(array_filter(
            $snapshots,
            fn (array $s): bool => ($now - $s['timestamp']) <= self::MAX_HISTORY_AGE,
        ));

        // Keep only the most recent MAX_SNAPSHOTS
        if (count($snapshots) > self::MAX_SNAPSHOTS) {
            $snapshots = array_slice($snapshots, -self::MAX_SNAPSHOTS);
        }

        $this->history[$queueKey] = $snapshots;

        // Need at least 2 snapshots to calculate growth rate
        if (count($snapshots) < 2) {
            return [
                'rate' => $processingRate,
                'confidence' => 0.3,
                'source' => 'no_history',
            ];
        }

        // Check if the oldest usable snapshot is too recent (interval too short)
        $oldest = $snapshots[0];
        $totalInterval = $now - $oldest['timestamp'];

        if ($totalInterval < self::MIN_INTERVAL) {
            return [
                'rate' => $processingRate,
                'confidence' => 0.3,
                'source' => 'interval_too_short',
            ];
        }

        // Calculate weighted average growth rate from consecutive pairs
        // More recent pairs get higher weight
        $weightedGrowthRate = $this->calculateWeightedGrowthRate($snapshots);

        // Arrival rate = processing rate + backlog growth rate
        $arrivalRate = $processingRate + $weightedGrowthRate;

        // Sanity check: arrival rate can't be negative
        $arrivalRate = max($arrivalRate, 0.0);

        // Calculate confidence based on window quality
        $pairCount = count($snapshots) - 1;
        $overallDelta = $currentBacklog - $oldest['backlog'];
        $confidence = $this->calculateConfidence($totalInterval, $overallDelta, $pairCount);

        $observedRate = $arrivalRate;

        if ($this->forecaster !== null && $this->policy !== null) {
            $blended = $this->maybeBlendForecast($snapshots, $processingRate, $observedRate);
            if ($blended !== null) {
                return [
                    'rate' => $blended['rate'],
                    'confidence' => $confidence,
                    'source' => sprintf(
                        'forecast_blended: observed=%.2f/s forecast=%.2f/s R²=%.2f',
                        $observedRate,
                        $blended['forecast'],
                        $blended['r_squared'],
                    ),
                ];
            }
        }

        return [
            'rate' => $arrivalRate,
            'confidence' => $confidence,
            'source' => sprintf(
                'estimated: processing=%.2f/s + growth=%.2f/s (delta=%d over %.1fs, %d samples)',
                $processingRate,
                $weightedGrowthRate,
                $overallDelta,
                $totalInterval,
                $pairCount + 1,
            ),
        ];
    }

    public function setForecaster(
        ForecasterContract $forecaster,
        ForecastPolicyContract $policy,
        int $horizonSeconds,
    ): void {
        $this->forecaster = $forecaster;
        $this->policy = $policy;
        $this->forecastHorizonSeconds = $horizonSeconds;
    }

    public function hasForecaster(): bool
    {
        return $this->forecaster !== null;
    }

    /**
     * @param  list<array{backlog: int, timestamp: float}>  $snapshots
     * @return array{rate: float, forecast: float, r_squared: float}|null
     */
    private function maybeBlendForecast(array $snapshots, float $processingRate, float $observedRate): ?array
    {
        if ($this->forecaster === null || $this->policy === null) {
            return null;
        }

        if (count($snapshots) < 2) {
            return null;
        }

        $history = [];
        for ($i = 1; $i < count($snapshots); $i++) {
            $interval = $snapshots[$i]['timestamp'] - $snapshots[$i - 1]['timestamp'];
            if ($interval < 0.001) {
                continue;
            }
            $growth = ($snapshots[$i]['backlog'] - $snapshots[$i - 1]['backlog']) / $interval;
            $history[] = [
                'timestamp' => $snapshots[$i]['timestamp'],
                'rate' => max(0.0, $processingRate + $growth),
            ];
        }

        $forecast = $this->forecaster->forecast($history, $this->forecastHorizonSeconds);

        if (! $forecast->hasSufficientData || $forecast->rSquared < $this->policy->minRSquared()) {
            return null;
        }

        $weight = $this->policy->forecastWeight();
        $blended = ($weight * $forecast->projectedRate) + ((1 - $weight) * $observedRate);

        return [
            'rate' => max(0.0, $blended),
            'forecast' => $forecast->projectedRate,
            'r_squared' => $forecast->rSquared,
        ];
    }

    /**
     * Calculate weighted average growth rate from snapshot pairs
     *
     * Gives exponentially more weight to recent observations.
     * This smooths noise from single outlier ticks while still
     * reacting quickly to sustained changes.
     *
     * @param  list<array{backlog: int, timestamp: float}>  $snapshots
     */
    private function calculateWeightedGrowthRate(array $snapshots): float
    {
        $pairCount = count($snapshots) - 1;

        if ($pairCount === 0) {
            return 0.0;
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;

        for ($i = 1; $i < count($snapshots); $i++) {
            $interval = $snapshots[$i]['timestamp'] - $snapshots[$i - 1]['timestamp'];

            if ($interval < 0.001) {
                continue;
            }

            $delta = $snapshots[$i]['backlog'] - $snapshots[$i - 1]['backlog'];
            $growthRate = $delta / $interval;

            // Exponential weight: most recent pair gets highest weight
            // weight = 2^(pair_index) so last pair dominates
            $weight = pow(2.0, $i);

            $weightedSum += $growthRate * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight === 0.0) {
            return 0.0;
        }

        return $weightedSum / $totalWeight;
    }

    /**
     * Calculate confidence in the estimate
     *
     * Higher confidence when:
     * - Total interval is in optimal range (5-30 seconds)
     * - Backlog change is significant (not just noise)
     * - Multiple sample pairs contribute to the average
     */
    private function calculateConfidence(float $totalInterval, int $overallDelta, int $pairCount): float
    {
        // Base confidence from interval quality
        $intervalConfidence = match (true) {
            $totalInterval >= 5.0 && $totalInterval <= 30.0 => 0.9,
            $totalInterval >= 2.0 && $totalInterval <= 60.0 => 0.7,
            default => 0.5,
        };

        // Boost confidence for multiple samples (sliding window benefit)
        $sampleBoost = min($pairCount / 3.0, 1.0); // 3+ pairs = full boost
        $intervalConfidence = $intervalConfidence * (0.8 + 0.2 * $sampleBoost);

        // Adjust for significance of change
        if (abs($overallDelta) < 3) {
            return $intervalConfidence * 0.6;
        }

        $changeSignificance = min(abs($overallDelta) / 10.0, 1.0);

        return $intervalConfidence * (0.7 + 0.3 * $changeSignificance);
    }

    /**
     * Clear history for a specific queue
     */
    public function clearHistory(string $queueKey): void
    {
        unset($this->history[$queueKey]);
    }

    /**
     * Clear all history
     */
    public function reset(): void
    {
        $this->history = [];
    }

    /**
     * Get current history state (for testing/debugging)
     *
     * @return array<string, list<array{backlog: int, timestamp: float}>>
     */
    public function getHistory(): array
    {
        return $this->history;
    }
}
