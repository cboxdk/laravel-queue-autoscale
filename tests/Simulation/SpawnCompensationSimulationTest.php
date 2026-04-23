<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
// SpawnCompensationConfiguration is used in the contract method signatures below
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\DisabledForecastPolicy;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\ScalingSimulation;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\WorkloadSimulator;

/**
 * Spawn Compensation Simulation Tests
 *
 * Verifies that HybridStrategy's spawn compensation feature causes
 * BacklogDrainCalculator to trigger a scale-up decision earlier during a
 * sudden traffic spike. The compensation works by subtracting the known
 * spawn latency from the effective SLA budget:
 *
 *   effectiveSla = slaTarget - spawnLatency
 *   slaProgress  = oldestJobAge / effectiveSla
 *
 * BacklogDrainCalculator fires when slaProgress >= 0.5 (breachThreshold).
 * With spawn compensation, the 50% threshold is reached sooner in wall-clock
 * time, so the autoscaler issues the scale-up decision one full scaling
 * interval (5 s) before the uncompensated path would.
 *
 * ## Why we measure "first scale-up tick", not "breach tick count"
 *
 * "Breach tick count" (ticks where oldest-job age > SLA) is dominated by
 * simulator artefacts that are unrelated to compensation:
 *
 *  1. Fractional post-spike arrivals (0.05 jobs/tick) add ceil(0.05)=1
 *     integer entries to the job-queue each tick but only 0.05 to the float
 *     backlog counter. The mismatch leaves orphan queue entries that age
 *     indefinitely, producing identical breach counts in both runs.
 *  2. The aggressive scale-down logic reduces workers within one interval of
 *     the initial scale-up; both runs converge to the same drain trajectory,
 *     masking the 5-tick head-start that compensation provides.
 *
 * The tick at which the first scale-up decision is issued is immune to both
 * artefacts: it depends only on when slaProgress reaches the 0.5 threshold,
 * which is determined purely by oldestJobAge and effectiveSla.
 *
 * ## Why the numbers are deterministic
 *
 * - Spike: 20 jobs/s for ticks 1–15, then near-idle (0.05 jobs/s)
 * - 1 initial worker: at tick T, backlog ≈ 19T, oldestJobAge ≈ T-1
 * - SLA = 30 s, spawnLatency = 5 s
 *
 * Without compensation (effectiveSla = 30):
 *   threshold at oldestAge >= 15 s → first evaluation tick where age ≥ 15
 *   is tick 20 (age ≈ 18 s at that point, since scaling checks every 5 ticks
 *   and the age at tick 20 = 18, which is > 15)
 *
 * With compensation (effectiveSla = 25):
 *   threshold at oldestAge >= 12.5 s → first evaluation tick where age ≥ 12.5
 *   is tick 15 (age = 14 s, which is > 12.5)
 *
 * Δ = 5 ticks = exactly one scaling interval. The fake tracker provides a
 * fixed latency (no EMA learning), making the result reproducible.
 *
 * Run locally with: vendor/bin/pest tests/Simulation/SpawnCompensationSimulationTest.php
 * Excluded from CI due to execution time.
 */
uses()->group('simulation');

/**
 * Build a fake SpawnLatencyTrackerContract that always returns a fixed latency.
 *
 * This models the production scenario where EmaSpawnLatencyTracker has already
 * collected enough spawn observations to report a stable latency figure. By
 * injecting a fixed value, the test is deterministic — we remove the learning
 * phase and test the compensation logic directly.
 */
function makeFixedLatencyTracker(float $latencySeconds): SpawnLatencyTrackerContract
{
    return new class($latencySeconds) implements SpawnLatencyTrackerContract
    {
        public function __construct(private readonly float $latency) {}

        public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void {}

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
        {
            return $this->latency;
        }
    };
}

/**
 * Run a sudden-spike simulation with spawn compensation toggled on or off.
 *
 * The metric returned is the simulation tick at which the first scale-up
 * decision is issued. This cleanly isolates the compensation threshold
 * arithmetic from downstream artefacts (scale-down oscillation, fractional
 * arrival rounding) that pollute breach-count comparisons.
 *
 * Returns null if no scale-up was ever triggered (which itself would be a
 * bug in the simulation, distinct from the compensation comparison).
 */
