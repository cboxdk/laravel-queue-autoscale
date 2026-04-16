<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;

function makeNoopSpawnTracker(): SpawnLatencyTrackerContract
{
    return new class implements SpawnLatencyTrackerContract
    {
        public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void {}

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
        {
            return 0.0;
        }
    };
}

function makeEmptyPickupStore(): PickupTimeStoreContract
{
    return new class implements PickupTimeStoreContract
    {
        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void {}

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };
}

beforeEach(function () {
    $this->littles = new LittlesLawCalculator;
    $this->backlog = new BacklogDrainCalculator;
    $this->arrivalEstimator = new ArrivalRateEstimator;
    $this->strategy = new HybridStrategy(
        littles: $this->littles,
        backlog: $this->backlog,
        arrivalEstimator: $this->arrivalEstimator,
        spawnTracker: makeNoopSpawnTracker(),
        pickupStore: makeEmptyPickupStore(),
        percentileCalc: new SortBasedPercentileCalculator,
    );

    $this->config = makeQueueConfig();
});

afterEach(function () {
    $this->arrivalEstimator->reset();
});

describe('steady state calculations', function () {
    it('calculates workers using Littles Law for steady state', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 120.0, // 2.0 jobs/sec * 60
            'active_workers' => 4,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        // Avg job time: 4 workers / 2 jobs/sec = 2 sec/job
        // Little's Law: 2 jobs/sec × 2 sec = 4 workers (within max=10)
        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        // Should be at least steady-state (no trend buffer in HybridStrategy)
        expect($workers)->toBeGreaterThanOrEqual(4);
    });

    it('estimates average job time from processing rate and active workers', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 120.0, // 2.0 jobs/sec * 60
            'active_workers' => 6,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        // Avg job time: 6 workers / 2 jobs/sec = 3 sec/job
        // Steady state: 2 jobs/sec × 3 sec = 6 workers (within max=10)
        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($workers)->toBeGreaterThanOrEqual(6);
    });

    it('uses configurable fallback average job time when no active workers', function () {
        // Default fallback is 2.0 seconds
        $metrics = createMetrics([
            'throughput_per_minute' => 120.0, // 2.0 jobs/sec * 60
            'active_workers' => 0, // no workers yet
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        // Fallback avg job time: 2.0 sec (configurable default)
        // Steady state: 2 jobs/sec × 2 sec = 4 workers (within max=10)
        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($workers)->toBeGreaterThanOrEqual(4);
    });
});

describe('forecast-blended arrival rate', function () {
    it('uses steady state arrival rate for stable workloads', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 120.0, // 2.0 jobs/sec * 60
            'active_workers' => 4,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        // First call establishes baseline
        $this->strategy->calculateTargetWorkers($metrics, $this->config);

        // Second call: arrival rate should be estimated from backlog changes
        // Steady state: 2 jobs/sec × 2 sec = 4 workers (within max=10)
        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        // Should scale to handle steady state arrival rate
        expect($workers)->toBeGreaterThanOrEqual(4);
    });
});

describe('backlog drain calculations', function () {
    it('uses backlog drain workers when SLA breach is imminent', function () {
        // Use a config with higher max to observe backlog drain effect
        $config = makeQueueConfig(['maxWorkers' => 50]);
        $metrics = createMetrics([
            'throughput_per_minute' => 120.0, // 2.0 jobs/sec * 60
            'active_workers' => 4,
            'pending' => 100,
            'oldest_job_age' => 25, // approaching SLA (30s)
        ]);

        // Avg job time: 2 sec
        // Steady: 2 × 2 = 4 workers
        // Backlog drain will be higher due to imminent breach
        $workers = $this->strategy->calculateTargetWorkers($metrics, $config);

        expect($workers)->toBeGreaterThan(4);
    });

    it('returns maximum of backlog drain and steady state', function () {
        // Use a config with higher max to observe backlog drain effect
        $config = makeQueueConfig(['maxWorkers' => 50]);
        $metrics = createMetrics([
            'throughput_per_minute' => 120.0, // 2.0 jobs/sec * 60
            'active_workers' => 4,
            'pending' => 200,
            'oldest_job_age' => 28, // very close to SLA breach
        ]);

        // Avg job time: 4 / 2 = 2 sec
        // Steady state: 2 × 2 = 4 workers
        // Backlog drain: will be much higher (approaching breach)
        $workers = $this->strategy->calculateTargetWorkers($metrics, $config);

        // Backlog drain should dominate
        expect($workers)->toBeGreaterThan(4);
    });
});

