<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryFailureWindowStore;

beforeEach(function (): void {
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

    $this->store = new InMemoryFailureWindowStore;

    $this->engine = new ScalingEngine(
        new HybridStrategy(
            littles: new LittlesLawCalculator,
            backlog: new BacklogDrainCalculator,
            arrivalEstimator: new ArrivalRateEstimator,
            spawnTracker: $spawnTracker,
            pickupStore: $pickupStore,
            percentileCalc: new SortBasedPercentileCalculator,
        ),
        new CapacityCalculator,
        new ResourceEstimateResolver,
        new FailureFuse($this->store),
    );

    $this->config = makeQueueConfig([
        'minWorkers' => 2,
        'maxWorkers' => 20,
        'fuse' => ['minSamples' => 20, 'failureThresholdPercent' => 50.0, 'cooldownSeconds' => 60],
    ]);

    // A backlog that any strategy answers by scaling up hard.
    $this->overloadedMetrics = createMetrics([
        'pending' => 5000,
        'oldest_job_age' => 120,
        'throughput_per_minute' => 600.0,
        'avg_duration' => 1000.0,
        'active_workers' => 2,
    ]);
});

/**
 * Assertions about "scaling is not held back" check the fuse's own signal
 * rather than an absolute worker count. CapacityCalculator reads real host
 * CPU and memory, so on a loaded machine the target can legitimately sit at
 * the floor for reasons that have nothing to do with the fuse — which is
 * exactly the confusion these specs exist to rule out.
 */
function fuseConstrained(ScalingDecision $decision): bool
{
    return $decision->capacity?->limitingFactor === LimitingFactor::Fuse
        || str_contains($decision->reason, 'fuse');
}

test('scales up normally while the fuse is closed', function (): void {
    $decision = $this->engine->evaluate($this->overloadedMetrics, $this->config, currentWorkers: 2);

    expect(fuseConstrained($decision))->toBeFalse();
});

test('holds an overloaded queue at workers.min once the fuse trips', function (): void {
    // The backlog is real, but it is caused by a dependency that is failing —
    // adding workers would only increase pressure on it.
    $this->store->seedWindow(total: 200, failures: 180);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $this->config, currentWorkers: 8);

    expect($decision->targetWorkers)->toBe(2);
    expect($decision->action())->toBe('scale_down');
    expect($decision->reason)->toContain('fuse OPEN');
    expect($decision->reason)->toContain('90.0% failure rate');
    expect($decision->capacity?->limitingFactor)->toBe(LimitingFactor::Fuse);
});

test('keeps holding while the fuse stays open on later cycles', function (): void {
    $this->store->seedState('open', microtime(true));

    $decision = $this->engine->evaluate($this->overloadedMetrics, $this->config, currentWorkers: 8);

    expect($decision->targetWorkers)->toBe(2);
    expect($decision->capacity?->limitingFactor)->toBe(LimitingFactor::Fuse);
});

test('allows a single-worker probe when the cooldown expires', function (): void {
    $config = makeQueueConfig(['minWorkers' => 0, 'maxWorkers' => 20]);
    $this->store->seedState('open', microtime(true) - 61.0);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $config, currentWorkers: 0);

    // Capped at the probe level rather than at the backlog's demand. The
    // exact floor-of-one guarantee is pinned in FuseVerdictTest; here the
    // claim is only that the fuse, not capacity, set the ceiling.
    expect($decision->targetWorkers)->toBeLessThanOrEqual(1)
        ->and($decision->capacity?->limitingFactor)->toBe(LimitingFactor::Fuse)
        ->and($decision->reason)->toContain('fuse HALF-OPEN');
});

test('resumes normal scaling after the fuse closes', function (): void {
    $this->store->seedState('half_open', microtime(true));
    $this->store->seedWindow(total: 50, failures: 0);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $this->config, currentWorkers: 1);

    expect(fuseConstrained($decision))->toBeFalse();
});

test('caps cluster-wide demand too so the leader stops recommending capacity', function (): void {
    $this->store->seedState('open', microtime(true));

    $demand = $this->engine->evaluateDemand($this->overloadedMetrics, $this->config);

    expect($demand)->toBe(2);
});

