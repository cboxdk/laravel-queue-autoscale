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
 * Verifies that HybridStrategy's spawn compensation feature reduces cold-start
 * SLA breaches during a sudden traffic spike. The compensation works by
 * subtracting the known spawn latency from the effective SLA budget, causing
 * BacklogDrainCalculator to trigger scaling earlier — before a real worker
 * would have had time to start and pick up jobs.
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
 * Scenario: 300 jobs arrive in the first 15 seconds (20 jobs/tick × base 1.0),
 * then traffic drops to a near-idle trickle (0.05 jobs/tick). With 1 initial
 * worker, avgJobTime=1s, and a 30-second SLA, the burst builds a 300-job backlog
 * far faster than any scaling reaction can drain it, guaranteeing SLA breaches
 * in the uncompensated run.
 *
 * The fake spawn tracker reports 5 seconds of latency. When compensation is
 * enabled, HybridStrategy uses effectiveSla = 30 - 5 = 25 seconds, so
 * BacklogDrainCalculator triggers its progressive multiplier sooner (slaProgress
 * reaches 0.5 at 12.5 s instead of 15 s), requesting more workers before the
 * oldest job actually breaches.
 *
 * Spike severity is deliberately large (300 jobs vs 50-worker max) to ensure
 * the no-compensation run produces breaches. The compensation run requests
 * workers one scaling interval earlier, producing measurably fewer breach ticks.
 * Both runs use a deterministic fake tracker (no EMA learning phase) so the test
 * is reproducible.
 *
 * Returns the number of simulation ticks where oldest job age exceeded the SLA.
 */
function runSpawnSpikeSimulation(bool $compensationEnabled): int
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
    // Total burst: 300 jobs. With avgJobTime=1s and max 50 workers, draining takes ~6s
    // at full capacity — but scaling must first be triggered and workers deployed.
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

    // Count ticks where oldest job age exceeded the SLA
    $breachTicks = 0;
    foreach ($result->getHistory() as $tick) {
        if ($tick['oldestAge'] > $slaTarget) {
            $breachTicks++;
        }
    }

    return $breachTicks;
}

test('spawn compensation reduces SLA breaches during sudden spike with slow worker startup', function (): void {
    $breachesWithout = runSpawnSpikeSimulation(compensationEnabled: false);
    $breachesWith = runSpawnSpikeSimulation(compensationEnabled: true);

    // The assertion is strict: compensation MUST produce fewer breach ticks.
    //
    // Why this is reliable:
    // - The 300-job spike guarantees the no-compensation run breaches the 30s SLA
    //   (300 jobs / 50 max workers = 6s to drain at full capacity, but scaling must
    //   react first — the backlog age will exceed 30s before enough workers arrive).
    // - With effectiveSla = 25s (30 - 5 spawn latency), BacklogDrainCalculator
    //   requests workers when oldest age reaches 12.5s instead of 15s, one full
    //   scaling interval (5s) earlier than the uncompensated path.
    // - Both runs use a fixed-latency fake tracker: no non-determinism from EMA
    //   learning or Redis state.
    //
    // Design note: a fake tracker (not the real EmaSpawnLatencyTracker) is used
    // intentionally to isolate the compensation arithmetic in HybridStrategy and
    // BacklogDrainCalculator from the EMA measurement pipeline. Integration of
    // the real tracker is covered by EmaSpawnLatencyTrackerTest and the
    // SpawnLatencyRecorder listener.
    expect($breachesWith)->toBeLessThan($breachesWithout);
})->group('simulation');