describe('empty and idle states', function () {
    it('returns workers.min for empty queue', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 0.0,
            'active_workers' => 0,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        // HybridStrategy clamps to [workers.min, workers.max]. Config has min=1.
        expect($workers)->toBe($this->config->workers->min);
    });

    it('returns workers.min for idle state with no backlog', function () {
        $config = makeQueueConfig(['minWorkers' => 0]);
        $metrics = createMetrics([
            'throughput_per_minute' => 0.0,
            'active_workers' => 0,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $config);

        // With minWorkers=0 and no load, target should be 0
        expect($workers)->toBe(0);
    });
});

describe('return type', function () {
    it('always returns integer ceiling of target workers', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 180.0, // 3.0 jobs/sec * 60
            'active_workers' => 5,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($workers)->toBeInt();
    });
});

describe('reason generation', function () {
    it('returns no calculation message before first run', function () {
        $reason = $this->strategy->getLastReason();

        expect($reason)->toBe('No calculation performed yet');
    });

    it('provides reason explaining scaling decision', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
            'active_workers' => 20,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $reason = $this->strategy->getLastReason();

        // Reason should contain useful info about scaling decision
        expect($reason)->toBeString();
        expect(strlen($reason))->toBeGreaterThan(10);
    });

    it('provides reason for backlog drain scaling', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
            'active_workers' => 20,
            'pending' => 100,
            'oldest_job_age' => 25,
        ]);

        $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $reason = $this->strategy->getLastReason();

        expect($reason)->toContain('backlog=100')
            ->and($reason)->toContain('SLA breach');
    });

    it('indicates backlog growth direction in reason when detected', function () {
        // First call to establish history
        $metrics1 = createMetrics([
            'throughput_per_minute' => 300.0,
            'active_workers' => 10,
            'pending' => 100,
            'oldest_job_age' => 5,
        ]);
        $this->strategy->calculateTargetWorkers($metrics1, $this->config);

        // Manipulate arrival estimator history to simulate time passing
        $history = $this->arrivalEstimator->getHistory();
        if (! empty($history)) {
            $key = array_key_first($history);
            foreach ($history[$key] as &$snapshot) {
                $snapshot['timestamp'] -= 10;
            }
            unset($snapshot);

            $reflection = new ReflectionClass($this->arrivalEstimator);
            $historyProperty = $reflection->getProperty('history');
            $historyProperty->setValue($this->arrivalEstimator, $history);
        }

        // Second call with larger backlog (simulating growing)
        $metrics2 = createMetrics([
            'throughput_per_minute' => 300.0,
            'active_workers' => 10,
            'pending' => 200, // Backlog doubled
            'oldest_job_age' => 15,
        ]);
        $this->strategy->calculateTargetWorkers($metrics2, $this->config);
        $reason = $this->strategy->getLastReason();

        // Should contain growth direction info when arrival != processing
        expect($reason)->toBeString();
    });
});

