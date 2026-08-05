---
title: "Introduction"
description: "Declare how long a job may wait. The autoscaler works out how many workers that takes."
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

## Who does the translation?

Every scaling model eventually needs one number: how many workers to run. The models differ in who
works it out — you, or the autoscaler.

| What you configure | What the system does with it | What you had to know |
|---|---|---|
| A fixed count | Nothing. It runs what you set. | How many workers your load needs. |
| A ceiling, split between queues | Divides your ceiling between queues — by how deep each one is, or by how long each looks like it will take to clear. | How many workers your load needs — the ceiling *is* that guess. |
| A jobs-per-worker target | Divides queue depth by your target. | How many jobs one worker clears in an acceptable time. |
| **A pickup-time target** | Measures how long jobs actually take and how fast they arrive, then solves for the count. | **How long a job may wait before it matters to you.** |

Only the last one asks a question you can answer from the business. "Password resets must go out within
ten seconds" is a fact about your product. "Password resets need four workers" is a derivation from
that fact, made with information you do not have on a Tuesday afternoon — how long the job takes this
week, how many are arriving right now, how long a new worker takes to start.

The first three are not wrong. They are fast, they need almost no measurement, and a fixed pool is
genuinely the right answer for a queue whose load never changes. But each of them makes you do the
arithmetic once, in advance, from numbers that move.

### Why queue depth is a proxy, and where it breaks

Depth is the cheapest signal there is — one call to any queue driver returns it. That is why most
scaling models are built on it. It is also a stand-in for the thing you care about, and stand-ins
break in specific ways:

- **Jobs are not the same size.** Ten jobs at 100 ms and ten jobs at sixty seconds are the same
  depth and nine minutes apart in real terms.
- **Retries look like new work.** A job that fails and is released re-enters the queue. Depth goes
  up, but no new work arrived.
- **Contention looks like load.** Laravel's own `RateLimited` and `WithoutOverlapping` middleware
  *release jobs back onto the queue* rather than running them. Under contention, depth measures how
  many jobs are waiting for a lock — and adding workers makes it strictly worse. A depth-driven
  scaler reads this as demand and scales into the spiral.

That last case is why this package has a [failure fuse](basic-usage/failure-fuse.md): when jobs are
failing rather than queueing, more workers are the wrong answer, and something has to say so.

Some models improve on raw depth by dividing their pool according to how long each queue looks like
it will take to clear, which does answer the first objection — a queue of slow jobs earns more
workers than a queue of fast ones. It is a better split. It is still a split: the size of the pool
being divided is the number you supplied.

Pickup time has none of those failure modes, because it is not a proxy. It is the number you were
trying to control in the first place, measured directly.

### What happens before there is evidence

Deriving workers from latency needs measurement that depth alone does not: how long jobs take, when
they were enqueued, when they were picked up. A queue that has just been created has none of that.

It does not fall over, and it does not guess. Every input degrades to a cruder one, and the decision
reason names which it used:

| Input | With evidence | Without it |
|---|---|---|
| SLA signal | p95 over observed pickup times | Age of the oldest waiting job |
| Job duration | Measured average, sanity-bounded | A configured fallback |
| Arrival rate | Backlog deltas, blended with a forecast | Observed processing rate, then derived from backlog and age |
| Memory and CPU per worker | Measured from running workers | Your per-queue figure, then a global default |
| Host capacity | Measured CPU and memory headroom | A deliberately small ceiling |

Read the right-hand column together and it describes a depth-and-age scaler — which is to say, a cold
start behaves roughly like the models in the table above, and improves from there as evidence arrives.
You are not worse off on minute one for having chosen this; you are better off by minute ten. The
`EstimateSource` on every decision tells you which column you are in, so "is it warmed up yet?" is a
question you can answer rather than assume.

### It stays a good neighbour

A scaling model that only knows queue depth has no idea what machine it is running on. `ceil(20 / 5)`
is four workers whether the box can carry forty or two.

Every target here is bounded by measured CPU and memory headroom on the host *before* any minimum is
applied, and each queue can only claim capacity the other queues are not already using. If the host
cannot be read at all, the ceiling drops to a small conservative number and the decision says
`system_metrics_unavailable` rather than assuming the machine is empty. An optional
`limits.max_total_workers` puts a hard cap across every queue and group, for the case where queue
names are discovered rather than configured.

The failure mode of a queue-depth scaler under a backlog it cannot drain is to keep adding workers.
The failure mode here is to stop at what the machine has and say why.

## What the number counts

A worker count only means something once you know what it is counted over. Three models, three
different answers — and the difference shows up as either surprise cost or surprise latency.

**Counted per host.** A process supervisor running on each machine scales that machine and knows
nothing about the others. Set a ceiling of twenty and run six hosts and you have authorised a hundred
and twenty workers against one database. The ceiling reads like a budget and behaves like a budget
per replica. Making it a real fleet budget means dividing it by your host count by hand and redoing
that arithmetic every time you add or lose a machine.

**Counted per container.** One worker to a container makes the platform's own limits the enforcement
mechanism, which is a genuinely sound trade: nothing can oversubscribe a box, because nothing shares
one. You pay for it in granularity. Each worker carries a full container's allocation and a full cold
start, so a queue that needs eleven workers for ninety seconds costs eleven containers spun up and
torn down, and the memory a worker does not use is not available to anything else.

**Counted per fleet.** Here the leader solves for the demand of a workload once, across every host,
and then hands each host a share weighted by the capacity that host actually reported. A host with
more headroom takes more; a host already busy with other queues takes fewer; every host still applies
its own resource ceiling to whatever it is handed. The ceiling you configure is the number of workers
that will exist, not the number that will exist somewhere.

## Queues you forgot to configure still get processed

Naming your queues is the step everyone gets wrong eventually. A developer adds
`dispatch(new ThingJob)->onQueue('exports')` and nobody adds `exports` to the supervisor config. A
queue name is misspelled in one of the two places it appears. A tenant-specific queue is created at
runtime and no config file knows about it. In every case the jobs are enqueued successfully, nothing
errors, and they are never picked up — until someone notices days later.

This package does not read its list of queues from configuration. It reads it from the metrics
package, which sees every queue that has actually received a job. Anything that appears there is
managed, using your default profile, with no configuration at all. Configuration exists to *change*
how a queue is treated, not to make it visible.

That trades one failure mode for another, so both halves ship:

- [`excluded`](basic-usage/configuration.md) takes glob patterns for queues this package must never
  touch — the ones another supervisor owns, or throwaway queues you do not want workers for.
- [`limits.max_total_workers`](basic-usage/configuration.md) caps the whole host. Discovery means an
  application that generates queue names per tenant can present thousands of them, and each would
  otherwise be raised to its floor. The cap is the backstop; it is worth setting the moment queue
  names are dynamic.

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

- **PHP**: 8.4 or 8.5
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
