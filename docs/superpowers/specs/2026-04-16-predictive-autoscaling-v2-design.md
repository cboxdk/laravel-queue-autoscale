# Predictive Autoscaling v2 — Design Spec

**Date:** 2026-04-16
**Status:** Draft for review
**Version target:** v2.0.0 (breaking changes)

## 1. Context & Motivation

The package currently ships as v1 with three calculators (`LittlesLawCalculator`, `BacklogDrainCalculator`, `ArrivalRateEstimator`) combined by `PredictiveStrategy` taking `max()` of three worker estimates.

Gap analysis (documented separately) identified three weaknesses:

1. **No real forecasting** — the "predictive" label is backed by a fixed 10-30% multiplier applied to observed arrival rate (via `TrendScalingPolicy`). No trend fitting, no confidence weighting. A 3-5 minute gradual ramp escapes the 60-second observation window.
2. **Worker spawn latency unaccounted for** — scaling decisions assume new workers start working instantly. Actual cold start is 500-2000ms in typical environments, causing SLA breaches during the spawn-to-first-pickup gap.
3. **Fragile SLA signal** — `oldestJobAge` is used as SLA proxy; one stuck job triggers false breach signals. No support for percentile-based SLAs (industry standard).

This spec addresses all three while restructuring the config for cleanliness and adopting a Spatie-style class-replaceable architecture.

## 2. Goals & Non-Goals

### Goals
- Implement genuine forecasting (linear regression with R²-weighted confidence blending)
- Measure and compensate for worker spawn latency in scaling decisions
- Replace oldest-job-age SLA signal with p95 pickup time over a sliding window
- Restructure config for clarity (grouped by concern: `sla`, `forecast`, `spawn_compensation`, `pickup_time`)
- Make every algorithmic component class-replaceable via Laravel container binding
- Maintain PHPStan level 9 compliance
- Maintain or improve test coverage (currently 39 unit tests + simulation suite)

### Non-Goals
- ARIMA, Prophet, or ML-based forecasting (roadmap item for v2.1+)
- Seasonality detection (roadmap item)
- Cross-queue worker rebalancing (roadmap item)
- Cost-aware scaling (roadmap item)
- Kubernetes HPA / AWS ASG integration (external concern)
- Backward compatibility with v1 config shape (breaking release; migration tool provided)

## 3. High-Level Architecture

### Component map

```
src/
├── Contracts/                                          [NEW NAMESPACE]
│   ├── ForecasterContract.php
│   ├── ForecastPolicyContract.php
│   ├── SpawnLatencyTrackerContract.php
│   ├── PickupTimeStoreContract.php
│   ├── PercentileCalculatorContract.php
│   └── ProfileContract.php
│
├── Scaling/
│   ├── Calculators/
│   │   ├── LittlesLawCalculator.php                   [UNCHANGED]
│   │   ├── BacklogDrainCalculator.php                 [UPDATE — takes effective SLA]
│   │   ├── ArrivalRateEstimator.php                   [UPDATE — blends forecast]
│   │   └── LinearRegressionForecaster.php             [NEW]
│   │
│   ├── Forecasting/                                   [NEW]
│   │   ├── ForecastResult.php                         (DTO)
│   │   └── Policies/
│   │       ├── DisabledForecastPolicy.php
│   │       ├── HintForecastPolicy.php
│   │       ├── ModerateForecastPolicy.php
│   │       └── AggressiveForecastPolicy.php
│   │
│   └── Strategies/
│       ├── HybridStrategy.php                         [RENAMED from PredictiveStrategy]
│       ├── SimpleRateStrategy.php                     [UNCHANGED]
│       ├── BacklogOnlyStrategy.php                    [UNCHANGED]
│       └── ConservativeStrategy.php                   [UNCHANGED]
│
├── Workers/
│   ├── WorkerSpawner.php                              [UPDATE — stamps spawn time]
│   ├── WorkerTerminator.php                           [UNCHANGED]
│   └── SpawnLatency/                                  [NEW]
│       └── EmaSpawnLatencyTracker.php
│
├── Pickup/                                            [NEW NAMESPACE]
│   ├── RedisPickupTimeStore.php
│   ├── SortBasedPercentileCalculator.php
│   └── PickupTimeRecorder.php                         (Event listener)
│
├── Configuration/
│   ├── SlaConfiguration.php                           [NEW]
│   ├── ForecastConfiguration.php                      [NEW]
│   ├── SpawnCompensationConfiguration.php             [NEW]
│   ├── WorkerConfiguration.php                        [NEW]
│   ├── QueueConfiguration.php                         [UPDATE — composes above]
│   └── Profiles/                                      [NEW — replaces ProfilePresets]
│       ├── CriticalProfile.php
│       ├── HighVolumeProfile.php
│       ├── BalancedProfile.php
│       ├── BurstyProfile.php
│       └── BackgroundProfile.php
│
└── Commands/
    └── MigrateConfigCommand.php                       [NEW — v1→v2 migrator]
```