function runSpawnSpikeSimulation(bool $compensationEnabled): ?int
{
    $slaTarget = 30;
    $scalingInterval = 5;
    $duration = 180; // 3 minutes — enough to observe spike, scale-up, and drain
    $spawnLatencySeconds = 5.0;

    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'spike',
        sla: new SlaConfiguration(
            targetSeconds: $slaTarget,
            percentile: 95,
            windowSeconds: 300,
            minSamples: 20,
        ),
        forecast: new ForecastConfiguration(
            // Disable forecasting so only the backlog-drain path is tested;
            // this isolates the spawn compensation effect cleanly.
            forecasterClass: LinearRegressionForecaster::class,
            policyClass: DisabledForecastPolicy::class,
            horizonSeconds: 60,
            historySeconds: 300,
        ),
        spawnCompensation: new SpawnCompensationConfiguration(
            enabled: $compensationEnabled,
            fallbackSeconds: $spawnLatencySeconds,
            minSamples: 5,
            emaAlpha: 0.2,
        ),
        workers: new WorkerConfiguration(
            min: 1,
            max: 50,
            tries: 3,
            timeoutSeconds: 3600,
            sleepSeconds: 1,
            shutdownTimeoutSeconds: 30,
        ),
    );

    // Spike: 20×-multiplier for first 15 ticks (20 jobs/s × base 1.0), then near-idle.
    // At tick T (T ≤ 15), backlog ≈ 19T, oldestJobAge ≈ T-1.
    // BacklogDrainCalculator fires when oldestJobAge / effectiveSla ≥ 0.5 (breachThreshold).
    //   - Without compensation: effectiveSla = 30, fires at age ≥ 15 → tick 20
    //   - With    compensation: effectiveSla = 25, fires at age ≥ 12.5 → tick 15
    $pattern = [];
    for ($t = 1; $t <= $duration; $t++) {
        $pattern[$t] = $t <= 15 ? 20.0 : 0.05;
    }

    $simulation = new ScalingSimulation(
        simulator: new WorkloadSimulator(baseArrivalRate: 1.0, avgJobTime: 1.0),
        config: $config,
        arrivalEstimator: null,
        spawnTracker: makeFixedLatencyTracker($spawnLatencySeconds),
    );

    $result = $simulation
        ->setWorkloadPattern($pattern)
        ->setScalingInterval($scalingInterval)
        ->setScalingDelay(1) // 1-tick delay before worker changes take effect
        ->setInitialWorkers(1)
        ->run($duration);

    // Return the tick at which the autoscaler first decided to scale up.
    // This directly measures when BacklogDrainCalculator crossed the 0.5
    // threshold, which is shifted earlier by spawn compensation.
    return $result->getTimeToFirstScaleUp();
}

test('spawn compensation triggers scale-up one full interval earlier during sudden spike', function (): void {
    $firstScaleUpWithout = runSpawnSpikeSimulation(compensationEnabled: false);
    $firstScaleUpWith = runSpawnSpikeSimulation(compensationEnabled: true);

    // Both runs must have produced at least one scale-up decision.
    // If either is null the simulation scenario itself is broken.
    expect($firstScaleUpWithout)->not->toBeNull('no-compensation run produced no scale-up decisions');
    expect($firstScaleUpWith)->not->toBeNull('compensation run produced no scale-up decisions');

    // The assertion is strict: compensation MUST trigger the first scale-up
    // at an earlier tick than the uncompensated path.
    //
    // Why this is reliable and directly tied to the compensation mechanism:
    //
    //   BacklogDrainCalculator uses: slaProgress = oldestJobAge / effectiveSla
    //   Action threshold: slaProgress >= breachThreshold (0.5)
    //
    //   Without compensation: effectiveSla = 30, threshold at age ≥ 15 s
    //     → first scaling evaluation where age ≥ 15 is tick 20 (age ≈ 18 s)
    //
    //   With    compensation: effectiveSla = 25 (30 - 5 s latency),
    //                          threshold at age ≥ 12.5 s
    //     → first scaling evaluation where age ≥ 12.5 is tick 15 (age ≈ 14 s)
    //
    //   Expected difference: 5 ticks = exactly one scaling interval.
    //
    // This metric is immune to the artefacts that make breach-tick-count an
    // unreliable comparison (fractional arrival rounding, scale-down
    // oscillation). It captures the pure effect of the compensation
    // arithmetic on the scale-up trigger point.
    //
    // Design note: a fake tracker (not the real EmaSpawnLatencyTracker) is
    // used intentionally to isolate the compensation arithmetic in
    // HybridStrategy and BacklogDrainCalculator from the EMA measurement
    // pipeline. Integration of the real tracker is covered by
    // EmaSpawnLatencyTrackerTest and the SpawnLatencyRecorder listener.
    expect($firstScaleUpWith)->toBeLessThan($firstScaleUpWithout);

    // Additionally verify the magnitude: the difference should be at least
    // one full scaling interval (5 ticks). A larger difference is also
    // acceptable (e.g., if compensation crosses a lower threshold band).
    $scalingInterval = 5;
    expect($firstScaleUpWithout - $firstScaleUpWith)->toBeGreaterThanOrEqual($scalingInterval);
})->group('simulation');
