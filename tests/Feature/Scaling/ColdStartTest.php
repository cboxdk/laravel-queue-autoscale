<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryFailureWindowStore;

/**
 * A queue holding work with nothing draining it needs a worker now.
 *
 * Both of the engine's calculations are rate calculations, and both answer zero
 * for a small backlog on an idle queue: Little's Law sees no arrival rate, and
 * the backlog drain deliberately waits until the oldest job has spent
 * breach_threshold of its SLA. That patience is right when workers are already
 * running and wrong when none are — nothing is absorbing the backlog, and the
 * only thing happening is the clock running down.
 *
 * Measured before the fix, one job arriving at a queue sitting at zero: 15
 * seconds against a 30-second SLA, 60 against 120, and not within a hundred
 * seconds at 300. Always half the SLA, whatever the evaluation interval —
 * dropping the interval to one second still cost fourteen. Three jobs got a
 * worker on the next cycle; the difference was the threshold, not the work.
 *
 * It matters more since a queue nobody named no longer carries a worker floor:
 * scaling to zero is only safe if coming back is quick.
 */
function abundantCapacity(): CapacityCalculator
{
    return new class extends CapacityCalculator
    {
        public function calculateMaxWorkers(int $currentWorkers, ResourceEstimate $estimate): CapacityCalculationResult
        {
            return new CapacityCalculationResult(1000, 1000, PHP_INT_MAX, 1000, LimitingFactor::Cpu);
        }
    };
}

function exhaustedCapacity(): CapacityCalculator
{
    return new class extends CapacityCalculator
    {
        public function calculateMaxWorkers(int $currentWorkers, ResourceEstimate $estimate): CapacityCalculationResult
        {
            return new CapacityCalculationResult(0, 0, PHP_INT_MAX, 0, LimitingFactor::Cpu);
        }
    };
}

function coldStartEngine(?CapacityCalculator $capacity = null, ?FailureFuse $fuse = null): ScalingEngine
{
    return new ScalingEngine(
        app(HybridStrategy::class),
        $capacity ?? abundantCapacity(),
        new ResourceEstimateResolver,
        $fuse ?? new FailureFuse(new InMemoryFailureWindowStore),
    );
}

function coldStartConfig(array $rule): QueueConfiguration
{
    config()->set('queue-autoscale.queues', ['exports' => $rule]);

    return QueueConfiguration::fromConfig('redis', 'exports');
}

function coldStartMetrics(int $pending, int $activeWorkers)
{
    return createMetrics([
        'connection' => 'redis',
        'queue' => 'exports',
        'pending' => $pending,
        'oldest_job_age' => 1,
        'active_workers' => $activeWorkers,
        'avg_duration' => 1000.0,
    ]);
}

beforeEach(function (): void {
    config()->set('queue.default', 'redis');
});

test('a single job on an idle queue asks for a worker immediately', function (int $slaTarget): void {
    // Not after half the SLA. The only delay a cold start should carry is the
    // evaluation interval.
    $decision = coldStartEngine()->evaluate(
        coldStartMetrics(1, 0),
        coldStartConfig(['workers' => ['min' => 0, 'max' => 20], 'sla' => ['target_seconds' => $slaTarget]]),
        0,
    );

    expect($decision->targetWorkers)->toBe(1);
})->with([
    'a five second target' => [5],
    'a thirty second target' => [30],
    'a five minute target' => [300],
]);

test('an idle queue with no work still gets nothing', function (): void {
    // The withdrawn floor stays withdrawn. This is a response to WORK, not a
    // standing promise — the distinction that keeps a queue-per-tenant
    // application from holding one permanent worker per name ever seen.
    expect(coldStartEngine()->evaluate(
        coldStartMetrics(0, 0),
        coldStartConfig(['workers' => ['min' => 0, 'max' => 20]]),
        0,
    )->targetWorkers)->toBe(0);
});

test('a queue already being served is left to the rate calculations', function (): void {
    expect(coldStartEngine()->evaluate(
        coldStartMetrics(1, 3),
        coldStartConfig(['workers' => ['min' => 0, 'max' => 20]]),
        3,
    )->targetWorkers)->toBe(0);
});

test('a host with no capacity still spawns nothing', function (): void {
    // Stated as a need, not a floor, so every clamp downstream still applies.
    // The floor this replaces was applied AFTER the capacity clamp, which is
    // how a queue-per-tenant application could demand more workers than the
    // host could carry.
    expect(coldStartEngine(exhaustedCapacity())->evaluate(
        coldStartMetrics(1, 0),
        coldStartConfig(['workers' => ['min' => 0, 'max' => 20]]),
        0,
    )->targetWorkers)->toBe(0);
});

test('a queue capped at zero workers stays at zero', function (): void {
    expect(coldStartEngine()->evaluate(
        coldStartMetrics(1, 0),
        coldStartConfig(['workers' => ['min' => 0, 'max' => 0]]),
        0,
    )->targetWorkers)->toBe(0);
});

test('a queue whose fuse has tripped is not woken by its own backlog', function (): void {
    // The most important of these. Failing jobs pile up exactly like load, so a
    // rule reading "backlog means spawn" would fight the fuse and keep a worker
    // hammering a dependency that is already down.
    $store = new InMemoryFailureWindowStore;
    $store->seedState('open', microtime(true), queue: 'exports');

    $decision = coldStartEngine(null, new FailureFuse($store))->evaluate(
        coldStartMetrics(1, 0),
        coldStartConfig([
            'workers' => ['min' => 0, 'max' => 20],
            'fuse' => ['enabled' => true, 'min_samples' => 20, 'failure_threshold_percent' => 50.0],
        ]),
        0,
    );

    expect($decision->targetWorkers)->toBe(0);
});
