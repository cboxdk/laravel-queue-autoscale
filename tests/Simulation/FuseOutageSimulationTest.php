<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Events\FuseProbing;
use Cbox\LaravelQueueAutoscale\Events\FuseRecovered;
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;
use Cbox\LaravelQueueAutoscale\Fuse\CacheFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Illuminate\Support\Facades\Event;

/**
 * Failure Fuse Outage Simulation
 *
 * Drives a complete downstream incident through the REAL stack — the cache
 * backed window store, the real state machine, and the real ScalingEngine —
 * rather than the in-memory double the unit specs use. The point is to catch
 * the failures that only appear when the pieces are wired together:
 *
 *  1. Fuse state genuinely round-trips through the cache between cycles.
 *  2. Bucket rollover happens mid-incident, because the simulated clock
 *     advances past window boundaries. A window that lost its evidence at a
 *     boundary would let the queue scale back up mid-outage.
 *  3. The window reset on each transition really does clear counters written
 *     by earlier phases, so the probe is judged on probe traffic alone.
 *
 * ## Timeline
 *
 * Every tick is one manager evaluation interval (5 s). Workers record job
 * outcomes into the store exactly as JobOutcomeRecorder does in production;
 * the health of those outcomes is what the phases below vary.
 *
 *   ticks   0-11   healthy → autoscaler free to scale on backlog
 *   ticks  12-59   outage  → fuse trips, holds, probes, re-trips
 *   ticks 60-119   healthy → probe succeeds, normal scaling resumes
 *
 * Backlog is held high throughout: the whole question is whether the fuse
 * overrides a scale-up signal that never goes away on its own.
 *
 * ## Detection latency is bounded by two windows, not one
 *
 * The fuse cannot trip the instant a dependency dies. Its window sums the
 * current and previous bucket, so the healthy traffic recorded before the
 * outage stays in view for up to 2 x window_seconds and dilutes the failure
 * rate below the threshold until it ages out. That is the deliberate cost of
 * not losing evidence at a bucket boundary (see CacheFailureWindowStore), and
 * it is why this simulation asserts a bounded trip time rather than an
 * immediate one. Operators tuning for faster detection shorten the window;
 * they cannot tune the ratio away.
 */
const TICK_SECONDS = 5;

const FUSE_WINDOW_SECONDS = 60;

const FUSE_COOLDOWN_SECONDS = 60;

const JOBS_PER_TICK = 40;

function buildFuseSimulation(float &$clock): array
{
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

    $tick = static function () use (&$clock): float {
        return $clock;
    };

    $store = new CacheFailureWindowStore($tick);

    $engine = new ScalingEngine(
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
        new FailureFuse($store, $tick),
    );

    return [$store, $engine];
}

test('a downstream outage is ridden out and recovered from without manual intervention', function (): void {
    Event::fake([FuseTripped::class, FuseProbing::class, FuseRecovered::class]);

    $clock = 1_700_000_000.0;
    [$store, $engine] = buildFuseSimulation($clock);

    $config = makeQueueConfig([
        'minWorkers' => 2,
        'maxWorkers' => 25,
        'fuse' => [
            'minSamples' => 20,
            'failureThresholdPercent' => 50.0,
            'windowSeconds' => FUSE_WINDOW_SECONDS,
            'cooldownSeconds' => FUSE_COOLDOWN_SECONDS,
        ],
    ]);

    $metrics = createMetrics([
        'pending' => 4000,
        'oldest_job_age' => 90,
        'throughput_per_minute' => 500.0,
        'avg_duration' => 1000.0,
        'active_workers' => 5,
    ]);

    $workers = 5;
    $targets = [];
    $heldByFuse = [];

    foreach (range(0, 119) as $tick) {
        $failing = $tick >= 12 && $tick < 60;

        // Workers process jobs before the manager evaluates, exactly as they
        // do in production: the manager always decides on the previous
        // interval's outcomes.
        //
        // A fixed volume per tick, deliberately not scaled by worker count.
        // Tying it to $workers would feed the wall-clock-derived arrival rate
        // estimate back into how fast the fuse accumulates samples, making
        // the tick at which it trips depend on how quickly the machine ran
        // the loop.
        foreach (range(1, JOBS_PER_TICK) as $ignored) {
            $store->recordOutcome('redis', 'default', failed: $failing, windowSeconds: FUSE_WINDOW_SECONDS);
        }

        $decision = $engine->evaluate($metrics, $config, currentWorkers: $workers);
        $workers = $decision->targetWorkers;
        $targets[$tick] = $workers;
        $heldByFuse[$tick] = $decision->capacity?->limitingFactor === LimitingFactor::Fuse;

        $clock += TICK_SECONDS;
    }

    // Phase 1 — healthy: the fuse never interferes.
    //
    // Asserted on the fuse's own limiting factor rather than on worker
    // counts. CapacityCalculator reads real host CPU and memory, so an
    // absolute count can move for reasons unrelated to the fuse — which is
    // precisely the confounder this simulation must not be sensitive to.
    expect(array_slice($heldByFuse, 0, 12, preserve_keys: true))->each->toBeFalse();

    // Phase 2 — the fuse trips, and does so within the two windows the
    // two-bucket sum needs to flush the pre-outage evidence.
    $trippedAt = null;

    foreach ($heldByFuse as $tick => $held) {
        if ($tick >= 12 && $held) {
            $trippedAt = $tick;
            break;
        }
    }

    $windowsToDetect = (FUSE_WINDOW_SECONDS * 2) / TICK_SECONDS;

    expect($trippedAt)->not->toBeNull()
        ->and($trippedAt)->toBeLessThanOrEqual(12 + $windowsToDetect);

    // Phase 3 — held at the floor for the rest of the outage. The probe fires
    // partway through, finds the dependency still broken and re-trips, so the
    // queue never climbs back up while jobs are still failing.
    $duringOutage = array_slice($targets, $trippedAt, 60 - $trippedAt, preserve_keys: true);

    expect(max($duringOutage))->toBe(2);

    // Phase 4 — recovery: the fuse lets go. Asserted over the whole recovery
    // window rather than at one tick, because the fuse closes only after the
    // probe has gathered min_samples of healthy traffic.
    $heldAfterRecovery = array_slice($heldByFuse, 60, null, preserve_keys: true);

    expect($heldAfterRecovery)->toContain(false);
    expect(array_slice($heldByFuse, 110, null, preserve_keys: true))->each->toBeFalse();

    Event::assertDispatched(FuseTripped::class);
    Event::assertDispatched(FuseProbing::class);
    Event::assertDispatched(FuseRecovered::class);
});

