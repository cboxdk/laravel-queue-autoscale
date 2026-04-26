<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\DisabledForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\ScalingSimulation;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\WorkloadSimulator;

/**
 * Forecasting Simulation Tests
 *
 * Verifies that the ModerateForecastPolicy reduces SLA breaches compared to
 * DisabledForecastPolicy on a predictable linear ramp-up scenario.
 *
 * The linear ramp-up is ideal for the LinearRegressionForecaster because
 * the R² for a perfect linear signal approaches 1.0, well above the
 * ModerateForecastPolicy threshold of 0.6.
 *
 * Run locally with: vendor/bin/pest tests/Simulation/ForecastingSimulationTest.php
 * Excluded from CI due to execution time.
 */
uses()->group('simulation');

/**
 * Run a 3-minute linear ramp simulation with the given forecast policy.
 *
 * The simulation models a queue where arrival rate increases linearly from
 * 1 job/s to 10 jobs/s over 3 minutes (180 seconds). This is a scenario
 * where a forecaster with a good R² signal should proactively scale ahead
 * of the curve, reducing SLA breaches compared to reactive-only scaling.
 *
 * To ensure the forecaster has enough historical data to engage from the
 * beginning, we pre-seed the ArrivalRateEstimator with synthetic snapshots
 * representing the first 30 seconds of the ramp (6 evaluation points at
 * 5-second intervals). The LinearRegressionForecaster requires >= 5 samples.
 *
 * @param  class-string  $policyClass  Either DisabledForecastPolicy::class or ModerateForecastPolicy::class
 * @return int Number of ticks (seconds) where oldest job age exceeded the SLA target
 */
function runRampSimulation(string $policyClass): int
{
    $slaTarget = 30;
    $scalingInterval = 5;
    $duration = 180; // 3 minutes
    $queueKey = 'redis:default';

    // Build configuration with the requested forecast policy
    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        sla: new SlaConfiguration(
            targetSeconds: $slaTarget,
            percentile: 95,
            windowSeconds: 300,
            minSamples: 20,
        ),
        forecast: new ForecastConfiguration(
            forecasterClass: LinearRegressionForecaster::class,
            policyClass: $policyClass,
            horizonSeconds: 60,
            historySeconds: 300,
        ),
        spawnCompensation: new SpawnCompensationConfiguration(
            enabled: false,
            fallbackSeconds: 2.0,
            minSamples: 5,
            emaAlpha: 0.2,
        ),
        workers: new WorkerConfiguration(
            min: 1,
            max: 20,
            tries: 3,
            timeoutSeconds: 3600,
            sleepSeconds: 3,
            shutdownTimeoutSeconds: 30,
        ),
    );

    // Build and seed the ArrivalRateEstimator with synthetic pre-history.
    //
    // The simulation ticks execute in microseconds of wall-clock time, so the
    // ArrivalRateEstimator's real-time MIN_INTERVAL guard (1.0 second) would
    // prevent history from accumulating meaningful intervals. To work around this,
    // we pre-seed the estimator with snapshots whose timestamps are spaced 5
    // seconds apart (matching the scaling interval), positioned in the past
    // relative to "now". This ensures the linear regression has >= 5 samples
    // with realistic time deltas on the very first evaluation.
    //
    // The synthetic backlog values model a system with 1 initial worker
    // processing jobs at 1/s where arrivals ramp from 1/s to 10/s: the backlog
    // grows by (arrivalRate - 1) jobs per second. We compute cumulative backlog
    // at each 5-second evaluation point during the pre-history window.
    $estimator = new ArrivalRateEstimator;

    // Configure the forecaster on the estimator directly (bypasses the lazy
    // app() call in HybridStrategy since hasForecaster() will return true)
    $estimator->setForecaster(
        forecaster: new LinearRegressionForecaster,
        policy: new $policyClass,
        horizonSeconds: 60,
    );

    // Synthetic pre-history: 6 snapshots spaced 5 seconds apart ending ~5 seconds ago.
    // Represents ticks 0–30 of the ramp: rate at tick t = 1 + (t/180)*9
    // Backlog after n seconds with 1 worker ≈ sum of (rate_t - 1) for t=1..n
    $now = microtime(true);
    $preHistorySeconds = 30; // 6 evaluation points at 5-second intervals
    $syntheticSnapshots = [];

    $cumulativeBacklog = 0.0;
    for ($t = 0; $t <= $preHistorySeconds; $t += $scalingInterval) {
        // Arrival rate at this tick in the pre-history window
        $arrivalRate = 1.0 + ($t / 180.0) * 9.0;

        // Backlog grows at (arrivalRate - processingCapacity); with 1 worker: 1 job/s capacity
        if ($t > 0) {
            $cumulativeBacklog += ($arrivalRate - 1.0) * $scalingInterval;
        }

        // Place this snapshot in the past, spaced 5 seconds apart from "now"
        $timestamp = $now - ($preHistorySeconds - $t);

        $syntheticSnapshots[] = [
            'backlog' => (int) max(0, $cumulativeBacklog),
            'timestamp' => $timestamp,
        ];
    }

    $estimator->seedHistory($queueKey, $syntheticSnapshots);

    // Build the workload pattern: linear ramp from multiplier 1.0 to 10.0
    // over 180 ticks. baseArrivalRate=1.0 so actual arrivals = 1 * multiplier
    $pattern = [];
    for ($t = 1; $t <= $duration; $t++) {
        $pattern[$t] = 1.0 + (($t / (float) $duration) * 9.0);
    }

    // Run the simulation with the pre-seeded estimator
    $simulation = new ScalingSimulation(
        simulator: new WorkloadSimulator(baseArrivalRate: 1.0, avgJobTime: 1.0),
        config: $config,
        arrivalEstimator: $estimator,
    );

    $result = $simulation
        ->setWorkloadPattern($pattern)
        ->setScalingInterval($scalingInterval)
        ->setScalingDelay(1)
        ->setInitialWorkers(1)
        ->run($duration);

    // Count breach ticks: ticks where oldest job age exceeded the SLA target
    $breachTicks = 0;
    foreach ($result->getHistory() as $tick) {
        if ($tick['oldestAge'] > $slaTarget) {
            $breachTicks++;
        }
    }

    return $breachTicks;
}

test('forecasting reduces SLA breaches on gradual ramp compared to disabled forecast', function (): void {
    $breachesDisabled = runRampSimulation(DisabledForecastPolicy::class);
    $breachesModerate = runRampSimulation(ModerateForecastPolicy::class);

    // The moderate policy should reduce breaches by at least 30% compared to disabled.
    // On a perfect linear ramp, R² → 1.0, so the forecaster projects ahead and scales
    // workers proactively rather than waiting for the backlog to grow.
    $reductionThreshold = (int) floor($breachesDisabled * 0.7);

    expect($breachesModerate)
        ->toBeLessThan(max(1, $reductionThreshold),
            sprintf(
                'Expected moderate policy to produce < %d breach ticks (30%% fewer than disabled\'s %d), but got %d',
                max(1, $reductionThreshold),
                $breachesDisabled,
                $breachesModerate,
            )
        );
})->group('simulation');
