<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

/**
 * Estimates job arrival rate from backlog changes over time
 *
 * Solves the problem where throughput (processing rate) != arrival rate during spikes.
 * Uses the formula: arrivalRate = processingRate + backlogGrowthRate
 *
 * This is crucial because Little's Law requires arrival rate, not processing rate.
 * During a spike, processing rate stays constant while arrival rate increases.
 */
final class ArrivalRateEstimator
{
    /**
     * Historical backlog snapshots per queue
     *
     * @var array<string, array{backlog: int, timestamp: float}>
     */
    private array $history = [];

    /**
     * Minimum interval between measurements (seconds) to avoid noise
     */
    private const MIN_INTERVAL = 1.0;

    /**
     * Maximum age of historical data before it's considered stale (seconds)
     */
    private const MAX_HISTORY_AGE = 60.0;

    /**
     * Estimate arrival rate for a queue
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

        // Get previous snapshot
        $previous = $this->history[$queueKey] ?? null;

        // Store current snapshot for next calculation
        $this->history[$queueKey] = [
            'backlog' => $currentBacklog,
            'timestamp' => $now,
        ];

        // First measurement - no history available
        if ($previous === null) {
            return [
                'rate' => $processingRate,
                'confidence' => 0.3,
                'source' => 'no_history',
            ];
        }

        $interval = $now - $previous['timestamp'];

        // Interval too short - use processing rate to avoid noise
        if ($interval < self::MIN_INTERVAL) {
            return [
                'rate' => $processingRate,
                'confidence' => 0.3,
                'source' => 'interval_too_short',
            ];
        }

        // History too old - data is stale
        if ($interval > self::MAX_HISTORY_AGE) {
            return [
                'rate' => $processingRate,
                'confidence' => 0.4,
                'source' => 'history_stale',
            ];
        }

        // Calculate backlog change rate
        $backlogDelta = $currentBacklog - $previous['backlog'];
        $backlogGrowthRate = $backlogDelta / $interval;

        // Arrival rate = processing rate + backlog growth rate
        // If backlog is growing, arrival > processing
        // If backlog is shrinking, arrival < processing
        $arrivalRate = $processingRate + $backlogGrowthRate;

        // Sanity check: arrival rate can't be negative
        $arrivalRate = max($arrivalRate, 0.0);

        // Calculate confidence based on interval quality
        // Optimal interval is 5-30 seconds
        $confidence = $this->calculateConfidence($interval, $backlogDelta);

        return [
            'rate' => $arrivalRate,
            'confidence' => $confidence,
            'source' => sprintf(
                'estimated: processing=%.2f/s + growth=%.2f/s (delta=%d over %.1fs)',
                $processingRate,
                $backlogGrowthRate,
                $backlogDelta,
                $interval
            ),
        ];
    }

    /**
     * Calculate confidence in the estimate
     *
     * Higher confidence when:
     * - Interval is in optimal range (5-30 seconds)
     * - Backlog change is significant (not just noise)
     */
    private function calculateConfidence(float $interval, int $backlogDelta): float
    {
        // Base confidence from interval quality
        $intervalConfidence = match (true) {
            $interval >= 5.0 && $interval <= 30.0 => 0.9,
            $interval >= 2.0 && $interval <= 60.0 => 0.7,
            default => 0.5,
        };

        // Adjust for significance of change
        $changeSignificance = min(abs($backlogDelta) / 10.0, 1.0);

        // Blend: more weight on interval if change is small (might be noise)
        if (abs($backlogDelta) < 3) {
            return $intervalConfidence * 0.6;
        }

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
     * @return array<string, array{backlog: int, timestamp: float}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }
}
