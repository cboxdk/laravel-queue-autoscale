---
title: "Custom Strategies"
description: "Write a scaling strategy against the real ScalingStrategyContract, QueueMetricsData and QueueConfiguration types"
weight: 30
---

# Custom Strategies

A scaling strategy answers one question: **how many workers should this queue have right now?**
It is the only pluggable piece that runs *before* the engine — everything after it (system capacity,
`workers.min`/`workers.max`, the failure fuse, policies) constrains whatever number you return.

The package ships four strategies. Replace them when your workload has a signal the shipped
algorithms cannot see: an external schedule, a tenant tier, a business calendar, or a
domain-specific arrival model.

## The contract

```php
namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

interface ScalingStrategyContract
{
    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int;

    public function getLastReason(): string;

    public function getLastPrediction(): ?float;
}
```

`calculateTargetWorkers()` is called once per queue per evaluation cycle. `getLastReason()` and
`getLastPrediction()` are read by `ScalingEngine` immediately afterwards, so both refer to the most
recent call — a strategy is expected to keep that state on the instance. The strategy is resolved as
a container singleton, so the state is shared across every queue; store only what you read back in
the same cycle.

`getLastPrediction()` becomes `ScalingDecision::predictedPickupTime` and drives
`isSlaBreachRisk()`, the `SlaBreachPredicted` event and `BreachNotificationPolicy`. Return `null`
when you genuinely cannot predict a pickup time (that is what `SimpleRateStrategy` does), and `0.0`
when the answer is "no wait at all".

## The metrics you receive

`$metrics` is `Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData` — a `final readonly`
class with a flat property list. There is no nested `depth`, `trend` or `resources` object.

| Property | Type | Meaning |
|---|---|---|
| `connection` | `string` | Queue connection name |
| `queue` | `string` | Queue name |
| `depth` | `int` | Total jobs on the queue |
| `pending` | `int` | Jobs waiting to be picked up — the backlog every shipped strategy uses |
| `scheduled` | `int` | Delayed jobs not yet available |
| `reserved` | `int` | Jobs currently held by a worker |
| `oldestJobAge` | `int` | Seconds since the oldest pending job was queued |
| `ageStatus` | `string` | Metrics-package classification of that age |
| `throughputPerMinute` | `float` | Completed jobs per minute |
| `avgDuration` | `float` | Average job duration **in seconds** (see below) |
| `failureRate` | `float` | Lifetime failure rate as a **percentage** (0–100) |
| `utilizationRate` | `float` | Worker busy percentage (0–100) |
| `activeWorkers` | `int` | Workers the metrics package can see on this queue |
| `driver` | `string` | `redis`, `database`, … |
| `health` | `HealthStats` | `status, score, depth, oldestJobAge, failureRate, utilizationRate` |
| `calculatedAt` | `Carbon` | When the snapshot was computed |

Helper methods: `isEmpty()`, `isBacklogged()`, `hasActiveWorkers()`, `toArray()`.

### avgDuration is seconds, not milliseconds

The metrics package reports `avg_duration_ms`. `AutoscaleManager::mapMetricsFields()` divides by
1000 before constructing the DTO, so by the time a strategy sees it, `avgDuration` is **seconds**.
The cluster path does the same conversion. Every shipped strategy treats it as seconds and guards
the low end:

```php
private function determineJobTime(QueueMetricsData $metrics): float
{
    if ($metrics->avgDuration >= 0.01) {
        return $metrics->avgDuration;
    }

    return AutoscaleConfiguration::fallbackJobTimeSeconds();
}
```

`fallbackJobTimeSeconds()` reads `queue-autoscale.scaling.fallback_job_time_seconds`
(env `QUEUE_AUTOSCALE_FALLBACK_JOB_TIME`, default `2.0`). Copy this guard — a fresh queue reports
`0.0` and dividing by it is the classic way to produce a nonsense target.

### There is no trend or resource data on the metrics object

Forecasting lives in `ArrivalRateEstimator` and the classes under `Scaling/Forecasting/`, not on the
DTO. System CPU and memory are read by `CapacityCalculator` inside the engine, after your strategy
has already returned. If you need either, inject the collaborator yourself.