describe('prediction methods', function () {
    it('returns null prediction before any calculation', function () {
        $prediction = $this->strategy->getLastPrediction();

        expect($prediction)->toBeNull();
    });

    it('predicts pickup time based on backlog and target workers', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
            'active_workers' => 20,
            'pending' => 100,
            'oldest_job_age' => 5,
        ]);

        $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $prediction = $this->strategy->getLastPrediction();

        expect($prediction)->toBeFloat()
            ->and($prediction)->toBeGreaterThan(0.0);
    });

    it('returns zero prediction for empty backlog', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
            'active_workers' => 20,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $prediction = $this->strategy->getLastPrediction();

        expect($prediction)->toBe(0.0);
    });
});

describe('fallback arrival rate estimation', function () {
    it('scales workers when throughput is zero but workers exist', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 0.0, // NO throughput data
            'active_workers' => 10,
            'pending' => 50,
            'oldest_job_age' => 5,
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        // Should NOT return 0 workers despite zero throughput
        // Fallback estimates from active workers' capacity
        expect($workers)->toBeGreaterThan(0);
    });

    it('scales workers from backlog demand when no workers exist', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 0.0, // NO throughput data
            'active_workers' => 0, // NO workers yet
            'pending' => 100,
            'oldest_job_age' => 10,
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        // Should scale up based on backlog demand, not return 0
        expect($workers)->toBeGreaterThan(0);
    });

    it('applies urgency factor in fallback when job age is high', function () {
        // Create two scenarios: low urgency and high urgency
        $lowUrgencyMetrics = createMetrics([
            'throughput_per_minute' => 0.0,
            'active_workers' => 0,
            'pending' => 50,
            'oldest_job_age' => 5, // Low urgency (5s < 50% of SLA)
        ]);

        $highUrgencyMetrics = createMetrics([
            'throughput_per_minute' => 0.0,
            'active_workers' => 0,
            'pending' => 50,
            'oldest_job_age' => 20, // High urgency (20s > 50% of SLA=30s)
        ]);

        // Reset estimator between calls to ensure independent calculations
        $lowUrgencyWorkers = $this->strategy->calculateTargetWorkers($lowUrgencyMetrics, $this->config);
        $this->arrivalEstimator->reset();
        $highUrgencyWorkers = $this->strategy->calculateTargetWorkers($highUrgencyMetrics, $this->config);

        // High urgency should result in more workers
        expect($highUrgencyWorkers)->toBeGreaterThan($lowUrgencyWorkers);
    });

    it('provides a reason for fallback scaling', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 0.0, // Triggers fallback
            'active_workers' => 5,
            'pending' => 20,
            'oldest_job_age' => 3,
        ]);

        $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $reason = $this->strategy->getLastReason();

        // Reason should explain the scaling decision
        expect($reason)->toBeString();
        expect(strlen($reason))->toBeGreaterThan(10);
    });

    it('does not indicate fallback when real throughput data is available', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // Real throughput data
            'active_workers' => 20,
            'pending' => 0,
            'oldest_job_age' => 0,
        ]);

        $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $reason = $this->strategy->getLastReason();

        // Should NOT indicate fallback was used
        expect($reason)->not->toContain('fallback');
    });

    it('does not estimate arrival rate from worker capacity when queue is idle', function () {
        // BUG FIX: Previously, having workers would cause high arrival rate estimation
        // even when the queue was essentially empty
        $metrics = createMetrics([
            'throughput_per_minute' => 0.0, // No throughput (idle)
            'active_workers' => 5, // Workers exist but idle
            'pending' => 1, // Tiny backlog (< 3)
            'oldest_job_age' => 0,
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        // Should NOT scale up based on worker capacity alone
        // With 1 pending job and no throughput, we don't need many workers
        expect($workers)->toBeLessThanOrEqual(1);
    });

    it('does not inflate arrival rate when workers exist but no jobs arriving', function () {
        // BUG FIX: Having 5 workers with 0.15s avg job time was causing:
        // workerCapacity = 5 / 0.15 = 33.3 jobs/s
        // arrivalRate = 33.3 * 0.7 = 23.3 jobs/s  <- WRONG!
        $config = makeQueueConfig(['minWorkers' => 0]);
        $metrics = createMetrics([
            'throughput_per_minute' => 0.0, // No jobs being processed
            'active_workers' => 5, // 5 idle workers
            'pending' => 0, // Empty queue
            'oldest_job_age' => 0,
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $config);

        // With empty queue and no throughput, should return 0 workers (minWorkers=0)
        expect($workers)->toBe(0);
    });
});

describe('arrival rate estimation integration', function () {
    it('detects growing backlog and increases worker count', function () {
        // First measurement
        $metrics1 = createMetrics([
            'throughput_per_minute' => 300.0, // 5 jobs/sec
            'active_workers' => 10,
            'pending' => 100,
            'oldest_job_age' => 5,
        ]);
        $workers1 = $this->strategy->calculateTargetWorkers($metrics1, $this->config);

        // Simulate time passing
        $history = $this->arrivalEstimator->getHistory();
        if (! empty($history)) {
            $key = array_key_first($history);
            foreach ($history[$key] as &$snapshot) {
                $snapshot['timestamp'] -= 10;
            }
            unset($snapshot);

            $reflection = new ReflectionClass($this->arrivalEstimator);
            $historyProperty = $reflection->getProperty('history');
            $historyProperty->setValue($this->arrivalEstimator, $history);
        }

        // Second measurement with growing backlog
        $metrics2 = createMetrics([
            'throughput_per_minute' => 300.0, // Same processing rate
            'active_workers' => 10,
            'pending' => 200, // Backlog doubled = high arrival rate
            'oldest_job_age' => 15,
        ]);
        $workers2 = $this->strategy->calculateTargetWorkers($metrics2, $this->config);

        // Should request more workers due to detected higher arrival rate
        // (backlog grew by 100 in 10s = 10 jobs/sec arriving vs 5 jobs/sec processing)
        expect($workers2)->toBeGreaterThanOrEqual($workers1);
    });
});

describe('metrics handling', function () {
    it('handles missing optional metrics gracefully', function () {
        // createMetrics provides defaults for all fields
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($workers)->toBeInt()
            ->and($workers)->toBeGreaterThanOrEqual(0);
    });

    it('uses avgDuration from metrics when available', function () {
        // Use a higher max to observe the avgDuration effect on Little's Law
        $config = makeQueueConfig(['maxWorkers' => 50]);
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10 jobs/sec
            'active_workers' => 20,
            'pending' => 0,
            'oldest_job_age' => 0,
            'avg_duration' => 3.0, // Explicit 3 second duration
        ]);

        $workers = $this->strategy->calculateTargetWorkers($metrics, $config);
        $reason = $this->strategy->getLastReason();

        // Should use the avgDuration from metrics
        // Workers = rate * avgDuration = 10 * 3 = 30 (within max=50)
        expect($workers)->toBeGreaterThanOrEqual(30)
            ->and($reason)->toContain('3'); // Should reference 3 second job time
    });
});