test('leaves demand untouched while the fuse is closed', function (): void {
    $demand = $this->engine->evaluateDemand($this->overloadedMetrics, $this->config);

    expect($demand)->toBeGreaterThan(2);
});

test('cuts straight to the minimum instead of stepping down through hysteresis', function (): void {
    // TargetSmoother normally limits scale-down to one worker per cycle to
    // stop oscillation at steady state. An outage is not oscillation, and
    // walking 20 workers down one per cycle would keep hammering the
    // downstream for twenty evaluation intervals.
    $this->store->seedState('open', microtime(true));

    $decision = $this->engine->evaluate($this->overloadedMetrics, $this->config, currentWorkers: 20);

    expect($decision->targetWorkers)->toBe(2)
        ->and($decision->workersToRemove())->toBe(18);
});

test('never hands probe workers to a queue with no work', function (): void {
    // The fuse imposes a ceiling, not a target. An idle queue stays idle and
    // simply waits in half-open until real work shows up to probe with.
    $idle = createMetrics([
        'pending' => 0,
        'oldest_job_age' => 0,
        'throughput_per_minute' => 0.0,
        'active_workers' => 0,
    ]);
    $config = makeQueueConfig(['minWorkers' => 0, 'maxWorkers' => 20]);
    $this->store->seedState('half_open', microtime(true));

    $decision = $this->engine->evaluate($idle, $config, currentWorkers: 0);

    expect($decision->targetWorkers)->toBe(0);
});

test('respects a configured floor above one when probing', function (): void {
    // workers.min is the operator's decision; the probe never undercuts it.
    $config = makeQueueConfig(['minWorkers' => 4, 'maxWorkers' => 20]);
    $this->store->seedState('open', microtime(true) - 61.0);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $config, currentWorkers: 15);

    expect($decision->targetWorkers)->toBe(4);
});

test('holds at the floor even though the floor keeps pressure on the dependency', function (): void {
    // Documenting the trade-off rather than hiding it: a queue with min=6
    // keeps six workers running against a failing service, because dropping
    // below the configured floor is not the fuse's call to make.
    $config = makeQueueConfig(['minWorkers' => 6, 'maxWorkers' => 20]);
    $this->store->seedWindow(total: 100, failures: 95);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $config, currentWorkers: 18);

    expect($decision->targetWorkers)->toBe(6);
});

test('survives a queue configured to never run workers', function (): void {
    $config = makeQueueConfig(['minWorkers' => 0, 'maxWorkers' => 0]);
    $this->store->seedState('open', microtime(true) - 61.0);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $config, currentWorkers: 0);

    expect($decision->targetWorkers)->toBe(0);
});

test('reports the fuse as the limiting factor ahead of capacity', function (): void {
    // Both constraints bind at once during an outage on a busy host; the
    // fuse is the actionable one, so it must not be masked by "cpu".
    $this->store->seedState('open', microtime(true));

    $decision = $this->engine->evaluate($this->overloadedMetrics, $this->config, currentWorkers: 8, totalPoolWorkers: 500);

    expect($decision->capacity?->limitingFactor)->toBe(LimitingFactor::Fuse)
        ->and($decision->targetWorkers)->toBe(2);
});

test('keeps the strategy explanation alongside the fuse note', function (): void {
    $this->store->seedWindow(total: 100, failures: 95);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $this->config, currentWorkers: 8);

    expect($decision->reason)->toStartWith('fuse OPEN')
        ->and($decision->reason)->toContain('backlog=5000');
});

test('a queue that opted out of the fuse scales through a failure storm', function (): void {
    $config = makeQueueConfig([
        'minWorkers' => 2,
        'maxWorkers' => 20,
        'fuse' => ['enabled' => false],
    ]);
    $this->store->seedWindow(total: 200, failures: 200);

    $decision = $this->engine->evaluate($this->overloadedMetrics, $config, currentWorkers: 2);

    expect(fuseConstrained($decision))->toBeFalse();
});
