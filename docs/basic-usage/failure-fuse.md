---
title: "Failure Fuse"
description: "The circuit breaker that stops the autoscaler from adding workers to a downstream outage"
weight: 19
---

# Failure Fuse

The fuse is a circuit breaker over each queue's job failure rate. It exists because a downstream outage and a traffic spike look identical to an autoscaler — and answering the first one like the second makes things worse.

## Table of Contents
- [The problem it solves](#the-problem-it-solves)
- [How it works](#how-it-works)
- [Detection latency](#detection-latency)
- [What counts as a failure](#what-counts-as-a-failure)
- [Configuration](#configuration)
- [Per-profile defaults](#per-profile-defaults)
- [Tuning](#tuning)
- [Events](#events)
- [Telemetry](#telemetry)
- [Disabling the fuse](#disabling-the-fuse)
- [What the fuse is not](#what-the-fuse-is-not)
- [Troubleshooting](#troubleshooting)

## The problem it solves

Your payment provider goes down. Every job on the `payments` queue throws, gets released, and is retried. From the autoscaler's point of view:

- the backlog is growing
- the oldest job is aging
- the arrival rate looks high, because retries are indistinguishable from new work

Every signal says **scale up**. So the autoscaler adds workers, and each new worker does exactly what the existing ones are doing: hammering a service that is already failing, and burning through each job's `tries` budget faster than it otherwise would. You end up with maximum load on a broken dependency and a pile of permanently-failed jobs that would have survived if they had waited.

The fuse breaks that loop. Above a configured failure rate it stops scaling up and holds the queue at `workers.min` until the dependency recovers.

## How it works

Three states, same shape as any circuit breaker:

| State | Worker ceiling | Meaning |
|---|---|---|
| **Closed** | none | Normal operation. The autoscaler scales freely. |
| **Open** | `workers.min` | Tripped. The queue is held at its floor while the failure persists. |
| **Half-open** | `max(1, workers.min)` | Cooldown elapsed. A probe runs to find out whether the dependency recovered. |

```text
                  failure rate >= threshold
                  (with >= min_samples)
        ┌────────┐ ─────────────────────────> ┌──────┐
        │ Closed │                            │ Open │
        └────────┘ <───────────────────────── └──────┘
             ▲       probe window healthy         │
             │                                    │ cooldown_seconds elapsed
             │                                    ▼
             │                            ┌───────────┐
             └──────────────────────────  │ Half-open │
                                          └───────────┘
                     probe still failing ──────┘
                     (back to Open, cooldown restarts)
```

The fuse trips only when **both** conditions hold within the window:

- the failure rate is at or above `failure_threshold_percent`, and
- at least `min_samples` job outcomes have been recorded

The sample floor is what stops three failed jobs on a quiet queue from stalling it. Without it, any queue that processed two jobs and failed both would read as a 100% failure rate.

Every state transition clears the window. Otherwise the failures that tripped the fuse would still be in view when the probe is evaluated, and would re-trip it before the probe ran a single job.

### Where it applies

The fuse lives in the scaling engine, not in a strategy, so it applies to every strategy including [custom ones](../advanced-usage/custom-strategies.md). In [cluster mode](cluster-scaling.md) it constrains cluster-wide demand as well as each host's local decision, so the leader stops recommending capacity that no host will spawn.

### The floor is still the floor

A tripped queue drops to `workers.min`, not to zero. If you configured `min: 6`, six workers keep running against the failing dependency. That is deliberate — the floor is your decision, and the fuse does not overrule it. If you want a queue to be able to stand fully down during an outage, give it `min: 0`.

Conversely, the half-open probe never runs with zero workers even when `min` is `0`. A queue holding at zero would process no further jobs, record no outcomes, and the fuse could never observe recovery — so the probe floors at one.

The ceiling is a ceiling, not a target: an idle queue with no pending work stays idle while tripped rather than being handed probe workers it has nothing to do with.

## Detection latency

**The fuse cannot trip the instant a dependency dies.** Worst case, it takes `2 × window_seconds` to react.

Outcomes are counted into fixed time buckets, and a read sums the current *and* previous bucket. That is what stops the window from dropping to zero samples the moment a bucket rolls over — which would read as "not enough data" and let a held queue resume scaling mid-outage. The cost is that healthy traffic recorded before the outage stays in view, diluting the failure rate, until it ages out.

| `window_seconds` | Worst-case time to trip |
|---|---|
| 30 | 60s |
| 60 | 120s |
| 120 | 240s |
| 300 | 600s |

Shorten the window if you need faster detection. You cannot tune the 2× ratio away — it is inherent to not losing evidence at bucket boundaries.

## What counts as a failure

The fuse counts **thrown exceptions**, via Laravel's `JobExceptionOccurred` event.

It deliberately does not use `JobFailed`, which only fires once a job has exhausted its retries. With the default `tries: 3`, waiting for `JobFailed` would put the fuse three attempts behind reality — long after the backlog it exists to ignore had already formed.

A job that calls `$job->fail()` manually without throwing is **not** counted as a failure. Queues matched by [`excluded`](configuration.md) are ignored entirely.

### Excluding exceptions that say nothing about capacity

The real question the fuse asks is narrower than "did this job fail?" — it is **"does this failure mean adding workers would make things worse?"** A dead dependency, a timeout or a rate limit all answer yes. A job that threw a validation error on its own payload answers no: it never reached the dependency, and holding the queue back over it would be wrong.

List those exceptions and the fuse will skip them:

```php
'fuse' => [
    'ignored_exceptions' => [
        \App\Exceptions\InvalidPayloadException::class,
    ],
],
```

Matching is by `instanceof`, so listing a base class covers its subclasses. An ignored exception is dropped **entirely** — it counts neither as a failure nor as a success, because an outcome that carries no signal should not vote either way.

### Rate limits and auth errors are counted on purpose

This is where the fuse deliberately diverges from job-level circuit breakers, which conventionally ignore HTTP 429 and 401/403.

A job-level breaker ignores a rate limit because it wants the retry to happen later rather than the circuit to open. The autoscaler is answering a different question, and against a rate limit more workers are precisely the wrong response — they consume the remaining budget faster. The same holds for auth errors and timeouts: no number of workers fixes a bad credential.

If your queue treats rate limiting as routine and you would rather keep scaling through it, add the exception class to `ignored_exceptions`.

### Custom classification

When a list of classes is not enough — say the decision depends on an HTTP status inside the exception, or on which queue threw it — implement the contract:

```php
namespace App\Queue;

use Cbox\LaravelQueueAutoscale\Contracts\FailureClassifierContract;
use Illuminate\Http\Client\RequestException;
use Throwable;

class StatusAwareClassifier implements FailureClassifierContract
{
    public function countsAsFailure(Throwable $exception, string $connection, string $queue): bool
    {
        // A 422 is the caller's fault, not the dependency's.
        if ($exception instanceof RequestException) {
            return $exception->response->status() !== 422;
        }

        return true;
    }
}
```

Then point the config at it:

```php
'fuse' => [
    'classifier' => \App\Queue\StatusAwareClassifier::class,
],
```

The classifier is resolved from the container, so it can take constructor dependencies. It replaces the `ignored_exceptions` list rather than supplementing it.

## Configuration

Two settings are infrastructure and live at the top level of `config/queue-autoscale.php`:

```php
'fuse' => [
    // Master switch. Turning this off disables the fuse for every queue,
    // regardless of profile.
    'enabled' => env('QUEUE_AUTOSCALE_FUSE_ENABLED', true),

    // Where outcome counters live. Job outcomes are counted in the worker
    // processes and read by the manager process, so this must be a shared
    // backend even on a single host.
    //   'auto' / 'cache' => Laravel's cache (any driver)
    //   'null'           => disable outcome tracking (the fuse never trips)
    //   FQCN             => your own FailureWindowStoreContract
    'store' => env('QUEUE_AUTOSCALE_FUSE_STORE', 'auto'),

    // Exception classes that carry no signal about capacity. Matched by
    // instanceof; see "What counts as a failure" above.
    'ignored_exceptions' => [],

    // Replaces the list above wholesale when you need per-queue or
    // per-message decisions.
    'classifier' => \Cbox\LaravelQueueAutoscale\Fuse\ConfigurableFailureClassifier::class,
],
```

Unlike the pickup-time and spawn-latency stores, the fuse store does **not** fall back to a no-op in single-host mode. It goes through Laravel's cache rather than Redis directly, so it works with any cache driver and does not add Redis as a requirement.

> The `array` cache driver confines the fuse to a single process, so counters written by workers are invisible to the manager. Use a shared driver (`redis`, `memcached`, `database`, `file`) in any environment where the fuse should actually work. `queue:autoscale:debug` warns when it detects this.

The thresholds are per-queue and live in the profile's `fuse` block:

```php
'queues' => [
    'payments' => [
        'fuse' => [
            'enabled' => true,
            'failure_threshold_percent' => 40.0,
            'min_samples' => 10,
            'window_seconds' => 30,
            'cooldown_seconds' => 30,
        ],
    ],
],
```

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Whether this queue uses the fuse at all |
| `failure_threshold_percent` | `50.0` | Failure rate at or above which the fuse trips (0 < n <= 100) |
| `min_samples` | `20` | Outcomes required in the window before the rate is trusted |
| `window_seconds` | `60` | Bucket size for outcome counting |
| `cooldown_seconds` | `60` | How long to hold before probing for recovery |

The block is optional. Configs written before the fuse existed keep working on the defaults above — see the [upgrade guide](../advanced-usage/upgrade-guide-v3.md).

## Per-profile defaults

Each shipped [workload profile](workload-profiles.md) ships fuse tuning matched to its traffic shape:

| Profile | Threshold | Min samples | Window | Cooldown |
|---|---|---|---|---|
| `CriticalProfile` | 40% | 10 | 30s | 30s |
| `HighVolumeProfile` | 50% | 100 | 60s | 60s |
| `BalancedProfile` | 50% | 20 | 60s | 60s |
| `BurstyProfile` | 50% | 20 | 120s | 60s |
| `BackgroundProfile` | 60% | 10 | 300s | 300s |
| `ExclusiveProfile` | — | — | — | disabled |

The reasoning:

- **Critical** detects fastest and recovers fastest, because a tight SLA cannot absorb a long hold.
- **High volume** demands far more samples before acting — at thousands of jobs a minute, 20 samples is noise.
- **Bursty** widens the window, because a short one can be empty between bursts and never reach `min_samples`.
- **Background** widens it much further for the same reason, and tolerates a higher failure rate.
- **Exclusive** disables the fuse: a pinned queue runs exactly one worker by definition, so there is no scale-up to hold back.

## Tuning

**`min_samples` is the setting that matters most.** Set it too low and normal variance trips the fuse; too high and it never trips on a quiet queue. A useful starting point is *the number of jobs the queue processes in one window during normal traffic, divided by five*.

If your queue processes ~600 jobs/minute and you use a 60s window, `min_samples: 100` means the fuse acts on a sixth of a window's evidence — responsive without being twitchy.

**Check that `min_samples` is reachable at all.** A queue processing 5 jobs/minute with `window_seconds: 60` and `min_samples: 20` can never accumulate enough samples, and the fuse will never trip. Widen the window until `throughput_per_minute × (window_seconds / 60) × 2` comfortably exceeds `min_samples` — the `× 2` accounts for the two-bucket read.

**`cooldown_seconds` trades recovery speed against pressure on the dependency.** A short cooldown probes often, which finds recovery sooner but keeps poking a service that may need time to come back. Match it to how long your dependency typically takes to recover, not to how impatient you feel.

**`failure_threshold_percent` should sit well above your queue's normal failure rate.** If a queue normally runs at 15% failures, a 50% threshold gives comfortable headroom; a 20% threshold would trip on a bad afternoon.

## Events

Three events fire on state transitions — on transitions only, never on every cycle, so a sustained outage produces one alert rather than one per evaluation interval.

### `FuseTripped`

```php
namespace Cbox\LaravelQueueAutoscale\Events;

class FuseTripped
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $failureRate,
        public readonly int $samples,
        public readonly int $failures,
        public readonly float $thresholdPercent,
        public readonly int $heldAtWorkers,
    ) {}
}
```

**Use for:** incident alerting. This is a stronger signal than an SLA breach — the queue is not merely slow, its work is failing.

### `FuseProbing`

```php
class FuseProbing
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $probeWorkers,
        public readonly int $cooldownSeconds,
    ) {}
}
```

**Use for:** tracking how many recovery attempts an incident took.

### `FuseRecovered`

```php
class FuseRecovered
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $failureRate,
        public readonly int $samples,
    ) {}
}
```

**Use for:** all-clear notifications and incident duration metrics.

See [Alert on a Fuse Trip](../cookbook/alert-on-fuse-trip.md) for a paste-and-go listener, and [Event Handling](event-handling.md) for the rest of the autoscaler's events.

## Telemetry

With [`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry) installed, the fuse publishes automatically:

| Metric | Type | Notes |
|---|---|---|
| `queue_autoscale.fuse.state` | gauge | `0` closed, `1` half-open, `2` open. Labeled by `connection` and `queue`. |
| `queue_autoscale.fuse.trips` | counter | Incremented on each trip |

Plus OTLP events `queue_autoscale.fuse.tripped`, `.probing` and `.recovered`, carrying the full payloads above.

The state is one gauge with an encoded value rather than a boolean per state, so a dashboard reads current state from a single series instead of reconciling three that can disagree mid-transition. To alert on any tripped queue:

```text
queue_autoscale_fuse_state > 0
```

Scaling decisions made while the fuse is tripped also report `fuse` as their limiting factor on `queue_autoscale.capacity.max_workers`, and the decision `reason` string leads with the fuse note.

## Disabling the fuse

Globally, via env:

```bash
QUEUE_AUTOSCALE_FUSE_ENABLED=false
```

Per queue, in the profile block:

```php
'queues' => [
    'best-effort' => [
        'fuse' => ['enabled' => false],
    ],
],
```

The global switch is a master switch: it can turn the fuse off everywhere, but it never turns it on for a queue that opted out.

## What the fuse is not

**It is not a job-level circuit breaker.** It does not stop jobs from running, fail them fast, or wrap your HTTP calls. It only constrains how many workers the autoscaler spawns. If you want jobs to fail fast against a dead dependency, use job middleware — Laravel's own `ThrottlesExceptions`, or a dedicated package. The two compose well: the middleware protects each job, the fuse protects the scaling decision.

**It is not a replacement for SLA monitoring.** A tripped fuse means work is failing; an [SLA breach](monitoring.md) means work is late. Both are worth alerting on, for different reasons.

**It does not inspect your jobs.** Classification sees the thrown exception and the queue it came from, nothing else. If you need to decide based on the job's payload, that logic belongs in the job.

## Troubleshooting

### My queue is stuck at `workers.min` and the backlog is growing

Check whether the fuse is holding it:

```bash
php artisan queue:autoscale:debug --queue=<your-queue>
```

The `=== Failure Fuse ===` section reports the current state, the observed failure rate, and the thresholds it is being measured against. A held queue shows `State: open` and a `TRIPPED:` line.

If the failure rate shown is genuine, the fix is upstream — the queue is failing, and the fuse is doing its job. It will probe for recovery on its own; no manual intervention is needed. If the rate looks wrong, see the next entry.

The manager also logs the hold to its configured channel while it lasts:

```text
[warning] Autoscaling held back by failure fuse
  {"queue":"payments","current_workers":8,"target_workers":2,
   "reason":"fuse OPEN: 90.0% failure rate over 200 jobs — holding at workers.min..."}
```

That line is rate-limited by `alerting.cooldown_seconds` (5 minutes by default), so a long outage produces a periodic reminder rather than one line per evaluation cycle. It is a reminder, not a transition notice — for the transitions themselves, listen to the [events](#events).

The decision's `reason` string is also on every `ScalingDecisionMade` event, and shown per cycle when the manager runs with `-v`.

### The fuse trips during normal operation

Your `failure_threshold_percent` is too close to the queue's baseline failure rate, or `min_samples` is too low for the queue's volume. Raise the threshold, raise `min_samples`, or both.

If the failures are a routine, expected class — payload validation, business-rule rejections, rate limits you are happy to scale through — add them to [`ignored_exceptions`](#excluding-exceptions-that-say-nothing-about-capacity) instead of blunting the threshold for everything.

### The fuse never trips, even during a real outage

Three usual causes:

1. **`min_samples` is unreachable** for the queue's throughput at the configured window. See [Tuning](#tuning).
2. **The cache driver is `array`**, so the manager never sees what the workers recorded.
3. **The fuse is disabled** — check both `queue-autoscale.fuse.enabled` and the queue's own profile block.

### It took two minutes to react

Expected. See [Detection latency](#detection-latency).

### Recovery is slower than the outage

The probe needs `min_samples` outcomes before it can close the fuse, and it runs at `max(1, workers.min)` workers. On a low-throughput queue that can take several cooldown cycles. Lower `min_samples` if recovery speed matters more than confidence.
