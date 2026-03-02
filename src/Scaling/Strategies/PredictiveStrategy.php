<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\TrendScalingPolicy;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Predictive scaling strategy using multiple algorithms
 *
 * Combines three approaches to determine optimal worker count:
 * 1. Rate-Based (Little's Law): Workers needed for steady-state throughput
 * 2. Arrival-Based: Estimates true arrival rate from backlog changes (handles spikes)
 * 3. Backlog-Based: Workers needed to drain queue before SLA breach
 *
 * Takes the maximum of all three to ensure SLA compliance.
 */
final class PredictiveStrategy implements ScalingStrategyContract
{
    /**
     * @var array<string, float|int|string>
     */
    private array $lastCalculation = [];

    private bool $usedFallback = false;

    private string $arrivalRateSource = '';

    public function __construct(
        private readonly LittlesLawCalculator $littles,
        private readonly BacklogDrainCalculator $backlog,
        private readonly ArrivalRateEstimator $arrivalEstimator,
    ) {
    }

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        // Convert throughput_per_minute to jobs/second (processing rate)
        $processingRate = $metrics->throughputPerMinute / 60.0;

        $backlogSize = $metrics->pending;
        $oldestJobAge = $metrics->oldestJobAge;
        $activeWorkers = $metrics->activeWorkers;

        // Reset flags
        $this->usedFallback = false;
        $this->arrivalRateSource = '';

        // Determine average job time
        [$avgJobTime, $jobTimeSource] = $this->determineJobTime($metrics, $processingRate, $activeWorkers);

        // Estimate arrival rate from backlog changes (more accurate during spikes)
        $queueKey = "{$config->connection}:{$config->queue}";
        $arrivalEstimate = $this->arrivalEstimator->estimate($queueKey, $backlogSize, $processingRate);

        // Use estimated arrival rate if confidence is high enough
        $minConfidence = AutoscaleConfiguration::minArrivalRateConfidence();
        if ($arrivalEstimate['confidence'] >= $minConfidence) {
            $arrivalRate = $arrivalEstimate['rate'];
            $this->arrivalRateSource = $arrivalEstimate['source'];
        } else {
            // Fall back to processing rate (only accurate in steady state)
            $arrivalRate = $processingRate;
            $this->arrivalRateSource = sprintf(
                'processing_rate (arrival estimate confidence %.1f%% < %.1f%% threshold)',
                $arrivalEstimate['confidence'] * 100,
                $minConfidence * 100
            );
        }

        // FALLBACK: If arrival rate is 0, estimate from available indicators
        if ($arrivalRate === 0.0) {
            $arrivalRate = $this->estimateFallbackArrivalRate(
                $backlogSize,
                $activeWorkers,
                $oldestJobAge,
                $avgJobTime,
                $config->maxPickupTimeSeconds
            );

            if ($arrivalRate > 0.0) {
                $this->usedFallback = true;
                $this->arrivalRateSource = 'fallback_estimate';
            }
        }

        // Adjust arrival rate for retry noise
        // High failure rates inflate arrival rate because retries look like new jobs.
        // We subtract the estimated retry volume to get the "true" arrival rate of new work.
        //
        // Important: failureRate from metrics is LIFETIME, not recent. To avoid permanently
        // underestimating arrival rate from old failures, we:
        // 1. Only apply correction when failure rate is significant (>5%)
        // 2. Cap the correction at 30% of arrival rate to prevent over-correction
        // 3. Use a dampened rate: sqrt(rate/100) makes low rates nearly invisible
        //    while high rates (active incidents) still have strong correction
        $failureRate = $metrics->failureRate ?? 0.0;
        $rawArrivalRate = $arrivalRate;
        $retryNoise = 0.0;

        if ($failureRate > 5.0 && $processingRate > 0) {
            // Dampened correction: sqrt makes low lifetime rates negligible
            // 5% → 0.22 factor, 25% → 0.50 factor, 100% → 1.0 factor
            $dampenedFactor = sqrt($failureRate / 100.0);
            $retryNoise = $processingRate * $dampenedFactor;

            // Cap at 30% of arrival rate to prevent over-correction from stale data
            $maxCorrection = $arrivalRate * 0.3;
            $retryNoise = min($retryNoise, $maxCorrection);

            $arrivalRate = max(0.0, $arrivalRate - $retryNoise);
        }

        // 1. RATE-BASED: Little's Law (L = λW)
        // Workers needed to handle current (adjusted) arrival rate
        $steadyStateWorkers = $this->littles->calculate($arrivalRate, $avgJobTime);

        // 2. TREND-BASED: Predict future arrival rate using policy
        $trendPolicy = AutoscaleConfiguration::trendScalingPolicy();
        $predictedRate = $this->applyTrendPolicy($arrivalRate, $trendPolicy);
        $predictiveWorkers = $this->littles->calculate($predictedRate, $avgJobTime);

        // 3. BACKLOG-BASED: Prevent SLA breach
        $backlogDrainWorkers = $this->backlog->calculateRequiredWorkers(
            backlog: $backlogSize,
            oldestJobAge: $oldestJobAge,
            slaTarget: $config->maxPickupTimeSeconds,
            avgJobTime: $avgJobTime,
            breachThreshold: AutoscaleConfiguration::breachThreshold(),
        );

        // 4. COMBINE: Take maximum (most conservative)
        $targetWorkers = max(
            $steadyStateWorkers,
            $predictiveWorkers,
            $backlogDrainWorkers,
        );

        // 5. UTILIZATION ADJUSTMENT: Use worker utilization as a real-time signal
        // Utilization rate from metrics tells us how busy current workers actually are,
        // providing ground truth that complements the algorithmic calculations.
        $utilizationRate = $metrics->utilizationRate ?? 0.0;
        $utilizationAdjustment = '';

        if ($activeWorkers > 0 && $utilizationRate > 0) {
            if ($utilizationRate >= 90.0 && $targetWorkers <= $activeWorkers) {
                // Workers are saturated but algorithms didn't recommend scaling up.
                // This can happen when throughput data lags behind reality.
                // Add a small boost to prevent being stuck at saturation.
                $targetWorkers = $activeWorkers + 1;
                $utilizationAdjustment = sprintf('saturation boost (%.0f%% utilized)', $utilizationRate);
            }
        }

        // Store for reason building
        $this->lastCalculation = [
            'steady_state' => $steadyStateWorkers,
            'predictive' => $predictiveWorkers,
            'backlog_drain' => $backlogDrainWorkers,
            'arrival_rate' => $arrivalRate,
            'raw_arrival_rate' => $rawArrivalRate,
            'retry_noise' => $retryNoise,
            'processing_rate' => $processingRate,
            'predicted_rate' => $predictedRate,
            'avg_job_time' => $avgJobTime,
            'avg_job_time_source' => $jobTimeSource,
            'arrival_rate_source' => $this->arrivalRateSource,
            'backlog' => $backlogSize,
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilizationRate,
            'utilization_adjustment' => $utilizationAdjustment,
        ];

        return (int) ceil(max($targetWorkers, 0));
    }

    public function getLastReason(): string
    {
        if (empty($this->lastCalculation)) {
            return 'No calculation performed yet';
        }

        $parts = [];
        $calc = $this->lastCalculation;

        // Explain which algorithm drove the decision
        $maxWorkers = max($calc['steady_state'], $calc['predictive'], $calc['backlog_drain']);

        if ($calc['backlog_drain'] >= $maxWorkers && $calc['backlog_drain'] > 0) {
            $parts[] = sprintf(
                'backlog=%d requires %.1f workers to prevent SLA breach',
                $calc['backlog'],
                $calc['backlog_drain']
            );
        } elseif ($calc['predictive'] >= $maxWorkers && $calc['predictive'] > $calc['steady_state']) {
            $parts[] = sprintf(
                'trend predicts rate increase to %.2f/s requiring %.1f workers',
                $calc['predicted_rate'],
                $calc['predictive']
            );
        } else {
            $fallbackIndicator = $this->usedFallback ? ' (estimated)' : '';
            $parts[] = sprintf(
                'arrival_rate=%.2f/s × job_time=%.1fs = %.1f workers%s',
                $calc['arrival_rate'],
                $calc['avg_job_time'],
                $calc['steady_state'],
                $fallbackIndicator
            );
        }

        // Add info about retry adjustment if significant
        if (($calc['retry_noise'] ?? 0) > 0.1) {
            $parts[] = sprintf(
                'adjusted for retries (%.1f%% failure rate removed %.2f/s noise)',
                $calc['failure_rate'],
                $calc['retry_noise']
            );
        }

        // Add arrival rate source if different from processing rate
        $arrivalRate = (float) $calc['arrival_rate'];
        $processingRate = (float) $calc['processing_rate'];
        // Only show this detail if we haven't already explained it via retry adjustment
        if (abs($arrivalRate - $processingRate) > 0.1 && ($calc['retry_noise'] ?? 0) <= 0.1) {
            $diff = $arrivalRate - $processingRate;
            $direction = $diff > 0 ? 'growing' : 'shrinking';
            $parts[] = sprintf('backlog %s (arrival %.2f/s vs processing %.2f/s)', $direction, $arrivalRate, $processingRate);
        }

        // Add utilization adjustment if applied
        $utilizationAdj = $calc['utilization_adjustment'] ?? '';
        if ($utilizationAdj !== '') {
            $parts[] = $utilizationAdj;
        }

        return implode('; ', $parts);
    }

    public function getLastPrediction(): ?float
    {
        if (empty($this->lastCalculation)) {
            return null;
        }

        $calc = $this->lastCalculation;

        // Estimate pickup time based on backlog and workers
        $targetWorkers = max(
            $calc['steady_state'],
            $calc['predictive'],
            $calc['backlog_drain'],
        );

        if ($targetWorkers <= 0 || $calc['backlog'] === 0) {
            return 0.0;
        }

        // Time to process backlog with target workers
        $jobsPerWorker = (float) $calc['backlog'] / (float) $targetWorkers;
        $timeToProcess = $jobsPerWorker * (float) $calc['avg_job_time'];

        return $timeToProcess;
    }

    /**
     * Determine job time using available metrics
     *
     * Priority order:
     * 1. Average duration from metrics (when available, always in seconds)
     * 2. Estimation from throughput and worker count
     * 3. Configurable fallback (default: 2.0 seconds)
     *
     * Note: The metrics package provides avgDuration in milliseconds, but
     * AutoscaleManager::mapMetricsFields() converts to seconds before creating
     * the QueueMetricsData DTO. The value here is always in seconds.
     *
     * @return array{float, string} [avgJobTime, source]
     */
    private function determineJobTime(
        QueueMetricsData $metrics,
        float $processingRate,
        int $activeWorkers
    ): array {
        // Priority 1: Use average duration from metrics (already in seconds)
        if ($metrics->avgDuration > 0) {
            $avgDurationSeconds = $metrics->avgDuration;

            // Sanity check: reject unreasonably low values (< 10ms)
            // and cap at reasonable maximum (10 minutes)
            if ($avgDurationSeconds >= 0.01 && $avgDurationSeconds <= 600.0) {
                return [$avgDurationSeconds, sprintf('metrics: %.2fs', $avgDurationSeconds)];
            }
        }

        // Priority 2: Estimate from throughput and active workers
        if ($activeWorkers > 0 && $processingRate > 0) {
            $estimated = $activeWorkers / $processingRate;

            // Sanity check: cap at reasonable maximum (10 minutes)
            $estimated = min($estimated, 600.0);

            return [$estimated, sprintf('estimated: %d workers / %.2f rate = %.2fs', $activeWorkers, $processingRate, $estimated)];
        }

        // Priority 3: Configurable fallback
        $fallback = AutoscaleConfiguration::fallbackJobTimeSeconds();

        return [$fallback, sprintf('fallback: %.1fs (configurable)', $fallback)];
    }

    /**
     * Apply trend policy to predict future arrival rate
     *
     * Since we now estimate arrival rate from backlog changes,
     * the trend policy provides additional forward-looking adjustment.
     */
    private function applyTrendPolicy(float $currentRate, TrendScalingPolicy $policy): float
    {
        if (! $policy->isEnabled()) {
            return $currentRate;
        }

        // Apply a small growth factor based on policy aggressiveness
        // This helps anticipate continued growth during spikes
        $growthFactor = match ($policy) {
            TrendScalingPolicy::HINT => 1.1,       // 10% buffer
            TrendScalingPolicy::MODERATE => 1.2,   // 20% buffer
            TrendScalingPolicy::AGGRESSIVE => 1.3, // 30% buffer
            default => 1.0,
        };

        return $currentRate * $growthFactor;
    }

    /**
     * Estimate arrival rate when no data is available
     *
     * Only estimates when there's actual evidence of incoming work (significant backlog).
     * Does NOT estimate from worker capacity - having workers doesn't mean jobs are arriving.
     *
     * @param  int  $backlogSize  Number of pending jobs
     * @param  int  $activeWorkers  Current active workers (unused - kept for signature)
     * @param  int  $oldestJobAge  Age of oldest job in seconds
     * @param  float  $avgJobTime  Average processing time per job
     * @param  int  $slaTarget  Max pickup time SLA in seconds
     * @return float Estimated arrival rate in jobs/second
     */
    private function estimateFallbackArrivalRate(
        int $backlogSize,
        int $activeWorkers,
        int $oldestJobAge,
        float $avgJobTime,
        int $slaTarget,
    ): float {
        // Only estimate if there's a significant backlog (more than a few jobs)
        // Small backlogs (0-2 jobs) don't indicate meaningful arrival rate
        if ($backlogSize < 3) {
            return 0.0;
        }

        // Estimate from backlog demand - how fast do we need to process?
        if ($slaTarget > 0) {
            if ($oldestJobAge > 0) {
                // Calculate urgency based on how close we are to SLA breach
                $urgencyFactor = min($oldestJobAge / max($slaTarget * 0.5, 1), 2.0);
                $baseRate = $backlogSize / max($slaTarget, 1);

                return $baseRate * $urgencyFactor;
            }

            // No age data: estimate conservatively
            return $backlogSize / max($slaTarget, 1);
        }

        // Last resort: estimate based on backlog and job time
        if ($avgJobTime > 0) {
            // How many jobs per second would clear this backlog in 60 seconds?
            return $backlogSize / 60.0;
        }

        // Truly idle state - no arrivals
        return 0.0;
    }
}
