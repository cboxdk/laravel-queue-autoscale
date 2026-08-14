---
title: "API Reference"
description: "Contracts, value objects, events and shipped implementations for Queue Autoscale for Laravel v3"
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

`queue-autoscale.strategy` is a **plain class string**, read by `AutoscaleConfiguration::strategyClass()`. It is not an array and takes no options.

**Shipped implementations** (`src/Scaling/Strategies/`):
- `HybridStrategy` — default. `max(littlesLaw, backlogDrain)`, with the arrival rate supplied by `ArrivalRateEstimator` (forecast-blended), retry-noise correction, a saturation boost and `TargetSmoother` hysteresis.
- `BacklogOnlyStrategy` — backlog-drain only, no arrival-rate term.
- `ConservativeStrategy` — Little's Law + backlog-drain with a class-constant 25% safety buffer and its own 0.75 breach threshold (it does not read `scaling.breach_threshold`).
- `SimpleRateStrategy` — Little's Law only, no backlog term, no prediction.

There is no `PredictiveStrategy`.

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

Policies are loaded by `PolicyExecutor` from `queue-autoscale.policies`, which must contain **class strings** — the list is filtered with `is_string($policy) && class_exists($policy)`, so an instance or closure is silently dropped. Each class is resolved via `app()`. An exception thrown in either hook is caught and logged; scaling continues.

**Shipped implementations** (`src/Policies/`):
- `ConservativeScaleDownPolicy` — caps scale-down at `max(1, (int) ceil($decision->currentWorkers * 0.25))` per cycle: 25% of the current count, minimum 1.
- `AggressiveScaleDownPolicy` — only acts on scale-downs where the queue is idle (`predictedPickupTime` null or `0.0`) **and** `targetWorkers <= 1`, forcing that exact target; otherwise returns `null`. Intended to be listed **after** `ConservativeScaleDownPolicy` so it can override that clamp.
- `NoScaleDownPolicy` — replaces a scale-down with a hold, **except** when `currentWorkers > capacity->finalMaxWorkers`, in which case resource-forced scale-down is allowed through. Takes a `CapacityCalculator` via constructor injection.
- `BreachNotificationPolicy` — `beforeScaling()` always returns `null`; `afterScaling()` logs SLA breach risk (warning) and ≥90% SLA utilisation (notice), each gated by `AlertRateLimiter`.

There is no `ScalingPolicyContract` — the interface is named `ScalingPolicy`.

### `ProfileContract`

A profile resolves to a complete per-queue config. Implement this to ship your own reusable profile.

```php
namespace Cbox\LaravelQueueAutoscale\Contracts;

interface ProfileContract
{
    /**
     * @return array{
     *     sla: array{target_seconds: int, percentile: int, window_seconds: int, min_samples: int},
     *     forecast: array{forecaster: class-string, policy: class-string, horizon_seconds: int, history_seconds: int},
     *     workers: array{min: int, max: int, tries: int, timeout_seconds: int, sleep_seconds: int, shutdown_timeout_seconds: int, scalable?: bool},
     *     spawn_compensation: array{enabled: bool, fallback_seconds: float, min_samples: int, ema_alpha: float},
     *     fuse?: array{enabled: bool, failure_threshold_percent: float, min_samples: int, window_seconds: int, cooldown_seconds: int},
     * }
     */
    public function resolve(): array;
}
```

**Shipped profiles** (`src/Configuration/Profiles/`): `CriticalProfile`, `HighVolumeProfile`, `BalancedProfile`, `BurstyProfile`, `BackgroundProfile`, `ExclusiveProfile`. See [Workload Profiles](../basic-usage/workload-profiles.md) for what each one sets.

Each `resolve()` returns the keys `sla`, `forecast`, `workers`, `spawn_compensation` and `fuse`.

`ProfilePresets` was removed in v3 — `ProfilePresets::balanced()` no longer exists. Reference the profile classes directly.

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
readonly class QueueConfiguration
{
    public function __construct(
        public string $connection,
        public string $queue,
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
        public FuseConfiguration $fuse = new FuseConfiguration(
            enabled: true,
            failureThresholdPercent: 50.0,
            minSamples: 20,
            windowSeconds: 60,
            cooldownSeconds: 60,
        ),
        public array $memberQueues = [],  // populated when adapted from a GroupConfiguration
    ) {}

    /** @return list<string> Real queue names to aggregate signals across (group support). */
    public function sampleQueues(): array;

    public static function fromConfig(string $connection, string $queue): self;
}

