<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;

beforeEach(function () {
    $spawnTracker = new class implements SpawnLatencyTrackerContract
    {
        public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void {}

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
        {
            return 0.0;
        }
    };

    $pickupStore = new class implements PickupTimeStoreContract
    {
        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void {}

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };

    $this->strategy = new HybridStrategy(
        littles: new LittlesLawCalculator,
        backlog: new BacklogDrainCalculator,
        arrivalEstimator: new ArrivalRateEstimator,
        spawnTracker: $spawnTracker,
        pickupStore: $pickupStore,
        percentileCalc: new SortBasedPercentileCalculator,
    );
    $this->capacity = new CapacityCalculator;
    $this->engine = new ScalingEngine($this->strategy, $this->capacity);
});

it('evaluateDemand clamps at config max workers', function () {
    $config = makeQueueConfig(['maxWorkers' => 10]);

    $highLoadMetrics = createMetrics([
        'throughput_per_minute' => 6000.0,
        'active_workers' => 200,
        'pending' => 1000,
        'oldest_job_age' => 28,
    ]);

    $target = $this->engine->evaluateDemand($highLoadMetrics, $config);

    expect($target)->toBeLessThanOrEqual(10);
});

it('evaluateDemand clamps at config min workers', function () {
    $config = makeQueueConfig(['minWorkers' => 3]);

    $emptyMetrics = createMetrics([
        'throughput_per_minute' => 0.0,
        'active_workers' => 0,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $target = $this->engine->evaluateDemand($emptyMetrics, $config);

    expect($target)->toBeGreaterThanOrEqual(3);
});

it('evaluateDemand ignores system capacity constraints', function () {
    // With high config max, evaluate() would be capped by system capacity.
    // evaluateDemand() should return higher because it skips capacity.
    $config = makeQueueConfig(['maxWorkers' => 1000]);

    $highLoadMetrics = createMetrics([
        'throughput_per_minute' => 6000.0,
        'active_workers' => 200,
        'pending' => 1000,
        'oldest_job_age' => 28,
    ]);

    $demand = $this->engine->evaluateDemand($highLoadMetrics, $config);

    // evaluate() with 50 total pool workers would cap at system capacity
    $decision = $this->engine->evaluate($highLoadMetrics, $config, 5, 50);

    expect($demand)->toBeGreaterThanOrEqual($decision->targetWorkers);
});

it('evaluateDemand returns strategy recommendation when within config bounds', function () {
    $config = makeQueueConfig(['minWorkers' => 1, 'maxWorkers' => 20]);

    $metrics = createMetrics([
        'throughput_per_minute' => 300.0,
        'active_workers' => 5,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $target = $this->engine->evaluateDemand($metrics, $config);

    expect($target)->toBeGreaterThanOrEqual(1)
        ->and($target)->toBeLessThanOrEqual(20);
});