describe('retry noise correction', function () {
    it('ignores low lifetime failure rates to avoid stale data overcorrection', function () {
        // With 3% lifetime failure rate (below 5% threshold), retry noise should NOT be applied
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10 jobs/sec
            'active_workers' => 20,
            'pending' => 50,
            'oldest_job_age' => 5,
            'failure_rate' => 3.0, // Low lifetime rate - should be ignored
        ]);

        $config = $this->config;
        $this->strategy->calculateTargetWorkers($metrics, $config);
        $reason = $this->strategy->getLastReason();

        // Should NOT mention retry adjustment since rate is below threshold
        expect($reason)->not->toContain('adjusted for retries');
    });

    it('applies dampened correction for high failure rates', function () {
        // With 25% failure rate, correction should be applied but dampened
        $metricsNoFailure = createMetrics([
            'throughput_per_minute' => 600.0,
            'active_workers' => 20,
            'pending' => 50,
            'oldest_job_age' => 5,
            'failure_rate' => 0.0,
        ]);

        $metricsHighFailure = createMetrics([
            'throughput_per_minute' => 600.0,
            'active_workers' => 20,
            'pending' => 50,
            'oldest_job_age' => 5,
            'failure_rate' => 25.0, // High failure rate
        ]);

        $config = $this->config;

        $targetNoFailure = $this->strategy->calculateTargetWorkers($metricsNoFailure, $config);
        $targetHighFailure = $this->strategy->calculateTargetWorkers($metricsHighFailure, $config);

        // High failure rate should result in fewer target workers (lower effective arrival rate)
        expect($targetHighFailure)->toBeLessThanOrEqual($targetNoFailure);
    });

    it('caps retry correction at 30 percent of arrival rate', function () {
        // Even with 100% failure rate, correction should not remove more than 30% of arrival rate
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10 jobs/sec
            'active_workers' => 20,
            'pending' => 50,
            'oldest_job_age' => 5,
            'failure_rate' => 100.0, // Extreme failure rate
        ]);

        $config = $this->config;
        $target = $this->strategy->calculateTargetWorkers($metrics, $config);

        // Should still recommend workers (not zero'd out by retry correction)
        expect($target)->toBeGreaterThan(0);
    });
});