`fromConfig()` resolves `queue-autoscale.queues.{queue}` through `resolveProfileOrArray()`: a `ProfileContract` class string, or a literal partial-override array deep-merged over `sla_defaults`. It does **not** understand `['profile' => ..., 'overrides' => [...]]` — that shape belongs to `GroupConfiguration` only.

Access is nested: `$config->workers->min`, `$config->workers->max`, `$config->sla->targetSeconds`. There is no `$config->minWorkers`, `$config->maxWorkers` or `$config->maxPickupTimeSeconds`.
```

### `SlaConfiguration`

```php
public function __construct(
    public int $targetSeconds,   // > 0
    public int $percentile,      // one of 50, 75, 90, 95, 99
    public int $windowSeconds,   // >= 60
    public int $minSamples,      // >= 1; below this many samples, fall back to oldest_job_age
) {}
```

The constructor throws `InvalidConfigurationException` on any violation of those constraints.

### `WorkerConfiguration`

```php
public function __construct(
    public int $min,                  // >= 0
    public int $max,                  // >= $min
    public int $tries,                // >= 1
    public int $timeoutSeconds,       // > 0
    public int $sleepSeconds,
    public int $shutdownTimeoutSeconds,
    public bool $scalable = true,   // false = supervised/pinned (ExclusiveProfile)
) {}

public function pinnedCount(): int;   // returns $min; used when scalable=false
```

Constructor guards throw `InvalidConfigurationException`, including `scalable=false` requiring `min === max` and `min >= 1`.

> Every value here reaches the spawned worker. The global `queue-autoscale.workers` block that used to override them no longer exists.

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

### `FuseConfiguration`

```php
readonly class FuseConfiguration
{
    public function __construct(
        public bool $enabled,
        public float $failureThresholdPercent,  // (0, 100]
        public int $minSamples,                 // >= 1
        public int $windowSeconds,              // >= 1
        public int $cooldownSeconds,
    ) {}

    public static function fromArray(array $config): self;
}
```

See [Failure Fuse](../basic-usage/failure-fuse.md).

### `GroupConfiguration`

Multi-queue priority worker group. See [Queue Topology → Groups](../basic-usage/queue-topology.md#worker-groups).

```php
readonly class GroupConfiguration
{
    public const MODE_PRIORITY = 'priority';

    public function __construct(
        public string $name,
        public string $connection,
        public array $queues,           // array<int, string> in priority order
        public string $mode,            // MODE_PRIORITY is the only supported value
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
        public FuseConfiguration $fuse = new FuseConfiguration(
            enabled: true,
            failureThresholdPercent: 50.0,
            minSamples: 20,
            windowSeconds: 60,
            cooldownSeconds: 60,
        ),
    ) {}

    public function queueArgument(): string;                    // 'email,sms,push'
    public function toScalingConfiguration(): QueueConfiguration;
    public static function fromConfig(string $name, array $config): self;
    public static function allFromConfig(): array;              // array<string, self>
    public static function assertNoQueueConflicts(array $groups): void;
}
```

`fromConfig()` reads `queues`, `connection` (default `'default'`), `mode` (default `'priority'`), `profile` and `overrides`. The `profile` + `overrides` pair is **groups-only**; the per-queue resolver does not understand it.

The constructor throws `InvalidConfigurationException` for an empty queue list, an unsupported mode, a non-scalable profile, or a duplicate queue within the group. `assertNoQueueConflicts()` throws when a queue appears both under `queues` and in a group, or in two groups.

## Scaling Decision

Returned by `ScalingEngine::evaluate()` and dispatched in `ScalingDecisionMade` / `SlaBreachPredicted` events.

```php
namespace Cbox\LaravelQueueAutoscale\Scaling;