### Data flow (v2)

```
1. Job picked up → PickupTimeRecorder listens to JobProcessing event
                → stores [ts, pickup_ms] to Redis list

2. Every 5s, HybridStrategy evaluates:

   a. Read current queue metrics from cboxdk/queue-metrics
      (pending, processing rate, active workers, avg duration)

   b. Read spawn latency EMA from SpawnLatencyTracker
         effective_SLA = sla.target_seconds - spawn_latency_ema

   c. Read pickup time samples from RedisPickupTimeStore (window)
      compute p95 via SortBasedPercentileCalculator
      fallback to oldest_job_age if samples < min_samples

   d. Read arrival rate history (5 min snapshots)
      LinearRegressionForecaster fits line, returns ForecastResult
         (projected_rate, r_squared, slope, confidence)

   e. ForecastPolicy decides blending:
         if r_squared < min_r_squared: λ = λ_observed
         else: λ = w·λ_forecast + (1-w)·λ_observed

   f. Two calculators run (blended forecast replaces v1's separate trend calculator):
         W_rate    = LittlesLaw(λ_effective, avg_duration)
         W_backlog = BacklogDrain(p95, effective_SLA, pending, λ_effective)

   g. target = max(W_rate, W_backlog)
      clamped to [min_workers, max_workers]

3. WorkerSpawner spawns workers, stamps {worker_id, ts} in Redis (TTL 5min)
4. On first heartbeat: SpawnLatencyTracker computes latency, updates EMA
```

## 4. Feature 1 — Hybrid Forecasting

### `ForecasterContract`

```php
interface ForecasterContract
{
    /**
     * @param list<array{timestamp: int, rate: float}> $history
     */
    public function forecast(array $history, int $horizonSeconds): ForecastResult;
}
```

### `LinearRegressionForecaster` (default implementation)

Ordinary Least Squares fit on `(timestamp, rate)` pairs. Computes:
- `slope` (λ/sec)
- `intercept`
- `projected_rate = slope × (now + horizon) + intercept`
- `r_squared` (coefficient of determination)

**Edge cases:**
- `count(history) < 5` → returns `ForecastResult::insufficientData()`
- `slope ≈ 0` (flat line) → `projected_rate = current_rate`, R² computed normally
- Negative slope permitted (enables proactive scale-down)
- Samples older than `history_seconds` filtered out before fit

**Algorithm slide-friendly description:**
> Fit a line through the last N minutes of arrival-rate snapshots. The slope indicates how fast load is changing. R² measures how well the line fits — 1.0 is perfect, 0.0 is pure noise.

### `ForecastPolicyContract`

```php
interface ForecastPolicyContract
{
    public function minRSquared(): float;       // trust threshold
    public function forecastWeight(): float;    // blending weight [0, 1]
}
```

Four default implementations:

