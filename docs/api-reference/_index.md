---
title: "API Reference"
description: "Contracts, value objects, events, and shipped implementations for Queue Autoscale for Laravel v2"
weight: 70
---

# API Reference

The public surface of Queue Autoscale for Laravel. For conceptual guidance see [Basic Usage](../basic-usage/_index.md); for the algorithms themselves see [Algorithms](../algorithms/_index.md). This page is strictly about types and signatures — your editor's go-to-definition will take you the rest of the way.

## Contracts

Every core algorithm is replaceable via `$this->app->bind(Contract::class, YourImpl::class)` in a service provider.

### `ScalingStrategyContract`

Decides how many workers a queue needs. `HybridStrategy` is the shipped default.

```php
namespace Cbox\LaravelQueueAutoscale\Contracts;

interface ScalingStrategyContract
{
    public function calculateTargetWorkers(
        QueueMetricsData $metrics,
        QueueConfiguration $config,
    ): int;

    public function getLastReason(): string;        // human-readable
    public function getLastPrediction(): ?float;    // predicted pickup seconds, null if unavailable
}
```

**Shipped implementations** (`src/Scaling/Strategies/`):
- `HybridStrategy` — default. Combines Little's Law, arrival-rate forecasting, and backlog-drain calculators.
- `BacklogOnlyStrategy` — uses only backlog-drain, no forecasting.
- `ConservativeStrategy` — damped version of hybrid for stable workloads.
- `SimpleRateStrategy` — pure Little's Law, no prediction.

### `ScalingPolicy`

Hook into scaling decisions. Policies run in the order declared in `queue-autoscale.policies`. Return a modified `ScalingDecision` from `beforeScaling()` to alter the outcome; return `null` to pass through.

```php
namespace Cbox\LaravelQueueAutoscale\Contracts;

interface ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision;
    public function afterScaling(ScalingDecision $decision): void;
}
```

**Shipped implementations** (`src/Policies/`):
- `ConservativeScaleDownPolicy` — limits scale-down to one worker per cycle.
- `AggressiveScaleDownPolicy` — allows rapid scale-down.
- `NoScaleDownPolicy` — prevents all scale-down.
- `BreachNotificationPolicy` — logs SLA breach risks with built-in rate-limiting.

### `ProfileContract`

A profile resolves to a complete per-queue config. Implement this to ship your own reusable profile.

```php
namespace Cbox\LaravelQueueAutoscale\Contracts;

interface ProfileContract
{
    /** @return array{sla: array, forecast: array, workers: array, spawn_compensation: array} */
    public function resolve(): array;
}
```

**Shipped profiles** (`src/Configuration/Profiles/`): `CriticalProfile`, `HighVolumeProfile`, `BalancedProfile`, `BurstyProfile`, `BackgroundProfile`, `ExclusiveProfile`. See [Workload Profiles](../basic-usage/workload-profiles.md) for what each one sets.

### `ForecasterContract`

Predicts future arrival rate from a time-stamped history.

```php
interface ForecasterContract
{
    /** @param list<array{timestamp: float, rate: float}> $history */
    public function forecast(array $history, int $horizonSeconds): ForecastResult;
}
```

Shipped: `LinearRegressionForecaster` (with R² confidence).

### `ForecastPolicyContract`

Controls how much the forecaster influences scaling. Paired with a `ForecasterContract` implementation.

```php
interface ForecastPolicyContract
{
    /** Minimum R² for a forecast to be trusted. Returns > 1.0 to effectively disable. */
    public function minRSquared(): float;

    /** Blending weight for forecast in [0.0, 1.0]. */
    public function forecastWeight(): float;
}
```

**Shipped implementations** (`src/Scaling/Forecasting/Policies/`):
- `AggressiveForecastPolicy` — high weight, low R² threshold. Used by `CriticalProfile`, `BurstyProfile`.
- `ModerateForecastPolicy` — default for `BalancedProfile`, `HighVolumeProfile`.
- `HintForecastPolicy` — tiny influence. Used by `BackgroundProfile`.
- `DisabledForecastPolicy` — forecast ignored entirely. Used by `ExclusiveProfile`.

### `PercentileCalculatorContract`

Computes a percentile over pickup-time samples. Swap for e.g. an HdrHistogram-backed implementation if you need more accuracy at scale.

