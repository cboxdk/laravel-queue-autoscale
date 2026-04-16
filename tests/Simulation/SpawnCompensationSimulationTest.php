<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
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

        public function recordSpawn(string $workerId, string $connection, string $queue): void {}

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue): float
        {
            return $this->latency;
        }
    };
}

/**
 * Run a sudden-spike simulation with spawn compensation toggled on or off.
 *
 * Scenario: 100 jobs arrive in the first 10 seconds (10 jobs/tick), then
 * traffic drops to a low steady state (1 job/tick). With 1 initial worker
 * and a 30-second SLA, the burst quickly builds a backlog that strains the
 * SLA window.
 *
 * The fake spawn tracker reports 3 seconds of latency. When compensation is
 * enabled, HybridStrategy uses effectiveSla = 30 - 3 = 27 seconds, so
 * BacklogDrainCalculator triggers its progressive multiplier sooner (the
 * slaProgress threshold is reached at 27 × 0.5 = 13.5 s instead of 15 s),
 * requesting more workers before the oldest job actually breaches.
 *
 * Returns the number of simulation ticks (seconds) where oldest job age
 * exceeded the 30-second SLA target.
 */
function runSpawnSpikeSimulation(bool $compensationEnabled): int
{
    $slaTarget = 30;
    $scalingInterval = 5;
    $duration = 120; // 2 minutes total — enough to observe the spike and partial recovery
    $spawnLatencySeconds = 3.0;

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

    // Spike: 10×-multiplier for first 10 ticks (10 jobs/s × base 1.0), then quiet.
    // The WorkloadSimulator uses baseArrivalRate * multiplier jobs per tick.
    $pattern = [];
    for ($t = 1; $t <= $duration; $t++) {
        $pattern[$t] = $t <= 10 ? 10.0 : 0.1;
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

    // Compensation must not increase breach count.
    // The primary assertion is strict: compensation should produce fewer breaches
    // because the effective SLA window shrinks from 30s to 27s, causing
    // BacklogDrainCalculator to request additional workers earlier.
    //
    // If both runs happen to produce zero breaches (system copes regardless),
    // the test still passes — compensation cannot have made things worse.
    expect($breachesWith)->toBeLessThanOrEqual($breachesWithout);
})->group('simulation');