## The configuration you receive

`$config` is `Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration`, also `readonly`, and
its values are grouped:

```php
$config->connection;              // string
$config->queue;                   // string
$config->workers->min;            // int
$config->workers->max;            // int
$config->workers->tries;          // int
$config->workers->timeoutSeconds; // int
$config->workers->scalable;       // bool — false for pinned queues
$config->sla->targetSeconds;      // int
$config->sla->percentile;         // int — 50|75|90|95|99
$config->sla->windowSeconds;      // int — >= 60
$config->sla->minSamples;         // int
$config->forecast->horizonSeconds;
$config->spawnCompensation->enabled;
$config->fuse->failureThresholdPercent;
$config->sampleQueues();          // list<string> — member queues for a group, else [$queue]
```

There is no `$config->minWorkers`, `$config->maxWorkers`, `$config->maxPickupTimeSeconds` or
`$config->scaleCooldownSeconds`. The scaling cooldown is global and lives in
`queue-autoscale.scaling.cooldown_seconds`; it is enforced by `AutoscaleManager`, not by strategies.

When the config represents a **group**, `$config->queue` is the group name and
`$config->sampleQueues()` returns the real member queues. Use `sampleQueues()` whenever you look up
per-queue signals such as pickup-time samples.

## Registering your strategy

`strategy` is a plain class string. `AutoscaleConfiguration::strategyClass()` reads it with
`config('queue-autoscale.strategy')` and the service provider resolves it from the container.

```php
// config/queue-autoscale.php
'strategy' => \App\Autoscale\Strategies\BusinessCalendarStrategy::class,
```

An array value (`['class' => ..., 'options' => ...]`) breaks boot — there is no options sub-key.
Configure a strategy through constructor injection instead:

```php
// AppServiceProvider::register()
$this->app->bind(\App\Autoscale\Strategies\BusinessCalendarStrategy::class, function ($app): object {
    return new \App\Autoscale\Strategies\BusinessCalendarStrategy(
        littles: $app->make(\Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator::class),
        holidays: config('business.holidays'),
    );
});
```

## Example: a rate-and-backlog strategy

This reuses the shipped calculators rather than reimplementing them.
`LittlesLawCalculator::calculate($arrivalRate, $avgProcessingTime)` is literally
`arrivalRate * avgProcessingTime` (workers = λW) and returns a `float`; the caller rounds.

```php
<?php

declare(strict_types=1);

namespace App\Autoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

class RateAndBacklogStrategy implements ScalingStrategyContract
{
    private string $lastReason = 'No calculation performed yet';

    private ?float $lastPrediction = null;

    public function __construct(
        private readonly LittlesLawCalculator $littles,
    ) {}

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        $avgJobTime = $this->determineJobTime($metrics);
        $arrivalRate = $metrics->throughputPerMinute / 60.0;

        $steadyState = $this->littles->calculate($arrivalRate, $avgJobTime);

        $drain = 0.0;

        if ($metrics->pending > 0) {
            $timeBudget = max($config->sla->targetSeconds - $metrics->oldestJobAge, 1);
            $jobsPerWorker = max($timeBudget / $avgJobTime, 1.0);
            $drain = $metrics->pending / $jobsPerWorker;
        }

        $target = (int) ceil(max($steadyState, $drain));
        $target = max($config->workers->min, min($config->workers->max, $target));

        $this->lastReason = sprintf(
            'rate=%.2f/s x time=%.1fs = %.1f steady; backlog=%d needs %.1f; target=%d',
            $arrivalRate,
            $avgJobTime,
            $steadyState,
            $metrics->pending,
            $drain,
            $target,
        );

        $this->lastPrediction = $metrics->pending > 0 && $target > 0
            ? ($metrics->pending / $target) * $avgJobTime
            : 0.0;

        return $target;
    }

    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    public function getLastPrediction(): ?float
    {
        return $this->lastPrediction;
    }

    private function determineJobTime(QueueMetricsData $metrics): float
    {
        if ($metrics->avgDuration >= 0.01) {
            return $metrics->avgDuration;
        }

        return AutoscaleConfiguration::fallbackJobTimeSeconds();
    }
}
```

