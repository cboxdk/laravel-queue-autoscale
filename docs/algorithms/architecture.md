---
title: "Architecture"
description: "Deep dive into the scaling pipeline: signals, the two-calculation hybrid, capacity, the fuse, policies and worker lifecycle"
weight: 50
---

# Architecture

A walk through what actually happens between "metrics arrived" and "a worker process started",
with the real formulas from `src/`.

## Table of Contents

- [Overview](#overview)
- [Package boundary](#package-boundary)
- [Theoretical foundation](#theoretical-foundation)
- [The hybrid strategy](#the-hybrid-strategy)
- [Input signals](#input-signals)
- [Decision pipeline](#decision-pipeline)
- [Component map](#component-map)
- [Resource capacity](#resource-capacity)
- [The failure fuse](#the-failure-fuse)
- [Anti-flapping cooldown](#anti-flapping-cooldown)
- [Worker lifecycle](#worker-lifecycle)
- [Extension points](#extension-points)
- [Performance considerations](#performance-considerations)
- [Design decisions](#design-decisions)

## Overview

The default strategy, `HybridStrategy`, combines **two** worker calculations:

1. **Rate-based** — Little's Law over the estimated arrival rate (`LittlesLawCalculator`)
2. **Backlog-based** — SLA breach prevention with a progressive aggressiveness multiplier
   (`BacklogDrainCalculator`)

```php
$targetWorkers = max($steadyStateWorkers, $backlogDrainWorkers);
```

Forecasting is not a third term. It feeds the arrival rate that goes into term 1 — see
[Trend Prediction](trend-prediction.md).

Everything else in the pipeline is a **constraint on** that target: system capacity, config bounds,
the failure fuse, and finally the scaling policies.

## Package boundary

```text
┌────────────────────────────────────────────────────────────┐
│        laravel-queue-metrics (dependency)                  │
│                                                            │
│  • Scans queue connections (redis, database, sqs)          │
│  • Discovers active queues                                 │
│  • Collects depth, age, throughput, duration, failures     │
│  • Returns Collection<QueueMetricsData>                    │
└──────────────────────────┬─────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────────┐
│        laravel-queue-autoscale (this package)              │
│                                                            │
│  • Estimates arrival rate and forecasts it                 │
│  • Runs the two scaling calculations                       │
│  • Applies capacity, config, fuse and policy constraints   │
│  • Spawns and terminates queue:work processes              │
│  • Emits events and telemetry                              │
│                                                            │
│  DOES NOT: scan connections, discover queues, or collect   │
│  queue metrics                                             │
└────────────────────────────────────────────────────────────┘
```

`QueueMetricsData` carries `connection`, `queue`, `depth`, `pending`, `scheduled`, `reserved`,
`oldestJobAge`, `ageStatus`, `throughputPerMinute`, `avgDuration`, `failureRate`,
`utilizationRate`, `activeWorkers`, `driver`, `health` and `calculatedAt`. There is no `trend`
object and no `depth->pending` nesting — the fields are flat.

`AutoscaleManager::mapMetricsFields()` converts `avgDuration` from milliseconds to **seconds**
before the DTO reaches the strategy.

## Theoretical foundation

### Little's Law (L = lambda W)

```text
L = lambda x W

L      = jobs in service at any instant = workers required
lambda = arrival rate (jobs/second)
W      = time one job occupies one worker (seconds)
```

```php
public function calculate(float $arrivalRate, float $avgProcessingTime): float
{
    if ($arrivalRate <= 0 || $avgProcessingTime <= 0) {
        return 0.0;
    }

    return $arrivalRate * $avgProcessingTime;
}
```

Full derivation and worked examples: [Little's Law](littles-law.md).

### SLA-based targets

The configured objective is a pickup-time SLA (`sla.target_seconds`), not a worker count. Worker
counts are derived; the SLA is the thing an operator states and an alert can be written against.

## The hybrid strategy

`HybridStrategy::calculateTargetWorkers()`, in execution order:

```text
 1. processingRate = metrics.throughputPerMinute / 60
 2. avgJobTime     = determineJobTime(metrics)
 3. configure the forecaster from this queue's forecast config
 4. arrivalRate    = ArrivalRateEstimator::estimate(...) behind a confidence gate
 5. if arrivalRate == 0: estimateFallbackArrivalRate(...)
 6. subtract retry noise when failureRate > 5%
 7. effectiveSla   = max(1.0, sla.target_seconds - spawnLatency)
 8. slaSignal      = p95 pickup time, else metrics.oldestJobAge
 9. steadyState    = littles.calculate(arrivalRate, avgJobTime)
10. backlogDrain   = backlog.calculateRequiredWorkers(pending, (int) slaSignal,
                         sla.target_seconds, avgJobTime, breachThreshold, effectiveSla)
11. target         = max(steadyState, backlogDrain)
12. saturation boost
13. target         = max(workers.min, min(workers.max, ceil(target)))
14. target         = TargetSmoother::smooth(queueKey, target, throughputPerMinute), re-clamped
```

### Backlog drain, in full

```text
if backlog == 0 or avgJobTime <= 0:            return 0.0

effectiveSla = effectiveSlaSeconds ?? slaTarget

if oldestJobAge == 0 and backlog > 0:          # age signal unavailable
    jobsPerWorker = max(effectiveSla / avgJobTime, 1.0)
    return backlog / jobsPerWorker             # no multiplier on this path

slaProgress = min(oldestJobAge / effectiveSla, 1.5)
if slaProgress < breachThreshold:              return 0.0

timeUntilBreach = effectiveSla - oldestJobAge
baseWorkers = timeUntilBreach > 0
    ? backlog / max(timeUntilBreach / avgJobTime, 1.0)
    : backlog / max(avgJobTime, 0.1)

multiplier = min(1.0 + 8.0 * (slaProgress - 0.5)^2, 5.0)
return baseWorkers * multiplier
```

`breachThreshold` is `scaling.breach_threshold`, **default 0.5** (50% of the SLA window).

The **progressive aggressiveness multiplier** is the most consequential part of this calculation and
is easy to miss:

| SLA progress | Multiplier |
|---|---|
| 50% (threshold) | 1.00x |
| 70% | 1.32x |
| 80% | 1.72x |
| 100% (at SLA) | 3.00x |
| 120% | 4.92x |
| ~121% and beyond | 5.00x (cap) |

There is no branch that jumps to `workers.max` on breach, and no `ceil(maxWorkers * 0.8)` fallback.
Full derivation: [Backlog Drain](backlog-drain.md).

### Saturation boost

```php
if ($activeWorkers > 0 && $utilizationRate > 0) {
    if ($utilizationRate >= 90.0 && $targetWorkers <= $activeWorkers) {
        $targetWorkers = $activeWorkers + 1;
    }
}
```

Workers reporting 90%+ utilisation while the arithmetic says "no change" means throughput data is
lagging reality. One worker is added to break out of saturation.

### Target smoothing

`TargetSmoother` keeps the last 10 throughput samples per queue. When a **scale-down** is requested
and the coefficient of variation of throughput is below 5% (needing at least 3 samples), the
decrease is limited to one worker per cycle. Scale-up is never smoothed.

This kills the oscillation where a transient `pending = 0` collapses the Little's Law term and the
target snaps back up on the next tick.

## Input signals

| Signal | Source | Notes |
|---|---|---|
| `arrivalRate` | `ArrivalRateEstimator` + forecast blend | Gated by `scaling.min_arrival_rate_confidence` (0.5) |
| `avgJobTime` | `metrics.avgDuration` in seconds | Accepted only within `[0.01, 600]`, else `scaling.fallback_job_time_seconds` (2.0) |
| `effectiveSla` | `sla.target_seconds` minus EMA spawn latency | Only when `spawn_compensation.enabled`; floored at 1.0 s |
| `slaSignal` | `PickupTimeStore` percentile | `sla.percentile` over `sla.window_seconds`, needs `sla.min_samples`; falls back to `metrics.oldestJobAge` |
| `utilizationRate` | metrics | Drives the saturation boost |
| `failureRate` | metrics (lifetime) | Drives retry-noise subtraction and, separately, the fuse uses its own window |

For group configurations the pickup samples are collected across every member queue
(`QueueConfiguration::sampleQueues()`), because samples are stored under the real queue names.

## Decision pipeline

The order matters, and policies come **last**, not first.

```text
metrics (laravel-queue-metrics)
    │
    ▼
HybridStrategy::calculateTargetWorkers()          # max(steadyState, backlogDrain), clamped, smoothed
    │
    ▼
ScalingEngine::evaluate()
    ├─ 1. strategyRecommendation
    ├─ 2. capacityResult = CapacityCalculator::calculateMaxWorkers(
    │        max(totalPoolWorkers, currentWorkers),
    │        ResourceEstimateResolver::resolve(connection, queue))
    ├─ 3. availableForThisQueue = max(finalMaxWorkers - otherQueuesWorkers, 0)
    │     target = min(target, availableForThisQueue)
    ├─ 4. target = max(target, workers.min); target = min(target, workers.max)
    ├─ 5. fuseCeiling = FailureFuse::evaluate(config)->workerCeiling(workers.min)
    │     if not null: target = min(target, max(0, min(fuseCeiling, workers.max)))
    └─ 6. limitingFactor + ScalingDecision
    │
    ▼
AutoscaleManager
    ├─ SLA breach check + anti-flapping cooldown (may return early)
    ├─ PolicyExecutor::beforeScaling(decision) -> possibly modified decision
    ├─ scaleUp() / scaleDown() / no action
    ├─ PolicyExecutor::afterScaling(finalDecision)
    ├─ event(ScalingDecisionMade), event(SlaBreachPredicted) when at risk
    ├─ event(SlaBreached) / event(SlaRecovered) on state transitions
    └─ record lastScaleTime and lastScaleDirection (unless the decision was a hold)
```

`limitingFactor` on the final `CapacityCalculationResult` is `'fuse'` when the fuse is tripped;
otherwise `'config'` (capped by `workers.max`), `'strategy'` (raised by `workers.min`, or
unconstrained), or the capacity result's own `'cpu'` / `'memory'` / `'balanced'` /
`'system_metrics_unavailable'`.

`ScalingEngine::evaluateDemand()` — the cluster-leader path — runs the strategy, config bounds and
the fuse, but **not** the system-capacity constraint: local CPU and memory say nothing about
cluster-wide demand.

## Component map

```text
┌──────────────────────────────────────────────────────────┐
│ AutoscaleManager (queue:autoscale daemon)                │
│  loop: process worker output → enforce termination       │
│        deadlines → reap dead workers → evaluate & scale   │
│        → render → sleep (interval - executionTime)        │
└───────────────┬──────────────────────────┬───────────────┘
                │                          │
        ┌───────▼────────┐        ┌────────▼─────────┐
        │ ScalingEngine  │        │ Worker management │
        └───────┬────────┘        └────────┬─────────┘
                │                          │
   ┌────────────▼─────────────┐   ┌────────▼──────────────┐
   │ HybridStrategy           │   │ WorkerSpawner         │
   │  (ScalingStrategyContract)│   │ WorkerTerminator      │
   └────────────┬─────────────┘   │ WorkerPool            │
                │                  │ WorkerProcess         │
   ┌────────────▼─────────────┐   └───────────────────────┘
   │ LittlesLawCalculator     │
   │ BacklogDrainCalculator   │   ┌───────────────────────┐
   │ ArrivalRateEstimator     │   │ CapacityCalculator    │
   │ LinearRegressionForecaster│  │ ResourceEstimateResolver│
   │ TargetSmoother           │   │ FailureFuse           │
   └──────────────────────────┘   │ PolicyExecutor        │
                                  └───────────────────────┘
```

### Responsibilities

**AutoscaleManager** — the daemon. Owns the loop, signal handling (SIGTERM/SIGINT via
`SignalHandler`), the worker pool, breach state, cooldown state, output rendering, cluster cycles
and event dispatch.

**ScalingEngine** — turns a strategy recommendation into a `ScalingDecision` by applying capacity,
config bounds and the fuse. Holds no per-queue state.

**HybridStrategy** — the default `ScalingStrategyContract`. Also ships:
`BacklogOnlyStrategy`, `ConservativeStrategy`, `SimpleRateStrategy`.

**Calculators** — `LittlesLawCalculator` (L = lambda W), `BacklogDrainCalculator` (SLA protection),
`ArrivalRateEstimator` (sliding-window arrival rate plus forecast blend),
`LinearRegressionForecaster` (OLS projection), `TargetSmoother` (scale-down hysteresis),
`CapacityCalculator` (CPU/memory ceilings).

**Worker management** — `WorkerSpawner` starts processes, `WorkerTerminator` performs graceful
shutdown, `WorkerPool` tracks them, `WorkerProcess` wraps a Symfony `Process` with metadata.

## Resource capacity

`CapacityCalculator::calculateMaxWorkers(int $currentWorkers, ResourceEstimate $estimate)`, where
`$currentWorkers` is **all workers on this host across all queues**:

```text
availableCpuPercent    = max(limits.max_cpu_percent - currentCpuPercent, 0)
usableCores            = max(totalCores - limits.reserve_cpu_cores, 0)
availableCoreEquiv     = usableCores * (availableCpuPercent / 100)
maxWorkersByCpu        = currentWorkers + floor(availableCoreEquiv / max(cpuCoresPerWorker, 0.01))

availableMemoryPercent = max(limits.max_memory_percent - currentMemoryPercent, 0)
maxWorkersByMemory     = currentWorkers + floor(
                             totalMemoryMb * (availableMemoryPercent / 100)
                             / max(memoryMbPerWorker, 1.0))

finalMaxWorkers        = max(min(maxWorkersByCpu, maxWorkersByMemory), 0)
```

The `currentWorkers +` term is mandatory: the measured percentages already include the running
workers, so the division yields how many can be **added**.

Per-worker CPU and memory come from `ResourceEstimateResolver`, which resolves each dimension
independently as measured > per-queue `resources` config > global `limits.*` default.

System metrics are cached for 4 seconds because `SystemMetrics::cpuUsage(1.0)` blocks for a full
second. If `SystemMetrics::limits()` fails the calculator returns a fixed fallback of 5 workers with
`limitingFactor: 'system_metrics_unavailable'`.

Details and worked examples: [Resource Constraints](resource-constraints.md).

## The failure fuse

`FailureFuse` lives in `ScalingEngine`, not in a strategy, so every strategy — including custom ones
— is protected. A downstream outage is indistinguishable from load to every calculation above: jobs
fail, get released, the backlog grows. Without the fuse the autoscaler would answer an outage by
adding workers to it.

`FuseVerdict::workerCeiling(int $configuredMin)`:

| State | Ceiling |
|---|---|
| `Closed` | `null` — never constrains |
| `Open` | `workers.min` |
| `HalfOpen` | `max(1, workers.min)` |

It is a ceiling, not a target: the engine only lowers a target to meet it, never raises one. The
half-open floor of 1 matters for scale-to-zero queues — holding at zero would record no job
outcomes, so the fuse could never close again.

## Anti-flapping cooldown

`scaling.cooldown_seconds` (default 60) does **not** block all scaling. It blocks **direction
reversals only**.

The manager records `lastScaleTime` and `lastScaleDirection` per queue key. On each evaluation:

```text
currentDirection = up | down | hold

if lastDirection is set and the cooldown has fully elapsed:
    clear lastDirection            # a scale-up from minutes ago must not block a scale-down now

if currentDirection != 'hold'
   and lastDirection is set
   and currentDirection != lastDirection:

       isBreachScaleUp = currentDirection == 'up' and oldestJobAge >= sla.target_seconds

       if not isBreachScaleUp and still in cooldown:
           skip this queue this cycle
```

So:

- Scaling **further in the same direction** is always allowed, however recently it happened.
- A **reversal** within the window is suppressed.
- A **scale-up during an active SLA breach overrides the cooldown** — protecting the SLA outranks
  anti-flapping.
- A `hold` never records a direction and never blocks anything.

Groups use the same semantics with a `group:` prefixed key.

## Worker lifecycle

### Spawn

`WorkerSpawner` builds exactly this process:

```bash
{PHP_BINARY} artisan queue:work {connection} \
  --queue={queue} \
  --tries={workers.tries} \
  --max-time={workers.max_time_seconds} \
  --timeout={workers.timeout_seconds} \
  --sleep={workers.sleep_seconds}
```

The two time limits are separate settings and mean different things:
`workers.max_time_seconds` becomes `--max-time`, the worker process's lifetime before it is recycled;
`workers.timeout_seconds` becomes `--timeout`, how long a single job may run. Configuration rejects a
job timeout that is not shorter than the process lifetime. No `--memory` flag is passed.

Environment injected into the child: `LARAVEL_AUTOSCALE_WORKER=true`, `AUTOSCALE_MANAGER_ID`, and
`AUTOSCALE_WORKER_GROUP` for group workers. Group workers get a comma-separated
`--queue=q1,q2,q3` — strict left-to-right priority.

### Terminate

```text
WorkerTerminator::requestTermination(worker)
    posix_kill(pid, SIGTERM)
    mark deadline = now + workers.shutdown_timeout_seconds (default 30)

... manager loop calls enforceTerminationDeadlines() each tick ...

WorkerTerminator::forceKillIfExpired(worker)
    if deadline exceeded: posix_kill(pid, SIGKILL)
```

The synchronous `terminate()` variant does SIGTERM, waits out the timeout, then SIGKILLs.

## Extension points

### Custom strategies

```php
interface ScalingStrategyContract
{
    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int;

    public function getLastReason(): string;

    public function getLastPrediction(): ?float;
}
```

Register the class string:

```php
'strategy' => App\Scaling\TimeOfDayStrategy::class,
```

`AutoscaleConfiguration::strategyClass()` reads this as a **plain string**. An array form is not
supported and will break boot.

### Scaling policies

```php
interface ScalingPolicy
{
    /** Return null to keep the original decision. */
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision;

    public function afterScaling(ScalingDecision $decision): void;
}
```

`PolicyExecutor::beforeScaling()` chains them: a non-null return replaces the decision for every
subsequent policy. Exceptions in either hook are caught and logged to
`AutoscaleConfiguration::logChannel()` — a throwing policy does not abort scaling.

```php
'policies' => [
    ConservativeScaleDownPolicy::class,
    BreachNotificationPolicy::class,
],
```

`AutoscaleConfiguration::policyClasses()` filters to `is_string($policy) && class_exists($policy)`,
so a policy **instance** or closure placed in this array is silently dropped. Classes are resolved
via the container, so constructor injection works.

### Events

```php
Event::listen(ScalingDecisionMade::class, fn ($event) => /* $event->decision */);
Event::listen(WorkersScaled::class, fn ($event) => /* connection, queue, from, to, action, reason */);
Event::listen(SlaBreachPredicted::class, fn ($event) => /* $event->decision */);
Event::listen(SlaBreached::class, fn ($event) => /* connection, queue, oldestJobAge, slaTarget, pending, activeWorkers */);
Event::listen(FuseTripped::class, fn ($event) => /* connection, queue, failureRate, samples, ... */);
```

## Performance considerations

### Evaluation cadence

The loop interval comes from the command flag:

```bash
php artisan queue:autoscale --interval=5
```

The default is 5 seconds. Each cycle sleeps `max(0, interval - executionTime)`, so a slow cycle does
not compound. The value comes from `manager.evaluation_interval_seconds`; the `--interval` flag
overrides it for a single process, and an interval below one second is refused so the loop cannot
busy-spin against the metrics store.

Faster intervals react sooner and evaluate more often; slower intervals cost less and can miss short
spikes. Five to ten seconds suits most workloads.

### Per-cycle cost

- Strategy arithmetic is O(1) per queue, over an O(30) snapshot window.
- The one blocking cost is `SystemMetrics::cpuUsage(1.0)` — one second — cached for 4 seconds and
  therefore paid once per cycle, not once per queue.
- Percentile computation is a sort over the pickup samples in the window
  (`pickup_time.max_samples_per_queue`, default 1000).

### Worker spawn time

Process creation plus Laravel bootstrap is typically several hundred milliseconds. Two mechanisms
account for it: spawn compensation subtracts measured spawn latency (EMA) from the SLA budget, and
`workers.min` keeps always-ready capacity.

### Memory footprint

The default estimate is 128 MB per worker (`limits.worker_memory_mb_estimate`); override per queue
with the `resources` key when a queue's jobs are heavier. The manager process itself is small
compared with the pool it supervises.

## Design decisions

### Why a maximum of two calculations?

Each covers what the other cannot: Little's Law sizes for the flow arriving, backlog drain sizes for
the water already in the tank. Taking the maximum means a queue is provisioned for whichever
pressure is currently greater, and neither can mask the other. Over-provisioning is then bounded by
capacity, config and the fuse.

### Why an SLA rather than a worker count?

"Jobs are picked up within 30 seconds" is a statement about the business. "Run 12 workers" is an
implementation detail that stops being true the moment job duration changes.

### Why process-based workers?

Isolation (a leaking or crashing job takes down one process), control (individual SIGTERM/SIGKILL),
and familiarity — they are ordinary `queue:work` processes, visible in the process table and
manageable with standard tooling.

### Why does the fuse live in the engine?

Because it must apply to strategies this package has never seen. A custom strategy gets outage
protection without implementing anything.

## See also

- [Little's Law](littles-law.md) — the steady-state calculation
- [Backlog Drain](backlog-drain.md) — the SLA-protection calculation
- [Trend Prediction](trend-prediction.md) — forecasting inside the arrival-rate estimate
- [Resource Constraints](resource-constraints.md) — the capacity ceiling
- [Quick Start](../quickstart.md) — usage