| Policy     | min R² | forecast weight | semantics                                      |
|------------|--------|-----------------|------------------------------------------------|
| Disabled   | 1.1    | 0.0             | never trust forecast (effectively disabled)    |
| Hint       | 0.8    | 0.3             | require strong fit, small buffer               |
| Moderate   | 0.6    | 0.5             | balanced (DEFAULT)                             |
| Aggressive | 0.4    | 0.8             | trust forecast even with noise                 |

### `ForecastResult` DTO

```php
final readonly class ForecastResult
{
    public function __construct(
        public float $projectedRate,
        public float $rSquared,
        public float $slope,
        public int $sampleCount,
        public bool $hasSufficientData,
    ) {}

    public static function insufficientData(): self
    {
        return new self(0.0, 0.0, 0.0, 0, false);
    }
}
```

### Blending in `ArrivalRateEstimator`

```php
$forecast = $this->forecaster->forecast($history, $horizonSeconds);

if (!$forecast->hasSufficientData || $forecast->rSquared < $policy->minRSquared()) {
    return $observedRate;  // fallback, current v1 behavior
}

$weight = $policy->forecastWeight();
return ($weight * $forecast->projectedRate) + ((1 - $weight) * $observedRate);
```

## 5. Feature 2 — Spawn Latency Compensation

### `SpawnLatencyTrackerContract`

```php
interface SpawnLatencyTrackerContract
{
    public function recordSpawn(string $workerId, string $connection, string $queue): void;
    public function recordFirstPickup(string $workerId, int $pickupTimestamp): void;
    public function currentLatency(string $connection, string $queue): float; // seconds
}
```

### `EmaSpawnLatencyTracker` (default)

Redis-backed EMA with α = 0.2.

**Keys:**
- `autoscale:spawn:pending:{worker_id}` → `{spawn_ts, connection, queue}`, TTL 5min
- `autoscale:spawn:ema:{connection}:{queue}` → float seconds
- `autoscale:spawn:count:{connection}:{queue}` → int (samples recorded)

**Logic:**
```
recordSpawn(workerId, conn, queue):
    SET autoscale:spawn:pending:{workerId} = {ts, conn, queue}, EX 300

recordFirstPickup(workerId, pickupTs):
    pending = GET autoscale:spawn:pending:{workerId}
    if !pending: return  // no-op, worker pre-dated tracking
    latency = pickupTs - pending.ts (in seconds, as float)
    latency = clamp(latency, 0.1, 30.0)
    current_ema = GET autoscale:spawn:ema:{conn}:{queue} ?? latency
    new_ema = 0.2 * latency + 0.8 * current_ema
    SET autoscale:spawn:ema:{conn}:{queue} = new_ema
    INCR autoscale:spawn:count:{conn}:{queue}
    DEL autoscale:spawn:pending:{workerId}

currentLatency(conn, queue):
    count = GET autoscale:spawn:count:{conn}:{queue} ?? 0
    if count < 5: return config('spawn_compensation.fallback_seconds')
    return GET autoscale:spawn:ema:{conn}:{queue}
```

### First-pickup signal

Laravel's `Illuminate\Queue\Events\JobProcessing` event fires before each job is processed. We listen via `PickupTimeRecorder` (see Feature 3) and correlate to `SpawnLatencyTracker` using worker PID or job consumer ID.

**Open question:** `cboxdk/laravel-queue-metrics` may already track first-pickup per worker via `WorkerHeartbeatRepository`. To be verified during implementation. If not, the event-listener approach is authoritative.

### Compensation in strategy

```php
$effectiveSLA = $sla->targetSeconds - $spawnTracker->currentLatency($conn, $queue);
$effectiveSLA = max($effectiveSLA, 1.0); // floor at 1 second

// BacklogDrainCalculator now receives effectiveSLA instead of raw SLA
```

## 6. Feature 3 — p95 SLA

### `PickupTimeStoreContract`

