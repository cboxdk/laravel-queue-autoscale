---
title: "Backlog Drain"
description: "How the SLA-protection calculator sizes workers from backlog, job age and a progressive aggressiveness multiplier"
weight: 53
---

# Backlog Drain

`BacklogDrainCalculator` produces the **SLA-protection** half of the hybrid strategy: the number of
workers needed to clear the existing backlog before the oldest job breaches
`sla.target_seconds`.

Where [Little's Law](littles-law.md) sizes for the incoming flow, this calculator sizes for the
water already in the tank — and it gets progressively more aggressive as the deadline approaches.

## Signature

```php
public function calculateRequiredWorkers(
    int $backlog,
    int $oldestJobAge,
    int $slaTarget,
    float $avgJobTime,
    float $breachThreshold,
    ?float $effectiveSlaSeconds = null,
): float
```

Like Little's Law it returns a **float**; `HybridStrategy` rounds up once and clamps to
`workers.min`/`workers.max`.

`HybridStrategy` does not pass the raw oldest-job age. It passes `(int) slaSignal`, where
`slaSignal` is the sliding-window pickup-time percentile (`sla.percentile`, default p95) when the
`PickupTimeStore` has at least `sla.min_samples` samples inside `sla.window_seconds`, and
`metrics.oldestJobAge` otherwise.

## The calculation, step by step

### 1. Nothing to drain

```text
if backlog == 0 or avgJobTime <= 0:
    return 0.0
```

### 2. Effective SLA

```text
effectiveSla = effectiveSlaSeconds ?? (float) slaTarget
```

`HybridStrategy` always supplies `effectiveSlaSeconds`:

```text
effectiveSla = max(1.0, sla.target_seconds - spawnLatency)
```

`spawnLatency` is the EMA of measured worker spawn time from the `SpawnLatencyTracker`, and is
`0.0` when `spawn_compensation.enabled` is false for the queue. Budgeting for spawn time means the
calculator aims to have workers *already processing* by the deadline, not merely started.

### 3. Age-unavailable fallback

Not every queue driver can report job age, and a fresh process may have no percentile samples yet.
When the age signal is `0` but a backlog exists:

```text
jobsPerWorker = max(effectiveSla / avgJobTime, 1.0)
return backlog / jobsPerWorker
```

This path spreads the backlog across the full SLA window and returns immediately — **no
aggressiveness multiplier is applied**, and no threshold check happens.

### 4. SLA progress and the threshold

```text
slaProgress = min(oldestJobAge / effectiveSla, 1.5)     # capped at 150%

if slaProgress < breachThreshold:
    return 0.0
```

`breachThreshold` comes from `scaling.breach_threshold`, whose default is **0.5** (50% of the SLA
window), read through `AutoscaleConfiguration::breachThreshold()`. Below it, the calculator abstains
entirely and Little's Law alone decides the target.

**One exception, applied by the strategy rather than here.** Abstaining is right when workers are
already running: it stops the fleet reacting to every transient blip on a queue that is being
served. With no workers running, nothing is absorbing the backlog and the only thing happening is
the clock running down — so a queue holding work with nothing draining it asks for one worker
straight away, whatever this calculator says. Without it, a single job arriving at a queue sitting
at zero waited half its SLA before a worker was even requested: 15 seconds against a 30-second
target, 60 against 120, and the evaluation interval made no difference. It is stated as a need
rather than a floor, so the capacity clamp, `workers.max` and the failure fuse all still apply
after it.

The 1.5 cap keeps a badly breached queue from producing an unbounded multiplier.

### 5. Base workers

```text
timeUntilBreach = effectiveSla - oldestJobAge

baseWorkers = timeUntilBreach > 0
    ? backlog / max(timeUntilBreach / avgJobTime, 1.0)     # still time left
    : backlog / max(avgJobTime, 0.1)                       # already breached
```

The `max(..., 1.0)` floor means a worker is never assumed to clear less than one job in the
remaining window. In the already-breached branch there is no deadline left to divide by, so the
backlog is divided by job time directly.

### 6. Progressive aggressiveness multiplier

```text
if slaProgress < 0.5:
    multiplier = 0.0
else:
    multiplier = min(1.0 + 8.0 * (slaProgress - 0.5)^2, 5.0)

return baseWorkers * multiplier
```

A continuous quadratic curve, so urgency accelerates smoothly instead of stepping — discrete tiers
would make the target jump and reverse across an evaluation boundary.

| SLA progress | Multiplier |
|---|---|
| 50% (threshold) | 1.00x |
| 60% | 1.08x |
| 70% | 1.32x |
| 80% | 1.72x |
| 90% | 2.28x |
| 100% (at SLA) | 3.00x |
| 110% | 3.88x |
| 120% | 4.92x |
| ~121% and beyond | 5.00x (cap) |

The cap engages at `slaProgress ≈ 1.207`, well before the 1.5 progress cap.

The multiplier is the single largest factor in how this calculator behaves under pressure: at the
SLA line it asks for three times the arithmetically sufficient worker count, and up to five times
past it.

## Worked examples

All examples use `avgJobTime = 2.0 s`, `sla.target_seconds = 30`, `breach_threshold = 0.5`, and no
spawn compensation (`effectiveSla = 30.0`). Results are the raw float from the calculator, before
`HybridStrategy` takes the maximum with Little's Law, rounds up, and clamps.

### Example 1: age signal unavailable

| Input | Value |
|---|---|
| Backlog | 120 |
| Age signal | 0 (no p95 samples, driver reports no age) |

```text
jobsPerWorker = max(30.0 / 2.0, 1.0) = 15
workers       = 120 / 15 = 8.0
```

No threshold check, no multiplier — the fallback path returns before both.

### Example 2: below the threshold

| Input | Value |
|---|---|
| Backlog | 500 |
| Age signal | 9 s |

```text
slaProgress = 9 / 30 = 0.30  <  0.5
workers     = 0.0
```

Little's Law decides the target on its own.

### Example 3: exactly at the threshold

| Input | Value |
|---|---|
| Backlog | 200 |
| Age signal | 15 s |

```text
slaProgress     = 15 / 30 = 0.50           -> multiplier 1.00x
timeUntilBreach = 30 - 15 = 15 s
baseWorkers     = 200 / max(15 / 2.0, 1.0) = 200 / 7.5 = 26.67
workers         = 26.67 x 1.00 = 26.67
```

### Example 4: 80% of the SLA window consumed

| Input | Value |
|---|---|
| Backlog | 200 |
| Age signal | 24 s |

```text
slaProgress     = 24 / 30 = 0.80           -> multiplier 1 + 8 x 0.3^2 = 1.72x
timeUntilBreach = 6 s
baseWorkers     = 200 / max(6 / 2.0, 1.0) = 200 / 3 = 66.67
workers         = 66.67 x 1.72 = 114.67
```

With `workers.max = 20` this clamps to 20 — the calculator's job is to say how much is needed, the
engine's job is to say how much is allowed.

### Example 5: at the SLA line

| Input | Value |
|---|---|
| Backlog | 200 |
| Age signal | 30 s |

```text
slaProgress     = 30 / 30 = 1.00           -> multiplier 3.00x
timeUntilBreach = 0                        -> already-breached branch
baseWorkers     = 200 / max(2.0, 0.1) = 100
workers         = 100 x 3.00 = 300
```

### Example 6: deep breach

| Input | Value |
|---|---|
| Backlog | 200 |
| Age signal | 60 s |

```text
slaProgress     = min(60 / 30, 1.5) = 1.50 -> 1 + 8 x 1.0^2 = 9.0, capped to 5.00x
timeUntilBreach = -30                      -> already-breached branch
baseWorkers     = 200 / 2.0 = 100
workers         = 100 x 5.00 = 500
```

### Example 7: spawn compensation tightening the window

| Input | Value |
|---|---|
| Backlog | 200 |
| Age signal | 15 s |
| Measured spawn latency | 4 s |

```text
effectiveSla    = max(1.0, 30 - 4) = 26.0
slaProgress     = 15 / 26 = 0.577          -> multiplier 1 + 8 x 0.077^2 = 1.047x
timeUntilBreach = 26 - 15 = 11 s
baseWorkers     = 200 / max(11 / 2.0, 1.0) = 200 / 5.5 = 36.36
workers         = 36.36 x 1.047 = 38.08
```

Compare with Example 3: the same backlog and age produce a larger target once the spawn cost is
subtracted from the budget.

## What this calculator does not do

- It never returns `workers.max`. It has no access to `workers.max`, `workers.min`, or the current
  worker count — clamping happens in `HybridStrategy` and `ScalingEngine`.
- There is no "scale to maximum immediately on breach" branch. The breached case is the
  `backlog / avgJobTime` base multiplied by the (capped) aggressiveness factor.
- There is no drain-rate tracker, no breach-time forecaster, and no post-breach recovery mode.
- It holds no state between calls.

## Properties

- **O(1)** time and space.
- Deliberately over-provisions near the deadline; the multiplier is the mechanism.
- Bounded above by the 1.5 progress cap and the 5.0x multiplier cap, and then by config and
  system capacity in the engine.

## See also

- [Little's Law](littles-law.md) — the steady-state half of the maximum
- [Resource Constraints](resource-constraints.md) — the capacity ceiling applied afterwards
- [Architecture](architecture.md) — the full decision pipeline