test('the fuse never trips during a long healthy run', function (): void {
    // Guards the opposite failure: a fuse that trips on ordinary traffic
    // would be worse than no fuse at all. A 5% background failure rate is
    // normal for most queues and must stay well clear of the threshold.
    Event::fake([FuseTripped::class]);

    $clock = 1_700_000_000.0;
    [$store, $engine] = buildFuseSimulation($clock);

    $config = makeQueueConfig([
        'minWorkers' => 2,
        'maxWorkers' => 25,
        'fuse' => ['minSamples' => 20, 'failureThresholdPercent' => 50.0, 'windowSeconds' => FUSE_WINDOW_SECONDS],
    ]);

    $metrics = createMetrics([
        'pending' => 4000,
        'oldest_job_age' => 90,
        'throughput_per_minute' => 500.0,
        'avg_duration' => 1000.0,
        'active_workers' => 5,
    ]);

    $workers = 5;
    $limiters = [];

    foreach (range(0, 119) as $tick) {
        foreach (range(1, 100) as $job) {
            $store->recordOutcome('redis', 'default', failed: $job % 20 === 0, windowSeconds: FUSE_WINDOW_SECONDS);
        }

        $decision = $engine->evaluate($metrics, $config, currentWorkers: $workers);
        $workers = $decision->targetWorkers;
        $limiters[] = $decision->capacity?->limitingFactor;
        $clock += TICK_SECONDS;
    }

    // Asserted on the fuse's own signal rather than on worker counts: the
    // exact count per tick depends on wall-clock-derived arrival rate, but
    // "the fuse never constrained this queue" is exact.
    expect($limiters)->not->toContain(LimitingFactor::Fuse);
    Event::assertNotDispatched(FuseTripped::class);
});

test('a brief failure spike does not trip a queue tuned to tolerate it', function (): void {
    // One bad interval inside an otherwise healthy window: the two-bucket sum
    // keeps enough healthy evidence in view that the rate stays under the
    // threshold. This is the behaviour that stops deploy blips from stalling
    // a queue for a full cooldown.
    Event::fake([FuseTripped::class]);

    $clock = 1_700_000_000.0;
    [$store, $engine] = buildFuseSimulation($clock);

    $config = makeQueueConfig([
        'minWorkers' => 2,
        'maxWorkers' => 25,
        'fuse' => ['minSamples' => 20, 'failureThresholdPercent' => 50.0, 'windowSeconds' => FUSE_WINDOW_SECONDS],
    ]);

    $metrics = createMetrics([
        'pending' => 4000,
        'oldest_job_age' => 90,
        'throughput_per_minute' => 500.0,
        'avg_duration' => 1000.0,
        'active_workers' => 5,
    ]);

    $workers = 5;
    $limiters = [];

    foreach (range(0, 23) as $tick) {
        // A single failing tick out of twenty-four.
        $failing = $tick === 10;

        foreach (range(1, 100) as $ignored) {
            $store->recordOutcome('redis', 'default', failed: $failing, windowSeconds: FUSE_WINDOW_SECONDS);
        }

        $decision = $engine->evaluate($metrics, $config, currentWorkers: $workers);
        $workers = $decision->targetWorkers;
        $limiters[] = $decision->capacity?->limitingFactor;
        $clock += TICK_SECONDS;
    }

    expect($limiters)->not->toContain(LimitingFactor::Fuse);
    Event::assertNotDispatched(FuseTripped::class);
});