readonly class ScalingDecision
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
    public function isSlaBreachRisk(): bool;    // predictedPickupTime > slaTarget
}
```

There is **no** `confidence` property on `ScalingDecision`, or anywhere else in the package.

Do not confuse `ScalingDecision::action()` (`'scale_up' | 'scale_down' | 'hold'`) with `WorkersScaled::$action`, which is always `'up'` or `'down'`.

### `CapacityCalculationResult`

`ScalingDecision::$capacity`, in `Cbox\LaravelQueueAutoscale\Scaling\DTOs`:

```php
readonly class CapacityCalculationResult
{
    public function __construct(
        public int $maxWorkersByCpu,
        public int $maxWorkersByMemory,
        public int $maxWorkersByConfig,
        public int $finalMaxWorkers,
        public string $limitingFactor,
        public array $details = [],
    ) {}

    public function isCpuLimited(): bool;
    public function isMemoryLimited(): bool;
    public function isConfigLimited(): bool;
    public function getSummary(): string;
    public function getFormattedDetails(): array;
}
```

`limitingFactor` values seen on a decision: `cpu`, `memory`, `balanced`, `config`, `strategy`, `fuse`, `system_metrics_unavailable`.

## Events

All live in `Cbox\LaravelQueueAutoscale\Events`. See [Event Handling](../basic-usage/event-handling.md) for listener patterns.

| Event | Fires | Payload |
|---|---|---|
| `ScalingDecisionMade` | Every cycle | `$decision` |
| `SlaBreachPredicted` | Every cycle during risk | `$decision` |
| `SlaBreached` | Once per state transition | `$connection`, `$queue`, `$oldestJobAge`, `$slaTarget`, `$pending`, `$activeWorkers` |
| `SlaRecovered` | Once per state transition | `$connection`, `$queue`, `$currentJobAge`, `$slaTarget`, `$pending`, `$activeWorkers` |
| `WorkersScaled` | Per spawn/terminate action | `$connection`, `$queue`, `$from`, `$to`, `$action`, `$reason` |
| `ClusterScalingSignalUpdated` | When the leader publishes a new host scaling signal | `$clusterId`, `$leaderId`, `$currentHosts`, `$recommendedHosts`, `$currentCapacity`, `$requiredWorkers`, `$action`, `$reason` |
| `AutoscaleManagerStarted` | When a manager process starts | `$managerId`, `$host`, `$clusterEnabled`, `$clusterId`, `$intervalSeconds`, `$startedAt`, `$packageVersion` |
| `AutoscaleManagerStopped` | When a manager process stops | `$managerId`, `$host`, `$clusterEnabled`, `$clusterId`, `$startedAt`, `$stoppedAt`, `$reason`, `$workerCount`, `$packageVersion` |
| `ClusterLeaderChanged` | When a manager observes the cluster leader change | `$clusterId`, `$previousLeaderId`, `$currentLeaderId`, `$observedByManagerId`, `$changedAt` |
| `ClusterManagerPresenceChanged` | When the leader observes managers join/leave the active set | `$clusterId`, `$managerIds`, `$addedManagerIds`, `$removedManagerIds`, `$leaderId`, `$observedByManagerId`, `$observedAt` |
| `ClusterSummaryPublished` | When the leader publishes a fresh cluster summary | `$clusterId`, `$leaderId`, `$summary`, `$publishedAt` |

## Alerting

### `AlertRateLimiter`

Cache-lock-based cooldown helper. Used internally by `BreachNotificationPolicy` and recommended for custom alert listeners.

```php
namespace Cbox\LaravelQueueAutoscale\Alerting;

readonly class AlertRateLimiter
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

class WorkerProcess
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
    public function isTerminating(): bool;
    public function markTerminationRequested(Carbon $requestedAt, int $timeoutSeconds): void;
    public function terminationDeadlinePassed(Carbon $now): bool;
    public function uptimeSeconds(): int;
    public function matches(string $connection, string $queue): bool;        // false for group workers
    public function matchesGroup(string $connection, string $group): bool;
    public function isGroupWorker(): bool;
    public function getIncrementalOutput(): string;
    public function getIncrementalErrorOutput(): string;
}
```

### `WorkerPool`

Collection wrapper over `WorkerProcess`, held in-process by the manager daemon. A web request cannot see it.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerPool
{
    public function add(WorkerProcess $worker): void;
    public function addMany(Collection $workers): void;
    public function removeWorker(WorkerProcess $worker): void;
    public function remove(string $connection, string $queue, int $count): Collection;
    public function removeFromGroup(string $connection, string $group, int $count): Collection;

    public function count(string $connection, string $queue): int;
    public function countGroup(string $connection, string $group): int;
    public function totalCount(): int;
    public function queueCounts(): array;
    public function groupCounts(): array;

    public function all(): Collection;
    public function getDeadWorkers(): Collection;
    public function getTerminatingWorkers(): Collection;
    public function getByConnection(string $connection, string $queue): array;
    public function getTerminatable(string $connection, string $queue, int $count): Collection;
    public function getTerminatableFromGroup(string $connection, string $group, int $count): Collection;
    public function findByPid(int $pid): ?WorkerProcess;
    public function reset(): void;
}
```

There is no `getWorkerCount()` — use `count($connection, $queue)`, `countGroup()`, `totalCount()`, `queueCounts()` or `groupCounts()`.

### `WorkerSpawner`

Spawns `queue:work` subprocesses. The command it builds is exactly:

