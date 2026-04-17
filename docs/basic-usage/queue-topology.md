---
title: "Queue Topology"
description: "How Laravel multi-queue workers work, how Horizon groups them, and how this package chooses workers per queue, per group, or excluded"
weight: 5
---

# Queue Topology

Before you configure autoscaling, it helps to be precise about **which worker listens to which queue**. This page explains the three models you have available, when to use each, and how they compare to Laravel's native `queue:work` and Horizon.

Read this once; it removes 90% of the confusion about worker behaviour.

## The Three Questions You Actually Care About

For any queue in your system, you must answer:

1. **Does a worker for this queue share its process with another queue?** (dedicated vs. shared)
2. **If shared, who gets priority when both have jobs?** (order matters)
3. **Should the autoscaler manage it at all?** (some queues must stay manual)

This package supports all three answers cleanly:

| Answer | Configuration | Section |
|---|---|---|
| Dedicated worker per queue, autoscaled | `queues.{name}` | [Per-Queue Workers](#per-queue-workers-default) |
| Shared worker across several queues, priority order | `groups.{name}` | [Worker Groups](#worker-groups) |
| Exactly one pinned worker, sequential jobs | `ExclusiveProfile` | [Exclusive (Sequential) Queues](#exclusive-sequential-queues) |
| Not managed by this package at all | `excluded` | [Excluded Queues](#excluded-queues) |

---

## How Laravel's `queue:work` Actually Behaves

When you run `php artisan queue:work redis --queue=critical,default,low`, Laravel does **not** drain `critical` before moving to `default`. It does this on every poll cycle:

```text
poll() {
    if critical has a job → take it, return
    if default has a job  → take it, return
    if low has a job      → take it, return
    sleep
}
```

This is **strict priority, checked per-poll**. Two consequences:

- A burst on `default` still gets processed as long as there are gaps in `critical`'s arrivals.
- A queue that constantly produces jobs faster than the worker can drain **will** starve later queues in the list.

This is exactly what Horizon calls `balance: false`. It is the **only** Horizon mode where a single worker polls multiple queues — in `balance: simple` and `balance: auto`, Horizon spawns one process per queue and just decides how many to run for each.

That subtle distinction is the whole reason this package exists in per-queue form by default.

---

## Per-Queue Workers (Default)

```php
'queues' => [
    'payments' => CriticalProfile::class,
    'emails'   => BalancedProfile::class,
],
```

**What runs:** one set of workers per queue, each invoking `queue:work redis --queue=payments` (or `--queue=emails`).

**What you get:**

- Starvation is **impossible**. Each queue has its own worker pool, its own SLA target, its own metrics.
- Scaling is isolated: a spike on `emails` never pulls workers away from `payments`.
- The forecasting, pickup-time percentiles, and spawn-compensation maths all operate cleanly per queue.

**What you pay:**

- **More idle capacity.** A quiet queue still holds its `min_workers`. If `emails` is idle while `payments` is drowning, `emails`'s workers cannot help.
- **Spawn latency on bursts.** When `payments` suddenly needs more workers, we predict, forecast, and spawn — which takes seconds. A shared worker would have absorbed the spike immediately.

**Use this when:** queues have different job durations, different SLA targets, or different failure characteristics. This is the right default for the vast majority of queues.

> This model is approximately equivalent to Horizon's `balance: auto`, but with a smarter scaling engine (Little's Law, p95 pickup times, trend forecasting) than Horizon's `time`/`size` strategies.

---

## Worker Groups

```php
'groups' => [
    'notifications' => [
        'queues'     => ['email', 'sms', 'push'],  // priority order
        'profile'    => BalancedProfile::class,
        'connection' => 'redis',
    ],
],
```

**What runs:** one set of workers for the group, each invoking `queue:work redis --queue=email,sms,push`. The autoscaler treats the group as a single scaling unit — one worker count, one SLA target, one set of metrics aggregated across members.

**What you get:**

- **Zero-latency burst absorption.** When `push` suddenly gets 1000 jobs and `email` is quiet, the existing workers immediately pick up `push` jobs. No spawning needed.
- **Shared budget.** You set `min`/`max` for the group as a whole. You don't pay for idle capacity on each member queue separately.
- **Priority ordering.** Queues listed first get served first per-poll.

**What you pay:**

- **Starvation risk.** If `email` constantly outpaces drain capacity, `sms` and `push` can starve. Size the group correctly or rely on the autoscaler to add capacity under pressure.
- **Group-level signal only.** Pickup-time percentiles (p95) are computed across the union of all members' samples, and `oldest_job_age` uses the worst member. You lose per-queue precision — if one member is slow and three are fast, the group's p95 reflects the blend, not the slow queue's own SLA.
- **All members share one SLA target.** Not suitable for mixing `payments` (10s SLA) with `analytics` (hour-long SLA).

**Rules enforced at startup:**

- A queue may appear in `queues` **or** in a group's `queues` list — never both.
- A queue may appear in at most one group.
- Only `mode: 'priority'` is supported (the only mode that actually shares a worker across queues).
- Groups cannot use `ExclusiveProfile` (a pinned group makes no sense — use a per-queue exclusive config).

**Use this when:** several queues are closely related (same failure domain, similar job duration, correlated traffic), you want burst absorption, and per-queue SLA precision is not worth the resource cost.

### What's Different About Group Scaling

A group's `ScalingDecision` is computed from aggregated metrics:

- `pending`, `scheduled`, `reserved`, `throughput`: summed across members.
- `oldest_job_age`: **max** across members (the worst case drives the SLA signal).
- `avg_duration`: throughput-weighted mean.

This is deliberately conservative. The autoscaler will spawn extra workers if **any** member is behind — it will not let one happy queue mask another queue's SLA breach.

---

## Exclusive (Sequential) Queues

```php
'queues' => [
    'legacy-integration' => ExclusiveProfile::class,
],
```

**What runs:** exactly one worker, always. No more, no less.

**When the worker dies** (OOM, crashed job, operator kill), the manager respawns it on the next evaluation cycle. The package acts as a process supervisor for this queue — not an autoscaler.

**What you get:**

- **Sequential job processing.** Two jobs from this queue will never run at the same time.
- **Strict ordering under the queue driver's guarantees.** Redis FIFO stays FIFO.
- **Visibility.** SLA breach events still fire, even though we cannot scale to fix them — operators need to know when a single-threaded queue falls behind.

**What you pay:**

- **No autoscaling.** Ever. If the queue backs up, workers do not spawn.
- **No forecasting.** SLA breach detection still works, but prediction is disabled (`DisabledForecastPolicy`).

**Use this when:** a customer integration requires ordered processing, a third-party API enforces single-connection semantics, or your business logic assumes no two jobs from this queue run simultaneously.

> If you need "exactly N pinned workers" for some N > 1, write a custom profile that sets `min == max == N` with `scalable: false`. The constructor enforces both invariants.

---

## Excluded Queues

```php
'excluded' => [
    'horizon-managed',
    'legacy-*',
    'test-?',
],
```

**What runs:** nothing managed by this package. The queue is completely ignored.

**Why you need this:**

- **Horizon is already managing it.** Two autoscalers on one queue will fight each other.
- **A sidecar tool runs it.** Custom supervisord, systemd timers, manual `queue:work` in screen — all valid.
- **It's a throwaway queue** for a migration or test, and you do not want the autoscaler inventing a `BalancedProfile` worker pool for it.

**Glob support:** patterns use `fnmatch()` semantics. `legacy-*` matches `legacy-sync`, `legacy-reports`, etc. `test-?` matches `test-1` but not `test-12`.

**First-time log message:** when the manager sees an excluded queue in the metrics stream, it logs a single info line so you can confirm the exclusion is doing what you expect.

---

## Decision Tree

Use this when deciding where a new queue should live.

```text
Is another tool (Horizon, custom) managing it?
├── Yes → add it to 'excluded'
└── No
    │
    Must jobs run one-at-a-time in strict order?
    ├── Yes → 'queues' => ExclusiveProfile::class
    └── No
        │
        Does it share a traffic pattern and job-duration profile with other queues?
        ├── Yes → put them together in 'groups'
        └── No  → 'queues' => <the profile that matches its SLA>
```

---

## Comparison Table

| Capability | Per-Queue | Group | Exclusive | Excluded |
|---|---|---|---|---|
| Autoscales | ✅ | ✅ | ❌ | ❌ |
| Dedicated worker process | ✅ | ❌ (shared) | ✅ | n/a |
| Forecast-driven scaling | ✅ | ✅ (aggregate) | ❌ | n/a |
| Burst absorption without spawn | ❌ | ✅ | ❌ | n/a |
| Per-queue SLA precision | ✅ | ❌ (group-level) | ✅ (observability only) | n/a |
| Starvation possible | ❌ | ⚠️ Yes, if sized too small | ❌ | n/a |
| Respawns on worker death | ✅ | ✅ | ✅ | ❌ |
| SLA breach events | ✅ | ✅ (group) | ✅ | ❌ |

---

## Comparison With Horizon

| This package | Horizon equivalent | Notes |
|---|---|---|
| Per-queue (default) | `balance: auto` | Both: one pool per queue, dynamic scaling. Our scaling math is more sophisticated (Little's Law + forecasting vs. `time`/`size`). |
| Group (`mode: 'priority'`) | `balance: false` | Both: one worker process, multiple queues, strict priority per poll. |
| Exclusive profile | `balance: false`, fixed processes | Horizon has no built-in "pin to N" — you'd mimic it with `minProcesses = maxProcesses = 1`. Our profile makes it a first-class declaration with supervisor-style respawn. |
| Excluded | n/a | Horizon does not have a "leave this queue alone" concept; you either manage a queue or you don't. |

Horizon has `balance: simple` (fixed processes split evenly across queues). We deliberately do not expose this: it's strictly worse than either per-queue (better scaling) or groups (better resource sharing), and offering it would invite confusion.

---

## Common Mistakes

**"I want workers grouped by SLA."** — This sounds intuitive but is the wrong primitive. SLA is a *target*, not a *scheduling axis*. Two queues with the same 30s SLA but wildly different job durations (50ms vs. 8s) will behave terribly if mixed: the fast queue's workers get stuck on slow jobs. Group by **job characteristics and correlation**, then verify the SLA targets are compatible.

**"I'll put `payments` and `analytics` in one group to save workers."** — Don't. `payments` needs a 10s SLA, `analytics` can wait an hour. In a group they share one SLA target, and you'll either over-provision for analytics or under-provision for payments. Use per-queue for different SLA regimes.

**"The group should auto-detect its members from queue names."** — Auto-detection is a footgun. A new queue named `email-followup-v2` should not silently join the `email` group just because of its prefix. All group membership is declared explicitly.

**"Excluded queues will still show up in metrics, right?"** — Yes. The metrics package keeps recording them. `excluded` only affects this package — nothing else. That's the whole point: observe, don't manage.