## Example: a schedule-aware floor

A common real requirement is "never drop below N during business hours". Do it in a strategy when
the floor should influence the calculation; do it in a
[policy](scaling-policies.md) when it should override a finished decision.

```php
<?php

declare(strict_types=1);

namespace App\Autoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

class BusinessHoursFloorStrategy implements ScalingStrategyContract
{
    private string $lastReason = 'No calculation performed yet';

    private ?float $lastPrediction = null;

    /**
     * @param  array<int, int>  $floorByHour  Hour of day (0-23) => minimum workers
     */
    public function __construct(
        private readonly ScalingStrategyContract $inner,
        private readonly array $floorByHour = [],
    ) {}

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        $base = $this->inner->calculateTargetWorkers($metrics, $config);
        $floor = $this->floorByHour[now()->hour] ?? $config->workers->min;

        $target = max($base, min($floor, $config->workers->max));

        $this->lastReason = $target > $base
            ? sprintf('hour %d floor raised %d to %d (%s)', now()->hour, $base, $target, $this->inner->getLastReason())
            : $this->inner->getLastReason();

        $this->lastPrediction = $this->inner->getLastPrediction();

        return $target;
    }

    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    public function getLastPrediction(): ?float
    {
        return $this->lastPrediction;
    }
}
```

Decorating a shipped strategy like this is usually a better trade than writing one from scratch —
you keep the forecaster, the p95 pickup signal and the spawn-latency compensation.

## The shipped strategies as reference

Read the source before writing your own; all four live in `src/Scaling/Strategies/`.

### SimpleRateStrategy

Little's Law and nothing else: `target = ceil(throughputPerMinute / 60 * avgJobTime)`.
`getLastPrediction()` returns `null` because it never looks at the backlog. The smallest complete
implementation of the contract in the package — read this one first.

### BacklogOnlyStrategy

Delegates entirely to `BacklogDrainCalculator::calculateRequiredWorkers()` with
`AutoscaleConfiguration::breachThreshold()` (`queue-autoscale.scaling.breach_threshold`, default
`0.5`). Ignores arrival rate. `getLastPrediction()` returns
`(backlog / targetWorkers) * avgJobTime`.

### ConservativeStrategy

`max(littlesLaw, backlogDrain)` with two class constants: `BREACH_THRESHOLD = 0.75` (acts at 75% of
SLA instead of the configured threshold) and `SAFETY_BUFFER = 1.25` (adds 25% to the result). Both
are `private const` — to change them, copy the class.

### HybridStrategy (default)

The full pipeline. In order: processing rate from throughput, job time from `avgDuration`, arrival
rate from `ArrivalRateEstimator` (used only when its reported confidence clears
`queue-autoscale.scaling.min_arrival_rate_confidence` — not present in the published config file, so
the `0.5` default applies until you add the key), retry-noise correction when
`failureRate > 5.0`, an effective SLA reduced by measured spawn latency, a p95 pickup-time signal
from the `PickupTimeStore` over `sla.window_seconds`, then
`max(steadyState, backlogDrain)`, a saturation boost when `utilizationRate >= 90.0`, clamping to
`[workers.min, workers.max]`, and finally `TargetSmoother` hysteresis.

Its collaborators are all injected, so you can swap any one of them via a container binding without
replacing the strategy:

```php
public function __construct(
    private readonly LittlesLawCalculator $littles,
    private readonly BacklogDrainCalculator $backlog,
    private readonly ArrivalRateEstimator $arrivalEstimator,
    private readonly SpawnLatencyTrackerContract $spawnTracker,
    private readonly PickupTimeStoreContract $pickupStore,
    private readonly PercentileCalculatorContract $percentileCalc,
    private readonly TargetSmoother $smoother = new TargetSmoother,
) {}
```

## What happens to your number

`ScalingEngine::evaluate()` takes your return value and, in order:

1. Caps it at this queue's share of host capacity
   (`capacityResult->finalMaxWorkers - workers used by other queues`).