```php
interface PercentileCalculatorContract
{
    /** @param list<float> $values */
    public function compute(array $values, int $percentile, int $minSamples = 20): ?float;
}
```

Shipped: `SortBasedPercentileCalculator` — exact percentile via sort + index. Fine up to a few thousand samples per window.

### `PickupTimeStoreContract`

Stores and retrieves rolling-window pickup-time samples.

```php
interface PickupTimeStoreContract
{
    public function record(
        string $connection,
        string $queue,
        float $timestamp,
        float $pickupSeconds,
    ): void;

    /** @return list<array{timestamp: float, pickup_seconds: float}> */
    public function recentSamples(
        string $connection,
        string $queue,
        int $windowSeconds,
    ): array;
}
```

Shipped: `RedisPickupTimeStore` — lists in Redis with configurable `max_samples_per_queue` trim.

### `SpawnLatencyTrackerContract`

Measures how long a spawned worker takes to pick up its first job. The scaling engine subtracts this latency from the SLA target so it can start scaling *earlier* to compensate.

```php
interface SpawnLatencyTrackerContract
{
    public function recordSpawn(
        string $workerId,
        string $connection,
        string $queue,
        SpawnCompensationConfiguration $config,
    ): void;

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void;

    public function currentLatency(
        string $connection,
        string $queue,
        SpawnCompensationConfiguration $config,
    ): float;
}
```

Shipped: `EmaSpawnLatencyTracker` — Redis-backed exponentially-weighted moving average.

## Configuration Value Objects

All `final readonly`. Live in `src/Configuration/`.

### `QueueConfiguration`

Per-queue resolved configuration. Built by `QueueConfiguration::fromConfig($connection, $queue)`.

```php
final readonly class QueueConfiguration
{
    public function __construct(
        public string $connection,
        public string $queue,
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
        public array $memberQueues = [],  // populated when adapted from a GroupConfiguration
    ) {}

    /** @return list<string> Real queue names to aggregate signals across (group support). */
    public function sampleQueues(): array;
}
```

### `SlaConfiguration`

```php
public function __construct(
    public int $targetSeconds,   // pickup SLA
    public int $percentile,      // 50-99
    public int $windowSeconds,   // rolling window for the percentile
    public int $minSamples,      // below this many samples, fall back to oldest_job_age
) {}
```

### `WorkerConfiguration`

```php
public function __construct(
    public int $min,
    public int $max,
    public int $tries,
    public int $timeoutSeconds,
    public int $sleepSeconds,
    public int $shutdownTimeoutSeconds,
    public bool $scalable = true,   // false = supervised/pinned (ExclusiveProfile)
) {}

public function pinnedCount(): int;   // returns $min; used when scalable=false
```

### `ForecastConfiguration`

```php
public function __construct(
    public string $forecasterClass,       // class-string<ForecasterContract>
    public string $policyClass,           // class-string<ForecastPolicyContract>
    public int $horizonSeconds,           // how far ahead to predict
    public int $historySeconds,           // how much history to feed the forecaster
) {}
```

### `SpawnCompensationConfiguration`

```php
public function __construct(
    public bool $enabled,
    public float $fallbackSeconds,
    public int $minSamples,
    public float $emaAlpha,
) {}
```

### `GroupConfiguration`