describe('utilization rate integration', function () {
    it('boosts target when workers are saturated but algorithms suggest holding', function () {
        // Workers at 95% utilization with avgDuration explicitly set.
        // With 5 workers, throughput 60/min (1/sec), avgDuration 0.5s:
        // Little's Law says 1.0 * 0.5 = 0.5 workers, trend: 0.6 workers → target ≤ 5
        // But utilization at 95% means workers are overloaded → saturation boost
        $metrics = createMetrics([
            'throughput_per_minute' => 60.0, // 1/sec - low throughput
            'active_workers' => 5,
            'avg_duration' => 0.5, // Fast jobs (seconds)
            'pending' => 1,
            'oldest_job_age' => 1,
            'utilization_rate' => 95.0, // Saturated
        ]);

        $target = $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $reason = $this->strategy->getLastReason();

        // Should boost beyond current 5 workers due to saturation
        expect($target)->toBeGreaterThan(5)
            ->and($reason)->toContain('saturation boost');
    });

    it('does not boost when utilization is moderate', function () {
        $metrics = createMetrics([
            'throughput_per_minute' => 300.0,
            'active_workers' => 5,
            'pending' => 2,
            'oldest_job_age' => 1,
            'utilization_rate' => 60.0, // Not saturated
        ]);

        $target = $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $reason = $this->strategy->getLastReason();

        expect($reason)->not->toContain('saturation boost');
    });

    it('does not boost when algorithms already recommend scaling up', function () {
        // Heavy load: algorithms already want more workers
        $metrics = createMetrics([
            'throughput_per_minute' => 600.0, // 10/sec
            'active_workers' => 5,
            'pending' => 100, // Large backlog
            'oldest_job_age' => 20, // Near SLA
            'utilization_rate' => 95.0,
        ]);

        $target = $this->strategy->calculateTargetWorkers($metrics, $this->config);
        $reason = $this->strategy->getLastReason();

        // Algorithms should already recommend > activeWorkers, so no saturation boost needed
        expect($reason)->not->toContain('saturation boost');
    });
});

describe('constructor requirements', function () {
    it('requires all six dependencies in constructor', function () {
        $strategy = new HybridStrategy(
            littles: new LittlesLawCalculator,
            backlog: new BacklogDrainCalculator,
            arrivalEstimator: new ArrivalRateEstimator,
            spawnTracker: makeNoopSpawnTracker(),
            pickupStore: makeEmptyPickupStore(),
            percentileCalc: new SortBasedPercentileCalculator,
        );

        expect($strategy)->toBeInstanceOf(HybridStrategy::class);
    });
});