```php
interface PickupTimeStoreContract
{
    public function record(string $connection, string $queue, int $pickupTimestamp, float $pickupTimeSeconds): void;

    /**
     * @return list<array{timestamp: int, pickup_seconds: float}>
     */
    public function recentSamples(string $connection, string $queue, int $windowSeconds): array;
}
```

### `RedisPickupTimeStore` (default)

Redis list per queue, bounded by `LTRIM`.

**Keys:**
- `autoscale:pickup:{connection}:{queue}` → list of `{ts|pickup_seconds}` strings

**Logic:**
```
record(conn, queue, ts, pickup):
    LPUSH autoscale:pickup:{conn}:{queue} "{ts}|{pickup}"
    LTRIM autoscale:pickup:{conn}:{queue} 0 999   // keep max 1000

recentSamples(conn, queue, window):
    entries = LRANGE autoscale:pickup:{conn}:{queue} 0 -1
    cutoff = now() - window
    return filter(entries, fn($e) => parse($e).ts >= cutoff)
```

Memory footprint: ~15 bytes per entry × 1000 = ~15 KB per queue. Acceptable.

### `PickupTimeRecorder` (event listener)

Listens to `Illuminate\Queue\Events\JobProcessing`. Reads `job_id` + `pickup_time` from queue-metrics (if available) or from the job payload's `pushedAt` timestamp.

```php
public function handle(JobProcessing $event): void
{
    $pushedAt = $event->job->payload()['pushedAt'] ?? null;
    if (!$pushedAt) return;

    $pickupSeconds = max(0.0, microtime(true) - $pushedAt);
    $this->store->record(
        $event->connectionName,
        $event->job->getQueue() ?? 'default',
        (int) floor(microtime(true)),
        $pickupSeconds,
    );
}
```

### `PercentileCalculatorContract`

```php
interface PercentileCalculatorContract
{
    /**
     * @param list<float> $values
     * @return float|null Null if insufficient samples.
     */
    public function compute(array $values, int $percentile): ?float;
}
```

### `SortBasedPercentileCalculator` (default)

```php
public function compute(array $values, int $percentile): ?float
{
    $count = count($values);
    if ($count < 20) return null;

    sort($values);
    $index = (int) ceil(($percentile / 100) * $count) - 1;
    return $values[max(0, min($index, $count - 1))];
}
```

Minimum 20 samples ensures statistical meaningfulness. Below this, callers fall back to `oldestJobAge`.

### SLA check in strategy

```php
$samples = array_column(
    $pickupStore->recentSamples($conn, $queue, $sla->windowSeconds),
    'pickup_seconds',
);

$p95 = $percentileCalc->compute($samples, $sla->percentile);
$slaSignal = $p95 ?? $metrics->oldestJobAge;  // graceful fallback

if ($slaSignal > $breachThreshold * $effectiveSLA) {
    $backlogWorkers = $backlogDrain->workersNeeded(...);
}
```

## 7. Config Restructure (v2)

### Separation of concerns

- **Per-queue settings** (overridable per queue): `sla`, `forecast`, `workers`, `spawn_compensation`. These live inside profile classes or explicit arrays under `sla_defaults` / `queues`.
- **Global settings** (one instance per app): `pickup_time` (store backend), `scaling`, `limits`, `manager`, `strategy`, `policies`.

### New config file