Multi-queue priority worker group. See [Queue Topology → Groups](../basic-usage/queue-topology.md#worker-groups).

```php
final readonly class GroupConfiguration
{
    public const MODE_PRIORITY = 'priority';

    public function __construct(
        public string $name,
        public string $connection,
        public array $queues,           // list<string> in priority order
        public string $mode,            // only MODE_PRIORITY supported in v2
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
    ) {}

    public function queueArgument(): string;                    // 'email,sms,push'
    public function toScalingConfiguration(): QueueConfiguration;
    public static function fromConfig(string $name, array $config): self;
    public static function allFromConfig(): array;              // array<string, self>
    public static function assertNoQueueConflicts(array $groups): void;
}
```

## Scaling Decision

Returned by `ScalingEngine::evaluate()` and dispatched in `ScalingDecisionMade` / `SlaBreachPredicted` events.

```php
namespace Cbox\LaravelQueueAutoscale\Scaling;

final readonly class ScalingDecision
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $currentWorkers,
        public int $targetWorkers,
        public string $reason,
        public ?float $predictedPickupTime = null,
        public int $slaTarget = 30,
        public ?CapacityCalculationResult $capacity = null,
        public ?SpawnCompensationConfiguration $spawnCompensation = null,
    ) {}

    public function shouldScaleUp(): bool;
    public function shouldScaleDown(): bool;
    public function shouldHold(): bool;
    public function workersToAdd(): int;
    public function workersToRemove(): int;
    public function action(): string;           // 'scale_up' | 'scale_down' | 'hold'
    public function isSlaBreachRisk(): bool;
}
```

## Events

All live in `Cbox\LaravelQueueAutoscale\Events`. See [Event Handling](../basic-usage/event-handling.md) for listener patterns.

| Event | Fires | Payload |
|---|---|---|
| `ScalingDecisionMade` | Every cycle | `$decision` |
| `SlaBreachPredicted` | Every cycle during risk | `$decision` |
| `SlaBreached` | Once per state transition | `$connection`, `$queue`, `$oldestJobAge`, `$slaTarget`, `$pending`, `$activeWorkers` |
| `SlaRecovered` | Once per state transition | `$connection`, `$queue`, `$currentJobAge`, `$slaTarget`, `$pending`, `$activeWorkers` |
| `WorkersScaled` | Per spawn/terminate action | `$connection`, `$queue`, `$from`, `$to`, `$action`, `$reason` |

## Alerting

### `AlertRateLimiter`

Cache-lock-based cooldown helper. Used internally by `BreachNotificationPolicy` and recommended for custom alert listeners.

```php
namespace Cbox\LaravelQueueAutoscale\Alerting;

final readonly class AlertRateLimiter
{
    public function __construct(public int $cooldownSeconds = 300) {}

    public function allow(string $key): bool;  // true = proceed, false = still in cooldown
}
```

Resolved from the container with cooldown from `queue-autoscale.alerting.cooldown_seconds` (env var: `QUEUE_AUTOSCALE_ALERT_COOLDOWN`).

## Workers

### `WorkerProcess`

A live `queue:work` subprocess wrapped with spawn metadata.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

final class WorkerProcess
{
    public function __construct(
        public readonly Process $process,
        public readonly string $connection,
        public readonly string $queue,         // singular name OR comma-separated for group workers
        public readonly Carbon $spawnedAt,
        public readonly ?string $group = null,
    ) {}

    public function pid(): ?int;
    public function isRunning(): bool;
    public function isDead(): bool;
    public function uptimeSeconds(): int;
    public function matches(string $connection, string $queue): bool;        // false for group workers
    public function matchesGroup(string $connection, string $group): bool;
    public function isGroupWorker(): bool;
}
```

### `WorkerPool`

Collection wrapper over `WorkerProcess` with add/remove/filter helpers. Internal to the manager; useful when writing custom tooling.

## Console Commands

| Command | Purpose |
|---|---|
| `queue:autoscale` | Main daemon. Accepts `--interval=N`, `--replace`, and `-v` / `-vv` / `-vvv` verbosity flags. |
| `queue:autoscale:debug` | Dump queue state and metrics for diagnosis. `--queue=X --connection=Y`. |
| `queue:autoscale:cluster` | Show cluster leader, active managers, host capacity, workload targets, and host scale signal. Add `--json` for machine-readable output. |
| `queue:autoscale:cluster` | Show cluster leader, active managers, host capacity, workload targets, and host scale signal. Add `--json` for machine-readable output. |
| `queue-autoscale:migrate-config` | Translate a v1 config file to v2 shape. `--source` and `--destination` options. |

## Service Provider Bindings

Everything binds in `LaravelQueueAutoscaleServiceProvider`. Override by binding before this provider boots:

```php
// AppServiceProvider::register()
$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract::class,
    \App\Autoscale\MyForecaster::class,
);
```

See [Custom Strategies](../advanced-usage/custom-strategies.md) for writing your own implementations.

## See Also

- [Basic Usage](../basic-usage/_index.md) — Implementation guides
- [Advanced Usage](../advanced-usage/_index.md) — Custom strategies and policies
- [Algorithms](../algorithms/_index.md) — Mathematical foundations
- [Queue Topology](../basic-usage/queue-topology.md) — Per-queue vs. groups vs. exclusive vs. excluded
