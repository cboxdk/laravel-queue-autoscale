---
title: "How It Works"
description: "What the manager does each cycle, and why it decided what it decided"
weight: 10
---

# How It Works

Queue Autoscale for Laravel uses a hybrid predictive algorithm to make scaling decisions.

## Overview

The default `HybridStrategy` computes **two** worker counts and takes the larger of them:

1. **Little's Law (steady state)** — `workers = arrival_rate × avg_job_time`
2. **Backlog drain (SLA protection)** — how many workers it takes to clear the current backlog before the oldest job breaches its SLA

```text
target = max(steadyState, backlogDrain)
```

Forecasting is **not** a third term. The forecaster feeds the *arrival rate* that goes into Little's Law: `ArrivalRateEstimator` blends the measured rate with the forecast according to the queue's `forecast.policy`, and the blended rate is used only if its confidence clears `scaling.min_arrival_rate_confidence` (not written into the published config file; defaults to 0.5). A prediction therefore raises the steady-state term rather than competing with it.

After the strategy produces a number, the engine applies host capacity, the configured bounds and the failure fuse — see [The decision flow](#3-decision-phase).

## The Evaluation Loop

### 1. Metrics Retrieval Phase

Every evaluation cycle — 5 seconds by default, set with `queue:autoscale --interval=` — the autoscaler:

```text
1. Retrieves all queues and metrics from laravel-queue-metrics
   └─ Single call: QueueMetrics::getAllQueuesWithMetrics()
      plus a targeted fetch for any configured queue or group member
      the metrics package has not discovered yet

2. Receives per-queue data (QueueMetricsData)
   ├─ Connection and queue name
   ├─ activeWorkers
   ├─ throughputPerMinute
   ├─ pending / scheduled / reserved / depth
   ├─ oldestJobAge
   ├─ avgDuration, failureRate, utilizationRate
   └─ driver and health stats

3. Resolves per-queue configuration via QueueConfiguration::fromConfig()
   └─ SLA target, percentile window, forecast policy, worker bounds, fuse thresholds
```

Excluded queues are dropped here; groups are evaluated as a single unit against aggregated member metrics.

**Package Separation:**
- **laravel-queue-metrics** does: Queue discovery, connection scanning, metrics collection
- **laravel-queue-autoscale** does: Consumes metrics, applies algorithms, manages workers

### 2. Calculation Phase

For each queue, `HybridStrategy` computes two candidate worker counts.

#### A. Little's Law (Steady State)

```text
steadyState = arrivalRate × avgJobTime
```

That is the whole formula — `LittlesLawCalculator::calculate()` returns exactly `$arrivalRate * $avgProcessingTime` (and `0.0` if either input is non-positive). The caller rounds up.

**Where the inputs come from:**

- `arrivalRate` — `ArrivalRateEstimator`, which blends the measured throughput with the forecast; it falls back to `throughputPerMinute / 60` when confidence is too low. If the queue has failures, a **retry-noise correction** subtracts an estimate of retried work (only when `failureRate > 5%`), because retries are indistinguishable from new arrivals.
- `avgJobTime` — the metrics package's average duration, accepted when it is between 0.01s and 600s; otherwise `scaling.fallback_job_time_seconds` (default 2.0).

**Example:** 10 jobs/sec arriving, 2 seconds per job → `10 × 2 = 20` workers.

**When it dominates:** stable traffic with no meaningful backlog.

#### B. Backlog Drain (SLA Protection)

The drain calculator is gated and then amplified. Simplified:

```text
slaProgress = min(oldestJobAge / effectiveSla, 1.5)

if slaProgress < scaling.breach_threshold  →  0 workers

timeUntilBreach = effectiveSla - oldestJobAge
baseWorkers     = backlog / max(timeUntilBreach / avgJobTime, 1.0)

drain = baseWorkers × aggressivenessMultiplier(slaProgress)
```

`scaling.breach_threshold` defaults to **0.5** — half of the SLA window, not 80%.

The aggressiveness multiplier is what makes late backlogs scale hard:

```text
multiplier = min(1.0 + 8.0 × (slaProgress - 0.5)², 5.0)
```

| SLA consumed | Multiplier |
|---|---|
| 50% | 1.0× |
| 80% | 1.72× |
| 100% | 3.0× |
| 150% (the cap on `slaProgress`) | 5.0× (capped) |

There is no "jump straight to `workers.max` when breaching" branch — the ramp above is the whole mechanism.

Two more details:

- `effectiveSla` is `max(1.0, sla.target_seconds - spawnLatency)` when spawn compensation is enabled, so the autoscaler starts draining early enough to absorb the time a new worker takes to come online.
- When the oldest-job age is unavailable (`0`) but a backlog exists, the calculator takes a simpler path: `backlog / max(effectiveSla / avgJobTime, 1.0)`, with no multiplier.

**Example:** 100 pending jobs, oldest 25s old, 30s SLA, 2s per job.
`slaProgress = 25/30 = 0.83` (above the 0.5 gate). `timeUntilBreach = 5s`, so `baseWorkers = 100 / (5/2) = 40`. Multiplier at 0.83 is `1 + 8 × 0.11 = 1.88`, giving **75 workers** before clamping.

#### C. Which age signal is used

The drain calculator's "oldest job age" is a **p95 pickup time** when there are enough samples: the strategy collects `pickup_seconds` samples across the queue (or all member queues of a group) over `sla.window_seconds` and asks the percentile calculator for `sla.percentile`, requiring `sla.min_samples`. If that returns null, it falls back to the raw `oldest_job_age` from the metrics package.

#### D. Saturation boost and smoothing

Two final adjustments inside the strategy:

- **Saturation boost:** if worker utilization is at or above 90% and the computed target is not already above the active worker count, the target becomes `activeWorkers + 1`. This keeps a fully-saturated queue creeping upward even when the maths says "you have enough".
- **Smoothing:** `TargetSmoother` applies hysteresis. Scale-ups always pass through. A scale-down is limited to one worker per cycle only while the throughput history is statistically stable (coefficient of variation below 5%); when throughput is volatile the full scale-down is allowed.

Both are followed by a re-clamp to `[workers.min, workers.max]`.

### 3. Decision Phase

`ScalingEngine::evaluate()` runs these steps in order:

```text
1. target = strategy->calculateTargetWorkers(metrics, config)

2. Host capacity
   ├─ capacity = CapacityCalculator::calculateMaxWorkers(totalPoolWorkers, estimate)
   ├─ availableForThisQueue = max(capacity.finalMaxWorkers - otherQueuesWorkers, 0)
   └─ target = min(target, availableForThisQueue)

3. Config bounds
   └─ target = min(max(target, workers.min), workers.max)

4. Failure fuse
   └─ if tripped: target = min(target, fuseCeiling)   // holds at workers.min

5. Build ScalingDecision (connection, queue, currentWorkers, target, reason,
   predictedPickupTime, slaTarget, capacity, spawnCompensation)
```

The `limitingFactor` on the returned capacity DTO is `fuse` when the fuse is tripped, otherwise one of `config`, `strategy`, `cpu`, `memory`, `balanced`, or `system_metrics_unavailable`.

In cluster mode the leader uses `evaluateDemand()` instead, which applies the strategy, the config bounds and the fuse but **not** local host capacity — per-host capacity is enforced when the recommendation is distributed and executed.

Anti-flapping cooldown is not part of the engine. It is applied by the manager after the decision — see [Cooldown Periods](#cooldown-periods).

### 4. Execution Phase

```text
1. PolicyExecutor::beforeScaling(decision)
   └─ each configured policy may return a replacement decision;
      the next policy sees the modified one

2. Scale workers
   ├─ target > current: spawn
   ├─ target < current: terminate
   └─ target = current: nothing

3. PolicyExecutor::afterScaling(finalDecision)

4. Broadcast events
   ├─ ScalingDecisionMade (every cycle)
   ├─ SlaBreachPredicted (when predictedPickupTime > slaTarget)
   ├─ WorkersScaled (dispatched by the spawn/terminate step)
   └─ SlaBreached / SlaRecovered (on state transitions)
```

Note that `ScalingDecisionMade` carries the **post-policy** decision, and that it is dispatched *after* the scaling action, not before.

Policies never see the queue metrics and never run before the strategy — they operate purely on the finished `ScalingDecision`. An exception thrown by a policy is caught and logged; it does not abort scaling.

## Example Scenarios

### Scenario 1: Steady Traffic

```text
Rate: 10 jobs/sec, avg job 2s, backlog ~0, SLA 30s

steadyState = 10 × 2                       = 20 workers
drain       = 0 (oldest job age well under 0.5 × 30s)
target      = max(20, 0)                   = 20 workers
```

The drain term contributes nothing until the oldest job has consumed half the SLA window.

### Scenario 2: Sudden Spike

```text
T+0   Rate 10/s, backlog 0, workers 20

T+60  Campaign starts. Rate 50/s, backlog 200, oldest job 15s (SLA 30s)
      steadyState = 50 × 2                 = 100
      slaProgress = 15/30 = 0.5            → multiplier 1.0
      drain       = 200 / ((30-15)/2) × 1.0 = 27
      target      = max(100, 27)           = 100

T+70  Backlog still 200, oldest job now 27s
      slaProgress = 27/30 = 0.9            → multiplier 1 + 8×0.16 = 2.28
      drain       = 200 / ((30-27)/2) × 2.28 ≈ 304
      target      = max(100, 304)          = 304  → clamped to workers.max
```

The multiplier is why the drain term overtakes steady state as jobs age. Both numbers are then clamped by host capacity and `workers.max`, so the queue reaches its ceiling rather than 304 workers.

### Scenario 3: Traffic Decrease

```text
T+0   Rate 20/s, workers 40

T+5m  Rate drops to 10/s
      steadyState = 10 × 2 = 20, drain = 0, target = 20
      ConservativeScaleDownPolicy caps the removal at
      max(1, ceil(40 × 0.25)) = 10  → 40 → 30 this cycle

T+5m5s Next cycle: 30 → 23, then 23 → 18, then 18 → 20 (clamped up by the target)

T+later Rate 2/s → target 4, then down to workers.min
```

Cooldown does **not** slow this down: consecutive scale-downs are all in the same direction, and the cooldown only blocks direction *reversals*. The gradual shape comes from `ConservativeScaleDownPolicy` and the strategy's own smoothing.

## SLA Target Behavior

### How SLA Targets Work

Instead of saying "I want 10 workers", you say:
```php
'sla' => ['target_seconds' => 30]
```

This means: **"Jobs should start processing within 30 seconds of being queued"**

The autoscaler calculates how many workers are needed to meet this target.

### Breach Prevention

The autoscaler is **proactive** about SLA targets:

```text
SLA target:       30 seconds
Breach threshold: 0.5 (15 seconds) — scaling.breach_threshold, configurable

┌──────────────────────────────────────────────┐
│  0s          15s              30s      45s   │
│  ├────────────┼────────────────┼────────┤    │
│  Safe      Drain starts     Breach   1.5×    │
│            (multiplier 1×)  (3×)     (cap 5×)│
└──────────────────────────────────────────────┘

At 15s the backlog-drain term starts contributing.
Its multiplier grows as the job ages, reaching 3× at the
SLA target and capping at 5× once the job is 1.5× the target.
```

### Multiple SLA Tiers

You can configure different SLAs per queue:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;

'sla_defaults' => BalancedProfile::class,        // 30s SLA default

'queues' => [
    'critical' => CriticalProfile::class,         // 10s SLA
    'emails'   => ['sla' => ['target_seconds' => 300]],  // 5 min override
],
```

## Understanding SLA Timing

SLA targets define the **maximum acceptable pickup time** — the time between a job being dispatched and a worker starting to process it. In practice, most jobs are picked up far faster than the SLA target. A 30-second SLA does not mean jobs take 30 seconds — it means the autoscaler guarantees they start within 30 seconds, with the vast majority processing near-instantly.

However, there are hard timing floors imposed by Laravel's queue worker internals that every operator should understand.

### Floor 1: Worker Poll Loop (~3-5 seconds)

Even with a running, idle worker, job pickup is not instant. Laravel's `queue:work` command operates on a sleep/poll cycle:

```text
Worker idle loop:
├─ Poll queue for next job
├─ No job found
├─ Sleep for sleep_seconds (default: 3s)
├─ Poll again
└─ Job found → start processing
```

The worst-case pickup time for an idle worker is roughly `sleep_seconds` plus a small overhead for the poll itself. With the default `sleep_seconds: 3`, this means **~3-5 seconds** in the worst case.

**This means SLA targets below 5 seconds will always produce flaky breach events**, regardless of how many workers are running. This is expected behaviour — it reflects the fundamental polling model of Laravel's queue worker, not a limitation of the autoscaler.

> **Tip:** `CriticalProfile` sets `sleep_seconds: 1` to minimize this floor, but even then sub-5s SLA targets are unreliable due to poll overhead and job deserialization time.

### Floor 2: Scale-from-Zero Latency (~8-12 seconds)

Profiles with `workers.min = 0` (`BurstyProfile`, `BackgroundProfile`) can scale the queue to zero workers during idle periods. When a new job arrives, the autoscaler must:

```text
Scale-from-zero timeline:
├─ Job dispatched to empty queue
├─ Wait for next evaluation cycle (up to evaluation_interval: 5s)
├─ Autoscaler detects pending job
├─ Spawn worker process (1-2s startup)
├─ Worker enters poll loop
├─ Worker picks up job (up to sleep_seconds: 3s)
└─ Total: ~8-12 seconds typical
```

This is a conscious trade-off: zero idle cost in exchange for slower first-job pickup after an idle period. If this latency is unacceptable for a queue, set `workers.min >= 1`.

### Practical Guidelines

| SLA Target | Recommendation |
|---|---|
| **< 5 seconds** | Not recommended. Will produce flaky breaches regardless of configuration. Requires infrastructure outside this package's scope (e.g. synchronous processing, always-on consumers). |
| **5-10 seconds** | Requires `workers.min >= 1` and low `sleep_seconds` (1-2). Use `CriticalProfile` or a custom profile. Scale-from-zero is not viable at this SLA. |
| **10-30 seconds** | The sweet spot for most user-facing queues. `workers.min >= 1` recommended. Outliers may approach the SLA target; the vast majority of jobs process near-instantly. |
| **30-300 seconds** | Comfortable range. Scale-from-zero (`workers.min = 0`) is viable. The occasional 8-12s cold start is well within budget. |

### Why This Matters for Profiles

The shipped profiles are designed with these floors in mind:

- **CriticalProfile** (10s SLA, min=5): `sleep_seconds: 1` minimizes poll latency. Five always-on workers eliminate scale-from-zero entirely.
- **BurstyProfile** (60s SLA, min=0): 60-second SLA comfortably absorbs the ~8-12s scale-from-zero floor.
- **BackgroundProfile** (300s SLA, min=0): 5-minute SLA makes the cold start negligible.

If you create a custom profile with both a tight SLA (< 10s) and `workers.min = 0`, the autoscaler will honour it — but expect frequent breach events during scale-from-zero transitions.

## Worker Lifecycle

### Spawning Workers

`WorkerSpawner` starts a Symfony `Process` running exactly:

```bash
{PHP_BINARY} artisan queue:work {connection} \
    --queue={queue} \
    --tries={workers.tries} \
    --max-time={workers.max_time_seconds} \
    --timeout={workers.timeout_seconds} \
    --sleep={workers.sleep_seconds}
```

Every value comes from the queue's own profile; there is no global `workers` block. The two time
limits are separate settings: `max_time_seconds` becomes `--max-time` and recycles the worker
process, `timeout_seconds` becomes `--timeout` and bounds a single job. The spawner passes no
`--memory` flag.

Three environment variables are injected into each worker, and nothing else:

```text
LARAVEL_AUTOSCALE_WORKER=true
AUTOSCALE_MANAGER_ID=<manager id>
AUTOSCALE_WORKER_GROUP=<group name>   # group workers only
```

`WorkerPool` then tracks the `WorkerProcess`: PID, connection, queue (or comma-separated queue list for a group worker), spawn time and group.

### Monitoring Workers

```text
Every evaluation cycle:
1. the manager's inline liveness check::isHealthy() → is the tracked PID still alive?
   (posix_kill($pid, 0) — a liveness probe, nothing more)
2. Dead workers are removed from the pool
3. If the target still calls for them, replacements are spawned
```

The health check does not inspect memory, CPU or responsiveness, and there is no worker-health event. A respawn surfaces as a `WorkersScaled` event like any other scale-up.

### Terminating Workers

```text
When scaling down:
1. Select workers to terminate
2. Send SIGTERM (graceful shutdown)
3. Wait up to workers.shutdown_timeout_seconds (default 30)
4. Send SIGKILL if the process is still running
5. Remove from the worker pool
```

**Why graceful shutdown matters:**
- Allows the in-flight job to complete
- Prevents job failures
- Maintains data integrity

## Resource Constraints

### System Capacity

`CapacityCalculator` reads host CPU and memory and derives a ceiling as **headroom on top of the workers already running**:

```text
availableCpuPercent = max(limits.max_cpu_percent - currentCpuPercent, 0)
usableCores         = max(totalCores - limits.reserve_cpu_cores, 0)
maxByCpu            = currentWorkers
                    + floor(usableCores × (availableCpuPercent/100) / cpuCoresPerWorker)

availableMemPercent = max(limits.max_memory_percent - currentMemoryPercent, 0)
maxByMemory         = currentWorkers
                    + floor(totalMemoryMb × (availableMemPercent/100) / memoryMbPerWorker)

finalMaxWorkers     = max(min(maxByCpu, maxByMemory), 0)
```

The `currentWorkers +` term is essential: the CPU and memory those workers are already consuming is reflected in `currentCpuPercent` / `currentMemoryPercent`, so the calculation adds headroom to what exists rather than recomputing from zero.

System metrics are cached for 4 seconds because reading system metrics is not free. If the read fails entirely, a conservative fixed fallback is returned with `limitingFactor: 'system_metrics_unavailable'`.

### Configuration Limits

```php
'workers' => [
    'min' => 1,   // Always maintain at least 1
    'max' => 10,  // Never exceed 10
],
```

### Cooldown Periods

```php
'scaling' => ['cooldown_seconds' => 60],  // global, top-level
```

**Purpose:** prevent flapping. It blocks **direction reversals only**, per connection+queue:

```text
10:00:00  scale up   5 → 10        (direction recorded: up)
10:00:05  scale up  10 → 14        allowed — same direction
10:00:20  want to scale down       suppressed — reversal inside the 60s window
10:01:05  want to scale down       allowed — window elapsed, direction cleared
```

Scaling up during an **active SLA breach** bypasses the cooldown entirely: protecting the SLA outranks anti-flapping.

## Metrics and Visibility

### What Gets Logged

Run the manager with `-v`/`-vv`/`-vvv` to see per-cycle decisions with their reasoning and the limiting factor. The default (non-verbose) output is quiet and reports scaling actions only.

Errors during a cycle are logged as `Autoscale evaluation failed` to the channel in `manager.log_channel`, and the manager continues to the next cycle.

### What Events Fire

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\SlaBreachPredicted;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Illuminate\Support\Facades\Event;

// Every cycle, after policies and after the scaling action
Event::listen(function (ScalingDecisionMade $event) {
    $event->decision->targetWorkers;
});

// Every cycle while predictedPickupTime > slaTarget
Event::listen(function (SlaBreachPredicted $event) {
    $event->decision->predictedPickupTime;
    $event->decision->slaTarget;
});

// Only when workers actually spawn or terminate
Event::listen(function (WorkersScaled $event) {
    $event->from;      // 5
    $event->to;        // 8
    $event->action;    // 'up' | 'down'
});
```

See [Event Handling](event-handling.md) for the complete catalogue.

### What Metrics Are Tracked

`QueueMetricsData`, supplied by `laravel-queue-metrics`, is what the strategy consumes:

- `pending`, `scheduled`, `reserved`, `depth`
- `oldestJobAge` (seconds)
- `throughputPerMinute`
- `avgDuration` (milliseconds as reported; the manager converts it to seconds before handing it to the strategy)
- `failureRate`, `utilizationRate`
- `activeWorkers`

Pickup-time samples used for the p95 signal are recorded separately by this package, in the configured `pickup_time.store`.

### Metrics Package Setup

All metrics are collected by the `laravel-queue-metrics` package. Ensure it's properly configured:

**Storage Setup:**

```env
# Redis (recommended for autoscaling)
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default

# OR Database (for persistent metrics)
QUEUE_METRICS_STORAGE=database
```

**Installation:**

```bash
composer require cboxdk/laravel-queue-metrics
php artisan vendor:publish --tag=queue-metrics-config
```

**Learn more:** [Metrics Package Documentation](https://github.com/cboxdk/laravel-queue-metrics)

## Common Questions

### Q: Why did workers scale up when the queue was empty?

**A**: The forecaster raised the estimated arrival rate above the measured one, so Little's Law asked for more workers before the jobs arrived. This is **proactive scaling**. Swap to a less aggressive `forecast.policy` if you would rather not pay for it.

### Q: Why didn't workers scale down immediately?

**A**: Most likely `ConservativeScaleDownPolicy` (the default) capping removal at 25% of the current count per cycle, or the strategy's own smoothing while throughput is stable. Cooldown only blocks it if the previous action was a scale-**up** inside the cooldown window.

### Q: Why are there more workers than jobs?

**A**: Workers are scaled for **rate**, not backlog. A high arrival rate needs many workers even when the backlog is near zero.

### Q: Can I force faster scaling?

**A**: Lower the daemon's `--interval`, lower `scaling.breach_threshold`, or raise `workers.min`. Reducing `scaling.cooldown_seconds` only helps if direction reversals are what is blocking you.

### Q: What happens if the system runs out of resources?

**A**: `CapacityCalculator` clamps the target to what CPU and memory allow, and the decision reports `limitingFactor` as `cpu`, `memory` or `balanced`.

## Next Steps

- [Configuration Guide](configuration.md) - Configure SLA targets and limits
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Write your own scaling logic
- [Monitoring Guide](monitoring.md) - Track autoscaler performance
- [Algorithm Details](../algorithms/architecture.md) - Deep dive into math
