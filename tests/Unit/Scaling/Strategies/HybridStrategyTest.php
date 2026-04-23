<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;

function hybridMakeConfig(): QueueConfiguration
{
    config(['queue-autoscale.sla_defaults' => BalancedProfile::class, 'queue-autoscale.queues' => []]);

    return QueueConfiguration::fromConfig('redis', 'default');
}

function hybridFakeSpawnTracker(float $latency): SpawnLatencyTrackerContract
{
    return new class($latency) implements SpawnLatencyTrackerContract
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
 * @param  list<array{timestamp: float, pickup_seconds: float}>  $samples
 */
function hybridFakePickupStore(array $samples): PickupTimeStoreContract
{
    return new class($samples) implements PickupTimeStoreContract
    {
        /**
         * @param  list<array{timestamp: float, pickup_seconds: float}>  $samples
         */
        public function __construct(private readonly array $samples) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void {}

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return $this->samples;
        }
    };
}

test('target workers is bounded by workers.max', function (): void {
    $strategy = new HybridStrategy(
        littles: new LittlesLawCalculator,
        backlog: new BacklogDrainCalculator,
        arrivalEstimator: new ArrivalRateEstimator,
        spawnTracker: hybridFakeSpawnTracker(1.0),
        pickupStore: hybridFakePickupStore([]),
        percentileCalc: new SortBasedPercentileCalculator,
    );

    $config = hybridMakeConfig();
    $metrics = createMetrics([
        'pending' => 10_000,
        'oldest_job_age' => 300,
        'active_workers' => 1,
        'throughput_per_minute' => 10.0,
        'avg_duration' => 2.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 100.0,
    ]);

    $target = $strategy->calculateTargetWorkers($metrics, $config);

    expect($target)->toBeLessThanOrEqual($config->workers->max);
});

test('scales up when p95 exceeds effective SLA', function (): void {
    $samples = [];
    for ($i = 0; $i < 100; $i++) {
        $samples[] = ['timestamp' => (float) time(), 'pickup_seconds' => 26.0];
    }

    $strategy = new HybridStrategy(
        littles: new LittlesLawCalculator,
        backlog: new BacklogDrainCalculator,
        arrivalEstimator: new ArrivalRateEstimator,
        spawnTracker: hybridFakeSpawnTracker(2.0),
        pickupStore: hybridFakePickupStore($samples),
        percentileCalc: new SortBasedPercentileCalculator,
    );

    $config = hybridMakeConfig();
    $metrics = createMetrics([
        'pending' => 50,
        'oldest_job_age' => 20,
        'active_workers' => 2,
        'throughput_per_minute' => 60.0,
        'avg_duration' => 1.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 50.0,
    ]);

    $target = $strategy->calculateTargetWorkers($metrics, $config);

    expect($target)->toBeGreaterThan(2);
});

test('clamps target to workers.min', function (): void {
    $strategy = new HybridStrategy(
        littles: new LittlesLawCalculator,
        backlog: new BacklogDrainCalculator,
        arrivalEstimator: new ArrivalRateEstimator,
        spawnTracker: hybridFakeSpawnTracker(0.5),
        pickupStore: hybridFakePickupStore([]),
        percentileCalc: new SortBasedPercentileCalculator,
    );

    $config = hybridMakeConfig();
    $metrics = createMetrics([
        'pending' => 0,
        'oldest_job_age' => 0,
        'active_workers' => 0,
        'throughput_per_minute' => 0.0,
        'avg_duration' => 0.0,
        'failure_rate' => 0.0,
        'utilization_rate' => 0.0,
    ]);

    $target = $strategy->calculateTargetWorkers($metrics, $config);

    expect($target)->toBeGreaterThanOrEqual($config->workers->min);
});