```php
// config/queue-autoscale.php
return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
    'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID', gethostname()),

    // Per-queue defaults. Can be a ProfileContract class OR a literal array shaped like
    // BalancedProfile::resolve() returns.
    'sla_defaults' => \Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile::class,

    // Per-queue overrides. Each value can be a profile class OR an array of partial overrides
    // that merges with sla_defaults.
    'queues' => [
        // 'payments' => CriticalProfile::class,
        // 'custom' => ['sla' => ['target_seconds' => 45]],
    ],

    // Global: pickup time storage backend (shared across all queues).
    'pickup_time' => [
        'store' => RedisPickupTimeStore::class,
        'percentile_calculator' => SortBasedPercentileCalculator::class,
        'max_samples_per_queue' => 1000,
    ],

    // Global: scaling algorithm tuning.
    'scaling' => [
        'fallback_job_time_seconds' => 2.0,
        'breach_threshold' => 0.5,
        'cooldown_seconds' => 60,
    ],

    // Global: system resource caps.
    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
        'worker_memory_mb_estimate' => 128,
        'reserve_cpu_cores' => 1,
    ],

    // Global: manager process.
    'manager' => [
        'evaluation_interval_seconds' => 5,
        'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
    ],

    'strategy' => HybridStrategy::class,

    'policies' => [
        ConservativeScaleDownPolicy::class,
        BreachNotificationPolicy::class,
    ],
];
```

### Example profile output

```php
final class BalancedProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 30,
                'percentile' => 95,
                'window_seconds' => 300,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => ModerateForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 1,
                'max' => 10,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 3,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
```

Per-queue `queues[name]` arrays perform a deep merge on top of `sla_defaults` output. Missing keys inherit from defaults.

### Renames (breaking)

| v1                          | v2                                   | reason                                |
|-----------------------------|--------------------------------------|---------------------------------------|
| `PredictiveStrategy`        | `HybridStrategy`                     | honest name (forecasts + other signals) |
| `TrendScalingPolicy` (enum) | `ForecastPolicyContract` + 4 classes | class-replaceable                     |
| `ProfilePresets` (static)   | `ProfileContract` + 5 classes        | class-replaceable                     |
| `max_pickup_time_seconds`   | `sla.target_seconds`                 | grouped, semantics clarified          |
| `trend_policy`              | `forecast.policy`                    | accurate naming                       |
| `scaling.trend_window_seconds` | `forecast.history_seconds`        | accurate naming                       |
| `scaling.forecast_horizon_seconds` | `forecast.horizon_seconds`    | grouped                               |
| `workers.timeout_seconds` stays | same                             | no rename                             |

### `QueueConfiguration` (v2 shape)

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
    ) {}

    public static function fromConfig(string $connection, string $queue): self
    {
        // Resolves profile class or array overrides, constructs nested value objects.
    }
}
```

Each sub-configuration validates its invariants in `__construct()` (throws `InvalidConfigurationException`).

### `ProfileContract`

```php
interface ProfileContract
{
    /**
     * @return array{
     *     sla: array{target_seconds: int, percentile: int, window_seconds: int, min_samples: int},
     *     forecast: array{forecaster: class-string, policy: class-string, horizon_seconds: int, history_seconds: int},
     *     workers: array{min: int, max: int, tries: int, timeout_seconds: int, sleep_seconds: int, shutdown_timeout_seconds: int},
     *     spawn_compensation: array{enabled: bool, fallback_seconds: float, min_samples: int, ema_alpha: float},
     * }
     */
    public function resolve(): array;
}
```

Separation of concerns: profiles produce mergeable arrays (format). `QueueConfiguration::fromConfig()` consumes the merged array and constructs typed value objects (runtime representation).

Users can define custom profiles by implementing `ProfileContract`. They reference the class directly in `sla_defaults` or `queues[name]` — no service provider binding required.

## 8. Migration v1 → v2

### `queue-autoscale:migrate-config` command

Reads existing `config/queue-autoscale.php` (v1 shape) and writes a new file in v2 shape next to it with `.v2.php` suffix. User reviews and replaces manually (safer than in-place edit).

Detection: presence of `max_pickup_time_seconds` at top level of `sla_defaults` or lack of `sla` key.

### CHANGELOG entry

Full BREAKING CHANGES section, side-by-side v1 → v2 config example, pointer to the migration command.

### Upgrade guide

New file: `docs/upgrade-guide-v2.md`. Step-by-step instructions. Published on cbox.dk.

## 9. Test Strategy

### Unit tests (Pest, PHPStan level 9)

Per new class: constructor validation, algorithm correctness, edge cases (empty input, single sample, outliers, insufficient data). See section 5 in brainstorming for full enumeration.

### Integration tests

- `HybridStrategy` with all contracts wired to test doubles
- Contract swapping via container binding verified
- `QueueConfiguration::fromConfig` resolves profile classes and array overrides correctly

### Simulation tests (extend existing `tests/Simulation/SimulationTest.php`)

Three new scenarios with quantitative assertions:

1. **`testForecastingReducesBreaches`**
   - Gradual ramp-up over 3 minutes
   - Compare breach rate with `DisabledForecastPolicy` vs `ModerateForecastPolicy`
   - Assert: moderate reduces breaches by ≥ 30%

2. **`testSpawnCompensationPreventsBreachDuringColdStart`**
   - Simulate slow spawn (3 seconds)
   - Assert: first scale-up triggers early enough that no job exceeds SLA

3. **`testP95ResilientToOutliers`**
   - 100 normal pickups + 2 stuck jobs
   - Assert: `p95` signal stays below breach threshold; max-based would have breached

### Arch tests

```php
// tests/Arch.php
arch('contracts live in Contracts namespace')
    ->expect('Cbox\LaravelQueueAutoscale\Contracts')
    ->toBeInterfaces();

