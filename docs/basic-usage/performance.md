---
title: "Performance Tuning"
description: "Tuning the evaluation interval, cooldown, worker bounds and resource limits for responsiveness and stability"
weight: 14
---

# Performance Tuning

Tune Queue Autoscale for responsiveness, stability and resource efficiency.

## Table of Contents
- [Overview](#overview)
- [Configuration Tuning](#configuration-tuning)
- [Strategy Selection](#strategy-selection)
- [Resource Efficiency](#resource-efficiency)
- [Scaling Patterns](#scaling-patterns)
- [Troubleshooting Performance](#troubleshooting-performance)

## Overview

Performance tuning focuses on:
- **Response Time**: How quickly autoscaling reacts to load changes
- **Resource Efficiency**: Minimizing wasted capacity
- **SLA Compliance**: Meeting pickup targets consistently

### Performance Metrics

**Key Indicators:**
- SLA compliance (how often `SlaBreached` fires)
- Average worker utilization
- Scaling latency (time to adjust workers)
- Oscillation rate (direction reversals on `WorkersScaled`)

## Configuration Tuning

### Evaluation Interval

The evaluation interval controls how often scaling decisions are made. **It is set by the `--interval` flag on the daemon, and nowhere else:**

```bash
php artisan queue:autoscale --interval=5   # 5 is the default
```

> `queue-autoscale.manager.evaluation_interval_seconds` exists in the published config and is documented as 5 seconds, but nothing in the package reads it — `AutoscaleConfiguration::evaluationIntervalSeconds()` has no callers. Changing that key has **no effect**. Set the interval on the command line in your supervisor/systemd unit.

**Faster intervals (2-5s):**
- Quicker response to traffic spikes
- Better SLA compliance for burst traffic
- More manager CPU overhead (each cycle samples system metrics and queue metrics)
- More opportunities to oscillate

**Slower intervals (15-60s):**
- Lower manager overhead
- More stable
- Slower reaction to traffic changes; a tight SLA may breach before the next cycle

The interval is a floor on how fast the autoscaler can react, and it is part of the [scale-from-zero latency budget](how-it-works.md#understanding-sla-timing). Keep it well below your tightest `sla.target_seconds`.

```ini
; supervisor
command=php /path/to/artisan queue:autoscale --interval=5
```

### Cooldown Period

`scaling.cooldown_seconds` (a top-level global setting, default 60) is **anti-flapping only**. It does not throttle scaling in general:

```php
'scaling' => ['cooldown_seconds' => 60],
```

What it actually does, per connection+queue key:

- The manager records the time and direction of the last scaling action.
- Scaling **in the same direction** is always allowed — up, up, up on consecutive cycles is fine.
- A **direction reversal** within the cooldown window is suppressed, logged as `Anti-flapping: cannot reverse direction during cooldown`.
- Once the window fully elapses, the stored direction is cleared and the next action in either direction is free.
- **An SLA breach overrides it for scale-up.** A scale-up during an active breach bypasses the cooldown entirely, so protecting the SLA always wins over anti-flapping.

**Shorter cooldown (30-45s):** faster reversals, better for genuinely variable traffic, more oscillation risk.

**Longer cooldown (90-180s):** very stable, but the queue holds an over- or under-provisioned count for longer after the load turns.

### Worker Limits

Per-queue bounds live under the `workers` key — set via profile or override:

```php
'queues' => [
    'payments' => ['workers' => ['min' => 5, 'max' => 50]],  // Always warm
    'emails'   => ['workers' => ['min' => 0, 'max' => 20]],  // Can scale to zero
],
```

`workers.max` is a hard ceiling applied after the strategy and after the host-capacity constraint. When it is the binding constraint, the decision reports `limitingFactor: 'config'` — see [Monitoring → Limiting factor](monitoring.md#limiting-factor).

### SLA Target

`sla.target_seconds` drives scaling behavior. Change it via a profile or a per-queue override.

```php
'queues' => [
    'payments' => ['sla' => ['target_seconds' => 10]],
    'reports'  => ['sla' => ['target_seconds' => 300]],
],
```

**Aggressive SLA (5-15s):** very responsive, but higher cost and potential overprovisioning. Use `CriticalProfile` for the full bundle.

**Moderate SLA (30-90s):** balanced cost and performance — `BalancedProfile`.

**Relaxed SLA (120-300s):** cost-optimised — `BackgroundProfile`.

See [Workload Profiles](workload-profiles.md) for the full comparison.

## Strategy Selection

`queue-autoscale.strategy` is a **plain class string**, read by `AutoscaleConfiguration::strategyClass()`:

```php
'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy::class,
```

There is no options array and no per-strategy tuning keys. Writing `'strategy' => ['class' => ..., 'options' => [...]]` is not understood and will break the container binding at boot.

Four strategies ship with the package:

| Strategy | Behaviour |
|---|---|
| `HybridStrategy` (default) | `max(steady-state, backlog-drain)`, plus arrival-rate forecasting, retry-noise correction and a saturation boost |
| `BacklogOnlyStrategy` | Backlog-drain only — ignores arrival rate and forecasting |
| `ConservativeStrategy` | Little's Law + backlog-drain with a fixed 25% safety buffer and its own hard-coded 0.75 breach threshold |
| `SimpleRateStrategy` | Little's Law only, no backlog term and no prediction |

`ConservativeStrategy`'s buffer and threshold are class constants, not config — they do not read `scaling.breach_threshold`.

Two global keys tune the shipped algorithm itself:

```php
'scaling' => [
    // Job-time estimate used when metrics have no usable average yet.
    'fallback_job_time_seconds' => env('QUEUE_AUTOSCALE_FALLBACK_JOB_TIME', 2.0),

    // Fraction of the SLA the oldest job must have consumed before the
    // backlog-drain calculator contributes anything at all. Default 0.5.
    'breach_threshold' => 0.5,
],
```

`breach_threshold` is a **ratio, not a percentage**: at the default `0.5`, backlog-drain stays silent until the oldest job has consumed half of the SLA target, then ramps its aggressiveness multiplier as the job ages. Lower it (e.g. `0.35`) to start draining earlier at the cost of more scale-up churn; raise it to react later and hold a leaner pool.

To replace the algorithm entirely, see [Custom Strategies](../advanced-usage/custom-strategies.md).

## Resource Efficiency

### Worker configuration

Per-worker runtime knobs live under the `workers` key of a queue config:

```php
'queues' => [
    'exports' => [
        'workers' => [
            'timeout_seconds' => 300,  // --max-time= on queue:work
            'sleep_seconds' => 3,      // --sleep= on queue:work
            'tries' => 3,              // --tries= on queue:work
        ],
    ],
],
```

Note that `timeout_seconds` maps to `--max-time` (total worker lifetime before it exits and is respawned), **not** to `--timeout` (per-job limit). The spawner never passes `--timeout` or `--memory`; set those in `php.ini` or in your own worker supervision if you need them.

**Tuning `timeout_seconds`** (how long a worker is kept alive before recycling). Look at recent job durations in your metrics store and set it comfortably above the longest job you expect a worker to be mid-way through when it recycles.

**Tuning `sleep_seconds`** (how long a worker sleeps when the queue is empty). Higher-frequency queues benefit from 1–2s; background queues save CPU with 5–10s.

### System resource limits

The global `limits` section protects the host from runaway spawning:

```php
'limits' => [
    'max_cpu_percent' => 85,            // Host CPU the autoscaler is allowed to drive toward
    'max_memory_percent' => 85,         // Same for memory
    'worker_memory_mb_estimate' => 128, // Assumed memory per worker (fallback)
    'worker_cpu_core_estimate' => 0.2,  // Assumed CPU cores per worker (fallback)
    'reserve_cpu_cores' => 0.2,         // Cores kept for OS/other services
],
```

**How the worker ceiling is derived** (see [Resource Constraints](../algorithms/resource-constraints.md) for the full math). Note that both formulas are expressed as *headroom on top of the workers already running* — the `$currentWorkers +` term is not optional:

```php
$availableCpuPercent = max($limits['max_cpu_percent'] - $currentCpuPercent, 0);
$usableCores = max($totalCores - $limits['reserve_cpu_cores'], 0);
$availableCoreEquivalents = $usableCores * ($availableCpuPercent / 100);

$maxByCpu = $currentWorkers + floor(
    $availableCoreEquivalents / max($cpuCoresPerWorker, 0.01)
);

$availableMemoryPercent = max($limits['max_memory_percent'] - $currentMemoryPercent, 0);

$maxByMemory = $currentWorkers + floor(
    $totalMemoryMb * ($availableMemoryPercent / 100) / max($memoryMbPerWorker, 1.0)
);

$hostCeiling = max(min($maxByCpu, $maxByMemory), 0);
```

`$cpuCoresPerWorker` and `$memoryMbPerWorker` default to `limits.worker_cpu_core_estimate` and `limits.worker_memory_mb_estimate`, and are replaced by measured per-queue values once enough samples exist (or by a per-queue `resources` override).

The host ceiling is then divided among queues: each queue's share is `hostCeiling - (workers running for other queues)`, and the per-queue `workers.max` is applied on top of that.

System metrics are cached for 4 seconds inside the capacity calculator, because sampling CPU blocks for a second. If the system-metrics read fails entirely, the calculator falls back to a conservative fixed ceiling and reports `limitingFactor: 'system_metrics_unavailable'`.

### Queue prioritisation

Route jobs to appropriate queues:

```php
// High priority: tight SLA, always warm
dispatch(new CriticalJob())->onQueue('critical');

// Standard
dispatch(new StandardJob())->onQueue('default');

// Low priority
dispatch(new ReportJob())->onQueue('background');
```

And pick a profile per tier:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;

'queues' => [
    'critical'   => CriticalProfile::class,    // 10s SLA, 5-50 workers
    'default'    => BalancedProfile::class,    // 30s SLA, 1-10 workers
    'background' => BackgroundProfile::class,  // 300s SLA, 0-5 workers
],
```

## Scaling Patterns

There is no runtime API for overriding a queue's bounds — the facade exposes exactly two methods, `cluster()` and `clusterMetrics()`, both read-only. Everything below is expressed in config or in a policy.

### Pattern 1: Predictable Daily Traffic

The config file is plain PHP, so a business-hours swap can be expressed directly. It is evaluated when the manager boots, so pair it with a scheduled restart at each boundary:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

$isBusinessHours = now()->isWeekday() && now()->hour >= 9 && now()->hour < 17;

'queues' => [
    'exports' => $isBusinessHours ? CriticalProfile::class : BackgroundProfile::class,
],
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:autoscale:restart')->weekdays()->at('08:55');
Schedule::command('queue:autoscale:restart')->weekdays()->at('17:00');
```

For something that reacts without a restart, write a policy instead — it runs on every decision:

```php
namespace App\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class BusinessHoursFloorPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        $isBusinessHours = now()->isWeekday() && now()->hour >= 9 && now()->hour < 17;

        if (! $isBusinessHours || $decision->targetWorkers >= 10) {
            return null;
        }

        return new ScalingDecision(
            connection: $decision->connection,
            queue: $decision->queue,
            currentWorkers: $decision->currentWorkers,
            targetWorkers: 10,
            reason: 'BusinessHoursFloorPolicy: enforcing a floor of 10 workers 09:00-17:00',
            predictedPickupTime: $decision->predictedPickupTime,
            slaTarget: $decision->slaTarget,
            capacity: $decision->capacity,
            spawnCompensation: $decision->spawnCompensation,
        );
    }

    public function afterScaling(ScalingDecision $decision): void {}
}
```

A policy floor is applied **after** `workers.max`, so keep it at or below the queue's ceiling.

### Pattern 2: Gradual Ramp-Down

Scale-down damping is a policy concern, and the package ships one: `ConservativeScaleDownPolicy` limits each cycle's removal to `max(1, ceil(currentWorkers * 0.25))` — 25% of the current count, at least one worker. It is enabled by default.

The shipped `HybridStrategy` also applies its own hysteresis via `TargetSmoother`: when the recent throughput history is statistically stable (coefficient of variation below 5%), scale-down is limited to one worker per cycle before the decision even reaches the policies. Volatile throughput bypasses the smoother entirely.

For a different shape, write your own policy — see [Scaling Policies](scaling-policies.md).

### Pattern 3: Absorbing Bursts Without Spawning

Spawn latency is unavoidable when a queue has to grow from cold. Two ways to avoid paying it:

- Raise `workers.min` so a warm floor is always present.
- Put correlated queues in a [worker group](queue-topology.md#worker-groups) so idle workers on one member immediately pick up a burst on another.

## Troubleshooting Performance

### Issue: Slow Scaling Response

**Symptoms:**
- Jobs pile up before workers scale
- Slow reaction to traffic spikes

**Diagnosis:** run the manager in `-vv` mode and watch the time between evaluation cycles and the `current → target` transitions. If several cycles pass with `current < target` and no spawn, the cooldown or a policy is blocking.

**Solutions:**
1. Reduce the daemon's `--interval` (default 5s). This is the only place the interval is set — the config key has no effect.
2. Reduce `scaling.cooldown_seconds` (default 60s) if the block is a direction reversal
3. Lower `scaling.breach_threshold` (default 0.5) so backlog-drain engages earlier in the SLA window
4. Swap to a profile with a more aggressive forecast policy (`CriticalProfile` or `BurstyProfile`)
5. Raise `workers.min` so cold-start latency is not a factor

### Issue: Worker Oscillation

**Symptoms:**
- Worker count rapidly changing
- Inefficient resource usage

**Diagnosis:** run the manager in `-vv` mode during the oscillation window. The log shows every decision with reasoning. If you see `scaled UP` and `scaled DOWN` for the same queue within one cooldown window, anti-flapping didn't help — the strategy itself is oscillating.

Alternatively listen on the `WorkersScaled` event and count direction reversals per queue per minute (see [Cookbook → Alert via Log](../cookbook/alert-via-log.md)).

**Solutions:**
1. Increase `scaling.cooldown_seconds`
2. Use a profile with a higher `sla.min_samples` (larger p95 window smooths noise)
3. Consider a custom policy that rejects small scale-down steps — see [ConservativeScaleDownPolicy](scaling-policies.md)

### Issue: Persistently High Worker Count

**Symptoms:**
- Worker count consistently at or near `workers.max`

**Diagnosis:** listen on the `ScalingDecisionMade` event and record how often `$event->decision->capacity->limitingFactor` is `'config'` — that means the configured max is the bottleneck, not host capacity or demand. A single log listener with a counter suffices.

**Solutions:**
1. Optimise job performance — faster jobs need fewer workers
2. Relax the SLA: swap to `BalancedProfile` or `BackgroundProfile`, or raise `sla.target_seconds`
3. Lower `workers.max` if the extra workers are not measurably helping the SLA
4. Use queue prioritisation (critical vs. best-effort queues on separate profiles)
5. Batch similar small jobs together

### Issue: SLA Breaches

**Symptoms:**
- Jobs waiting longer than target
- `SlaBreached` events firing

**Diagnosis:** listen on `SlaBreached` / `SlaRecovered` and aggregate breach durations. Or run `php artisan queue:autoscale:debug --queue=X` during a breach to see pickup-time percentiles and backlog.

**Solutions:**
1. Increase `workers.max` (you may be capacity-constrained)
2. Increase `workers.min` (cold-start latency at scale-up may be the culprit)
3. Tighten `sla.target_seconds` — counter-intuitive, but a stricter SLA triggers earlier backlog-drain scaling
4. Check for stuck workers via `ps aux | grep queue:work` — a hung worker consumes a slot without draining
5. Lower `limits.max_cpu_percent` if the host is starving workers

## Tuning Method

Change one knob at a time and measure against the signals in [Monitoring](monitoring.md):

1. Baseline for a full traffic cycle (typically a week) with the shipped defaults.
2. Identify the binding constraint from `capacity->limitingFactor` on `ScalingDecisionMade`.
3. Change the one setting that addresses it (interval, cooldown, `breach_threshold`, `workers.min`/`max`, or the profile).
4. Re-measure `SlaBreached` frequency and `WorkersScaled` direction reversals.
5. Repeat.

## See Also

- [Configuration](configuration.md) - Detailed configuration options
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Custom strategy development
- [Monitoring](monitoring.md) - Performance monitoring
- [How It Works](how-it-works.md) - Algorithm explanation
