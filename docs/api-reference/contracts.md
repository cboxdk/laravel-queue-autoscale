---
title: "Contracts"
description: "The twelve interfaces the package resolves from the container, and what a consumer implements to replace one"
weight: 10
---

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