```bash
{PHP_BINARY} artisan queue:work {connection} \
    --queue={queue} \
    --tries={workers.tries} \
    --max-time={workers.max_time_seconds} \
    --timeout={workers.timeout_seconds} \
    --sleep={workers.sleep_seconds}
```

Every value comes from the queue's resolved `WorkerConfiguration`. `--memory` is never passed.

The only environment variables injected into a worker are:

```text
LARAVEL_AUTOSCALE_WORKER=true
AUTOSCALE_MANAGER_ID=<manager id>
AUTOSCALE_WORKER_GROUP=<group name>   # group workers only
```

## Facade

`Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale` proxies `Cbox\LaravelQueueAutoscale\LaravelQueueAutoscale`, which has exactly two public methods:

```php
readonly class LaravelQueueAutoscale
{
    /** @return array<string, mixed> The Redis cluster summary; [] when cluster mode is off. */
    public function cluster(): array;

    /** @return array<int, array{name: string, value: int|float, labels: array<string, scalar|null>}> */
    public function clusterMetrics(): array;
}
```

There is no runtime API for overriding a queue's bounds or forcing a scale — no `overrideMinWorkers()`, `scaleToCapacity()` or `resetToNormal()`. Use config, or a [scaling policy](../basic-usage/scaling-policies.md).

## Console Commands

| Command | Signature |
|---|---|
| `queue:autoscale` | `{--interval=5} {--replace}` — the daemon. `--interval` is the **only** way to set the evaluation interval. |
| `queue:autoscale:cluster` | `{--json}` — cluster leader, active managers, host capacity, workload targets, host scale signal. |
| `queue:autoscale:debug` | `{--queue=default} {--connection=}` — dump queue state and metrics for diagnosis. |
| `queue:autoscale:install` | `{--topology=} {--metrics-connection=} {--publish-migrations} {--write-env} {--env-file=} {--force} {--no-publish}` — `--topology` is one of `single-low`, `single-redis`, `cluster`. |
| `queue:autoscale:restart` | Signal running managers to restart gracefully. |
| `queue-autoscale:migrate-config` | `{--source=} {--destination=}` — migrates a **v1** config to **v2** shape. Default destination is `config/queue-autoscale.v2.php`; it warns and skips if the source does not look like a v1 config. |

`queue-autoscale:debug-queue` does not exist — the debug command is `queue:autoscale:debug`.

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

## Not in this package

Names that appear in older documentation, blog posts or generated code but do **not** exist in v3:

| Name | Reality |
|---|---|
| `ScalingPolicyContract` | The interface is `ScalingPolicy`. |
| `PredictiveStrategy` | Only `HybridStrategy`, `BacklogOnlyStrategy`, `ConservativeStrategy`, `SimpleRateStrategy` ship. |
| `ProfilePresets` (`::balanced()` etc.) | Removed in v3. Use the profile classes. |
| `ResourceConstraintChecker`, `ResourceConstraintPolicy` | Resource limits live in `CapacityCalculator`, applied inside `ScalingEngine`. |
| `ScalingDecision::$confidence` | No confidence value exists anywhere in the package. |
| `WorkerHealthCheckFailed` | No worker-health event exists. the manager's inline liveness check only answers whether a PID is alive. |
| `WorkersScaled::$newCount` | The properties are `from` and `to`. |
| `QueueConfiguration::$minWorkers` / `$maxWorkers` / `$maxPickupTimeSeconds` | Nested: `$config->workers->min`, `$config->workers->max`, `$config->sla->targetSeconds`. |
| `AutoscaleManager::getWorkerCount()` | The manager exposes only `configure()`, `setOutput()`, `setRenderer()` and `run()`. |
| `queue-autoscale:debug-queue` | The command is `queue:autoscale:debug`. |
| Cost/budget config (`cost_limits`, spot-instance keys) | No cost feature exists. |
| `'strategy' => ['class' => ..., 'options' => [...]]` | `strategy` is a plain class string. |
| `trend_weight`, `safety_margin`, `min_trend_samples` | No such config keys. |
| `QUEUE_AUTOSCALE_EVALUATION_INTERVAL` and similar env vars | Not read. The interval is `queue:autoscale --interval=`. |

## See Also

- [Basic Usage](../basic-usage/_index.md) — Implementation guides
- [Advanced Usage](../advanced-usage/_index.md) — Custom strategies and policies
- [Algorithms](../algorithms/_index.md) — Mathematical foundations
- [Queue Topology](../basic-usage/queue-topology.md) — Per-queue vs. groups vs. exclusive vs. excluded
