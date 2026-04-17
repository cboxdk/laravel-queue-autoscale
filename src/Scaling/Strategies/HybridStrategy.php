<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Hybrid scaling strategy integrating multiple v2 components
 *
 * Combines five approaches to determine optimal worker count:
 * 1. Rate-Based (Little's Law): Workers needed for steady-state throughput
 * 2. Backlog-Based: Workers needed to drain queue before SLA breach
 * 3. Forecast-Blended Arrival Rate: Estimated true arrival rate with forecasting
 * 4. p95 Pickup Time Signal: Uses sliding-window percentile over actual pickup times
 * 5. Spawn-Compensated Effective SLA: Subtracts EMA spawn latency from SLA budget
 *
 * Takes the maximum of all algorithms to ensure SLA compliance.
 */
final class HybridStrategy implements ScalingStrategyContract
{
    /**
     * @var array<string, float|int|string|null>
     */
    private array $lastCalculation = [];

    private bool $usedFallback = false;

    private string $arrivalRateSource = '';

    public function __construct(
        private readonly LittlesLawCalculator $littles,
        private readonly BacklogDrainCalculator $backlog,
        private readonly ArrivalRateEstimator $arrivalEstimator,
        private readonly SpawnLatencyTrackerContract $spawnTracker,
        private readonly PickupTimeStoreContract $pickupStore,
        private readonly PercentileCalculatorContract $percentileCalc,
    ) {}

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

        // Lazily configure the forecaster from queue config if not already set
        if (! $this->arrivalEstimator->hasForecaster()) {
            $this->arrivalEstimator->setForecaster(
                forecaster: app($config->forecast->forecasterClass),
                policy: app($config->forecast->policyClass),
                horizonSeconds: $config->forecast->horizonSeconds,
            );
        }

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
                $config->sla->targetSeconds
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
        $failureRate = $metrics->failureRate;
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

        // Compute effective SLA after subtracting spawn latency
        $spawnLatency = $config->spawnCompensation->enabled
            ? $this->spawnTracker->currentLatency($config->connection, $config->queue, $config->spawnCompensation)
            : 0.0;
        $effectiveSla = max(1.0, (float) $config->sla->targetSeconds - $spawnLatency);

        // Compute p95 pickup time signal from sliding window
        $pickupSamples = $this->pickupStore->recentSamples(
            $config->connection,
            $config->queue,
            $config->sla->windowSeconds,
        );
        $pickupTimes = array_map(static fn (array $s): float => $s['pickup_seconds'], $pickupSamples);
        $p95 = $this->percentileCalc->compute(
            $pickupTimes,
            $config->sla->percentile,
            $config->sla->minSamples,
        );
        $slaSignal = $p95 ?? (float) $oldestJobAge;
        $slaSignalSource = $p95 !== null ? 'p95' : 'oldest_age_fallback';

        // 1. RATE-BASED: Little's Law (L = λW)
        // Workers needed to handle current (adjusted) arrival rate
        $steadyStateWorkers = $this->littles->calculate($arrivalRate, $avgJobTime);

        // 2. BACKLOG-BASED: Prevent SLA breach using p95/oldest-age signal and effective SLA
        $breachThreshold = AutoscaleConfiguration::breachThreshold();
        $backlogDrainWorkers = $this->backlog->calculateRequiredWorkers(
            backlog: $backlogSize,
            oldestJobAge: (int) $slaSignal,
            slaTarget: $config->sla->targetSeconds,
            avgJobTime: $avgJobTime,
            breachThreshold: $breachThreshold,
            effectiveSlaSeconds: $effectiveSla,
        );

        // 3. COMBINE: Take maximum (most conservative)
        $targetWorkers = max($steadyStateWorkers, $backlogDrainWorkers);

        // 4. UTILIZATION ADJUSTMENT: Use worker utilization as a real-time signal
        // Utilization rate from metrics tells us how busy current workers actually are,
        // providing ground truth that complements the algorithmic calculations.
        $utilizationRate = $metrics->utilizationRate;
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
            'backlog_drain' => $backlogDrainWorkers,
            'arrival_rate' => $arrivalRate,
            'raw_arrival_rate' => $rawArrivalRate,
            'retry_noise' => $retryNoise,
            'processing_rate' => $processingRate,
            'avg_job_time' => $avgJobTime,
            'avg_job_time_source' => $jobTimeSource,
            'arrival_rate_source' => $this->arrivalRateSource,
            'backlog' => $backlogSize,
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilizationRate,
            'utilization_adjustment' => $utilizationAdjustment,
            'spawn_latency' => $spawnLatency,
            'effective_sla' => $effectiveSla,
            'p95' => $p95,
            'sla_signal' => $slaSignal,
            'sla_signal_source' => $slaSignalSource,
        ];

        // Clamp to [workers.min, workers.max]
        $targetWorkers = max(
            $config->workers->min,
            min($config->workers->max, (int) ceil(max($targetWorkers, 0))),
        );

        return $targetWorkers;
    }

    public function getLastReason(): string
    {
        if (empty($this->lastCalculation)) {
            return 'No calculation performed yet';
        }

        $parts = [];
        $calc = $this->lastCalculation;

        // Explain which algorithm drove the decision
        $maxWorkers = max((float) $calc['steady_state'], (float) $calc['backlog_drain']);

        if ((float) $calc['backlog_drain'] >= $maxWorkers && (float) $calc['backlog_drain'] > 0) {
            $slaSignalSource = (string) $calc['sla_signal_source'];
            $signalLabel = $slaSignalSource === 'p95' ? 'p95' : 'oldest_age';
            $parts[] = sprintf(
                'backlog=%d requires %.1f workers to prevent SLA breach (%s=%.1fs, effective_sla=%.1fs)',
                $calc['backlog'],
                $calc['backlog_drain'],
                $signalLabel,
                $calc['sla_signal'],
                $calc['effective_sla'],
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

        // Add spawn latency note when it meaningfully reduced the effective SLA
        $spawnLatency = (float) $calc['spawn_latency'];
        if ($spawnLatency > 0.5) {
            $parts[] = sprintf('spawn_latency=%.1fs subtracted from SLA (effective=%.1fs)', $spawnLatency, $calc['effective_sla']);
        }

        // Add utilization adjustment if applied
        $utilizationAdj = (string) ($calc['utilization_adjustment'] ?? '');
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
            (float) $calc['steady_state'],
            (float) $calc['backlog_drain'],
        );

        if ($targetWorkers <= 0 || $calc['backlog'] === 0) {
            return 0.0;
        }

        // Time to process backlog with target workers
        $jobsPerWorker = (float) $calc['backlog'] / $targetWorkers;
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