2. Clamps to `[workers.min, workers.max]`.
3. Applies the failure-fuse ceiling if the fuse is open.
4. Builds the `ScalingDecision`, attaching `getLastPrediction()` as `predictedPickupTime` and
   `sla.targetSeconds` as `slaTarget`.

Clamping in your strategy is still worth doing — it keeps `getLastReason()` honest — but the engine
is the authority. In cluster mode the leader calls `evaluateDemand()` instead, which applies only
config bounds and the fuse, so your raw demand number is what the cluster fair-share allocator sees.

## Testing

Build the DTOs directly; both are plain readonly objects.

```php
use App\Autoscale\Strategies\RateAndBacklogStrategy;
use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

function autoscaleTestConfig(int $min = 1, int $max = 20): QueueConfiguration
{
    return new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        sla: new SlaConfiguration(
            targetSeconds: 30,
            percentile: 95,
            windowSeconds: 300,
            minSamples: 20,
        ),
        forecast: new ForecastConfiguration(
            forecasterClass: LinearRegressionForecaster::class,
            policyClass: ModerateForecastPolicy::class,
            horizonSeconds: 60,
            historySeconds: 300,
        ),
        spawnCompensation: new SpawnCompensationConfiguration(
            enabled: true,
            fallbackSeconds: 2.0,
            minSamples: 5,
            emaAlpha: 0.2,
        ),
        workers: new WorkerConfiguration(
            min: $min,
            max: $max,
            tries: 3,
            timeoutSeconds: 3600,
            sleepSeconds: 3,
            shutdownTimeoutSeconds: 30,
        ),
    );
}

it('scales up for a backlog', function (): void {
    $strategy = new RateAndBacklogStrategy(new LittlesLawCalculator);

    $metrics = QueueMetricsData::fromArray([
        'connection' => 'redis',
        'queue' => 'default',
        'depth' => 500,
        'pending' => 500,
        'oldest_job_age' => 10,
        'throughput_per_minute' => 120.0,
        'avg_duration' => 0.5,   // seconds
        'active_workers' => 2,
        'driver' => 'redis',
    ]);

    $target = $strategy->calculateTargetWorkers($metrics, autoscaleTestConfig());

    expect($target)->toBeGreaterThan(2)
        ->and($target)->toBeLessThanOrEqual(20)
        ->and($strategy->getLastPrediction())->toBeFloat();
});
```

`QueueMetricsData::fromArray()` defaults every missing key, so a test only has to name the fields it
cares about. Note that its array keys are snake_case (`oldest_job_age`, `throughput_per_minute`,
`avg_duration`) while the properties are camelCase.

To test against the real engine, construct it with its four dependencies — the fourth,
`FailureFuse`, defaults to a null-backed fuse that never trips:

```php
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;

it('respects workers.max through the engine', function (): void {
    $engine = new ScalingEngine(
        new RateAndBacklogStrategy(new LittlesLawCalculator),
        app(CapacityCalculator::class),
        app(ResourceEstimateResolver::class),
    );

    $decision = $engine->evaluate(
        QueueMetricsData::fromArray([
            'connection' => 'redis',
            'queue' => 'default',
            'pending' => 10_000,
            'oldest_job_age' => 25,
            'throughput_per_minute' => 60.0,
            'avg_duration' => 1.0,
        ]),
        autoscaleTestConfig(min: 1, max: 5),
        currentWorkers: 1,
        totalPoolWorkers: 1,
    );

    expect($decision->targetWorkers)->toBeLessThanOrEqual(5);
});
```

`CapacityCalculator` samples real CPU for about a second on a cold cache, so engine-level tests are
slower than strategy-level ones. Prefer testing the calculation in isolation and keep one or two
engine tests for the wiring.

## See Also

- [Policy Execution Internals](scaling-policies.md) - The extension point that runs after the engine
- [How It Works](../basic-usage/how-it-works.md) - The default algorithm end to end
- [Little's Law](../algorithms/littles-law.md) and [Backlog Drain](../algorithms/backlog-drain.md) - The shipped calculators
- [Configuration](../basic-usage/configuration.md) - Every config key the contract reads
