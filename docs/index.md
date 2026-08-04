---
title: "Introduction"
description: "SLA-driven autoscaling for Laravel queue workers, built on Little's Law and backlog-drain math"
weight: 1
---

# Introduction

Queue Autoscale for Laravel is a long-running worker manager. It spawns and terminates `queue:work`
processes so that a queue holds a pickup-time SLA you declare, instead of a worker count you guess.

## What is Queue Autoscale for Laravel?

Traditionally you pick a number and hope it is right:

```ini
; supervisor
numprocs=10
```

Here you state the outcome you actually care about:

```php
// config/queue-autoscale.php
'sla' => ['target_seconds' => 30],
```

Every evaluation cycle the manager measures the queue, solves for the worker count that satisfies
that target, bounds it by what the host can actually run, and moves the pool toward it.

## Key features

**SLA-based scaling.** Declare a target pickup time. Worker counts are derived from measurements,
not configured by hand.

**Queueing theory foundation.** Steady-state demand comes from Little's Law (`L = λW`), so the
baseline worker count has a defensible derivation rather than a heuristic.

**Backlog drain with progressive urgency.** As a queue eats into its SLA budget, a continuous
quadratic curve raises the drain target — 1.0x at half the budget, 3.0x at the target, capped at
5.0x. The curve is continuous specifically to avoid the step changes that cause scaling oscillation.

**p95 pickup-time signal.** The SLA signal is a sliding-window percentile over pickup times that
were actually observed, not a proxy. When there are too few samples it falls back to the age of the
oldest job.

**Spawn-latency compensation.** Starting a worker takes time. The measured spawn latency (an EMA per
queue) is subtracted from the SLA budget so the manager acts early enough for the new worker to
matter.

**Failure fuse.** A downstream outage is indistinguishable from load: jobs fail, get released, the
backlog grows, the oldest job ages. A naive autoscaler responds by adding workers, which hammers the
failing dependency and burns each job's retry budget faster. The fuse watches the recent failure
rate, holds the queue at `workers.min`, and after a cooldown lets a single worker probe for recovery.

**Resource-aware.** CPU and memory ceilings are measured on the host and constrain every decision,
so the manager will not spawn more workers than the machine can carry.

**Metrics-driven.** Queue discovery and all metrics come from `cboxdk/laravel-queue-metrics`.

**Cluster-aware.** Managers on multiple hosts auto-join via Redis, elect a leader, and receive
per-host worker recommendations.

**Extensible.** Custom strategies and policies via small interfaces.

**Events.** Scaling decisions, SLA breaches and recoveries, fuse transitions, manager lifecycle and
cluster changes are all Laravel events.

**Graceful shutdown.** SIGTERM first, SIGKILL only after the configured shutdown timeout, so workers
finish the job in hand.

## How it works

The default `HybridStrategy` computes two candidate worker counts and takes the **maximum**:

**1. Steady state — Little's Law**

```text
workers = arrivalRate × avgJobTime
```

`arrivalRate` is estimated from backlog deltas blended with a forecast, falling back to the observed
processing rate when that estimate is not confident enough. `avgJobTime` comes from measured job
duration, with a configurable fallback.

**2. Backlog drain — SLA protection**

```text
slaProgress = min(slaSignal / effectiveSla, 1.5)
baseWorkers = backlog / max((effectiveSla - slaSignal) / avgJobTime, 1.0)
multiplier  = min(1.0 + 8.0 × (slaProgress - 0.5)², 5.0)
workers     = baseWorkers × multiplier
```

This contributes nothing until `slaProgress` reaches the breach threshold (default `0.5`), then
ramps continuously toward the 5.0x cap.

The larger of the two becomes the target. It is then bounded by host capacity, clamped to the
queue's `workers.min` / `workers.max`, held down by the failure fuse if that has tripped, and
smoothed to damp oscillation.

For the full derivation, including the saturation guard and the hysteresis smoother, see
[How It Works](basic-usage/how-it-works.md) and the [Architecture](algorithms/architecture.md)
deep dive.

## Use cases

- **High-volume applications** processing thousands of jobs per minute under a stated SLA
- **Variable traffic** — peak hours, marketing campaigns, seasonal spikes
- **Multi-tenant systems** where workloads differ wildly between customers
- **Off-peak thrift** — hold the SLA with as few workers as it actually takes
- **Mission-critical queues** that need a defensible processing-time guarantee

## Requirements

- **PHP**: 8.3, 8.4 or 8.5
- **Laravel**: 11, 12 or 13
- **Extensions**: `ext-pcntl`, `ext-posix`
- **Redis**: optional; required only for cluster mode

For the full dependency list and infrastructure notes, see [Requirements](requirements.md).

## Package architecture

Queue Autoscale is a **metrics consumer**, not a metrics collector:

- **laravel-queue-metrics** — discovers queues, scans connections, collects all metrics
- **laravel-queue-autoscale** — consumes those metrics, applies the algorithms, manages workers

That separation keeps each package focused, and means the autoscaler has no queue-driver code of its
own to keep in sync.

## Next steps

Ready to get started? Follow the [Installation](basic-usage/installation.md) guide, or go straight to
the [Quick Start](quickstart.md) for a five-minute path.

Want the algorithm in detail? See [How It Works](basic-usage/how-it-works.md).

Looking for configuration options? Check the [Configuration Guide](basic-usage/configuration.md).