arch('concrete classes depend only on contracts')
    ->expect('Cbox\LaravelQueueAutoscale\Scaling\Strategies')
    ->not->toUse('Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster');
```

### CI

- Default CI: unit + integration + arch tests + short simulation smoke (2 min budget)
- Weekly CI: full simulation suite (18+ min)
- PHPStan level 9 must pass on every commit
- Pint must pass on every commit

## 10. Risks & Open Questions

1. **`cboxdk/laravel-queue-metrics` integration for first-pickup signal.** Need to verify during implementation whether `WorkerHeartbeatRepository` exposes first-pickup timestamp. If not, `JobProcessing` event listener is the fallback (~20 LoC additional).

2. **Pickup time from job payload.** Laravel's `pushedAt` is available on most drivers but not universally. For drivers that omit it, `PickupTimeRecorder` silently skips recording. p95 computation then falls back to `oldestJobAge`. This is intentional graceful degradation but should be clearly documented.

3. **Migration command robustness.** v1 config can be heavily customized by users. The migration command should be best-effort with clear warnings for anything it can't translate (e.g., custom `ProfilePresets` arrays).

4. **R² for constant arrival rate.** When variance is zero, R² is technically undefined (0/0). Implementation must return `rSquared = 1.0` for constant series (perfect fit) rather than NaN.

5. **Clock skew between spawn and pickup.** If the spawning manager and the worker run on different machines with clock skew, spawn latency measurement will be biased. Solution: use manager clock for both timestamps (worker reports pickup time to manager, not vice versa). Documented in implementation.

6. **Redis availability.** All new components depend on Redis. If Redis is unreachable, the package falls back to v1 behavior (observed rate only, oldest-age SLA). Degradation paths tested.

## 11. Rollout

1. Branch: `v2` from `main`
2. Implement in order: contracts → defaults → migration command → strategy update → tests → docs
3. Alpha release: `v2.0.0-alpha.1` for internal testing
4. Beta release: `v2.0.0-beta.1` for community feedback
5. Stable: `v2.0.0` tagged after successful simulation suite + 2 weeks of beta
6. Talk mentions v2 as "released today" with slide-worthy narrative

## 12. Non-goals explicitly called out for Q&A

- "Why no ML?" — Linear regression is sufficient for the ramps we observe. ML adds operational complexity (training, versioning, drift detection) without proportional benefit. Roadmap item only.
- "Why not just use Horizon?" — Horizon balances queues, this package scales worker count. Complementary concerns.
- "Why Redis?" — Queue-metrics already requires Redis; we piggyback on existing infra.
