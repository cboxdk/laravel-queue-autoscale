---
title: "Configuration"
description: "Queue Autoscale for Laravel configuration reference with SLA targets and worker limits"
weight: 11
---

# Configuration

Complete Queue Autoscale for Laravel configuration reference.

## Table of Contents
- [Prerequisites: Metrics Package Setup](#prerequisites-metrics-package-setup)
- [Basic Configuration](#basic-configuration)
- [Queue Configuration](#queue-configuration)
- [Worker Topology (v3)](#worker-topology-v3)
- [Strategy Configuration](#strategy-configuration)
- [Policy Configuration](#policy-configuration)
- [Manager Configuration](#manager-configuration)
- [Advanced Options](#advanced-options)
- [Environment Variables](#environment-variables)
- [Configuration Patterns](#configuration-patterns)

> **Reading tip:** the conceptual model for per-queue vs. group vs. exclusive vs. excluded workers lives in [Queue Topology](queue-topology.md). This page is the reference for **how** to express each of those in config.

## Prerequisites: Metrics Package Setup

Queue Autoscale for Laravel depends on `laravel-queue-metrics` for all queue discovery and metrics collection. **The autoscaler cannot function without proper metrics configuration.**

### Quick Setup

```bash
# Install metrics package (if not already installed)
composer require cboxdk/laravel-queue-metrics

# Publish configuration
php artisan vendor:publish --tag=queue-metrics-config

# Configure storage backend in .env
QUEUE_METRICS_STORAGE=redis        # Fast, in-memory (recommended)
# OR
QUEUE_METRICS_STORAGE=database     # Persistent storage
```

### Storage Configuration

**Redis (Recommended for Production):**

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
```

Ensure your Redis connection is configured in `config/database.php`.

**Database (For Historical Persistence):**

```env
QUEUE_METRICS_STORAGE=database
```

Then publish and run migrations:

```bash
php artisan vendor:publish --tag=queue-metrics-migrations
php artisan migrate
```

**📚 Full metrics package documentation:** [laravel-queue-metrics](https://github.com/cboxdk/laravel-queue-metrics)

---

## Basic Configuration

Publish the config:

```bash
php artisan vendor:publish --tag=queue-autoscale-config
```

The defaults work out of the box. You only need to touch the config when you want to override the default profile, add per-queue overrides, declare groups/excluded queues, or tune global scaling parameters.

### Minimal Configuration

```php
<?php

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    // Every queue discovered at runtime gets this profile unless overridden.
    'sla_defaults' => BalancedProfile::class,

    // Per-queue overrides. See "Queue Configuration" below.
    'queues' => [],
];
```

Six profiles ship with the package: five autoscaling ones (`BalancedProfile`, `CriticalProfile`, `HighVolumeProfile`, `BurstyProfile`, `BackgroundProfile`) plus the pinned single-worker `ExclusiveProfile`. See [Workload Profiles](workload-profiles.md) for what each one sets.

## Queue Configuration

A queue entry takes one of two shapes:

### Shape 1 — a profile class

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

'queues' => [
    'payments' => CriticalProfile::class,
],
```

Pick the profile whose SLA + worker bounds match what you want. Nothing else required.

### Shape 2 — a partial override array

When you want *almost* the defaults but with one or two changes, pass an array. It is deep-merged on top of `sla_defaults`:

```php
'queues' => [
    'exports' => [
        'sla' => ['target_seconds' => 45],
        'workers' => ['min' => 0, 'max' => 3],
    ],
],
```

### The nested config shape

A fully-resolved queue configuration has five sections. You rarely need to see all of them — a profile populates them all — but here's the reference when you need to override specific keys:

```php
'payments' => [
    'sla' => [
        'target_seconds' => 10,      // pickup SLA; the most important single number
        'percentile' => 99,          // which percentile to measure against (50–99)
        'window_seconds' => 120,     // rolling window for the percentile
        'min_samples' => 20,         // below this many samples we fall back to oldest_job_age
    ],
    'forecast' => [
        'forecaster' => \Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster::class,
        'policy' => \Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\AggressiveForecastPolicy::class,
        'horizon_seconds' => 60,
        'history_seconds' => 300,
    ],
    'workers' => [
        'min' => 5,                  // floor — autoscaler won't drop below this
        'max' => 50,                 // ceiling — autoscaler won't exceed this
        'tries' => 5,                    // --tries on queue:work
        'max_time_seconds' => 3600,      // --max-time: worker process lifetime
        'timeout_seconds' => 900,        // --timeout: how long one job may run
        'sleep_seconds' => 1,            // --sleep when the queue is empty
        'shutdown_timeout_seconds' => 30,  // ditto
        'scalable' => true,          // set false for pinned/exclusive queues
    ],
    'spawn_compensation' => [
        'enabled' => true,
        'fallback_seconds' => 2.0,
        'min_samples' => 3,
        'ema_alpha' => 0.3,
    ],
    'fuse' => [
        'enabled' => true,
        'failure_threshold_percent' => 40.0,  // trip at/above this failure rate
        'min_samples' => 10,                  // outcomes needed before the rate is trusted
        'window_seconds' => 30,               // bucket size for outcome counting
        'cooldown_seconds' => 30,             // hold this long before probing for recovery
    ],
],
```

The `fuse` block is optional — configs written before the fuse existed keep working on package defaults. See [Failure Fuse](failure-fuse.md) for what it does and how to tune it.

> **Every worker setting is per queue.** There used to be a second, global `queue-autoscale.workers`
> block holding the same keys, and only it reached a spawned worker — so a `tries` or
> `sleep_seconds` set on a profile was validated and then ignored. The global block is gone and the
> profile is the only surface, so what a queue declares is what its workers run with.
>
> `workers.health_check_interval_seconds` is also in the published config but has no callers — worker
> liveness is checked once per evaluation cycle.

**The keys most operators touch:**

- `sla.target_seconds` — your SLA pickup target. **Do not set below 5 seconds.** The worker poll loop and job pickup overhead impose a hard floor of ~3-5s on pickup time, and targets below this will produce flaky breach events. Profiles with `workers.min = 0` have an additional ~5-7s scale-from-zero overhead. See [Understanding SLA Timing](how-it-works.md#understanding-sla-timing).
- `workers.min` / `workers.max` — floor and ceiling on concurrency.
- `workers.scalable = false` — pin the queue and bypass the scaling engine (see [ExclusiveProfile](#exclusiveprofile--pinned-single-worker-queues)).

Global scaling keys (cooldown, breach threshold, fallback job time) live under `scaling.*` at the top level — see the published config file. The fuse's two infrastructure settings live under `fuse.*` at the top level; its thresholds are per-queue, in the block above.

## Worker Topology (v3)

v3 introduces three new capabilities on top of per-queue autoscaling. Each is expressed as its own top-level config key. See [Queue Topology](queue-topology.md) for the conceptual explanation; this section is the config reference.

### `excluded` — queues this package ignores

```php
'excluded' => [
    'horizon-managed',   // exact match
    'legacy-*',          // fnmatch glob
    'test-?',            // fnmatch glob (single char)
],
```

- Patterns use PHP's `fnmatch()` semantics.
- An excluded queue is never discovered, evaluated, spawned, or terminated — even if the metrics package reports activity for it.
- The first time the manager sees an excluded queue in a cycle, it logs a single `info` line so you can confirm.
- Exclusion wins over everything: if you put the same name in both `queues` and `excluded`, it is excluded.

**When to use:** queues managed by another supervisor, throwaway queues during migrations, or queues with workers started manually via `queue:work` under systemd/supervisord.

### `groups` — multi-queue workers with strict priority

```php
'groups' => [
    'notifications' => [
        'queues'     => ['email', 'sms', 'push'],   // priority order
        'profile'    => BalancedProfile::class,     // optional — defaults to sla_defaults
        'connection' => 'redis',                    // optional — defaults to 'default'
        'mode'       => 'priority',                 // the only supported mode
        'overrides'  => [                           // optional partial override
            'sla' => ['target_seconds' => 45],
        ],
    ],
],
```

> `profile` + `overrides` is a **groups-only** shape, read by `GroupConfiguration::fromConfig()`. Using those two keys inside a `queues.{name}` entry silently does nothing — see [Workload Profiles → Using a profile](workload-profiles.md#using-a-profile).

- Each worker spawned for the group invokes `queue:work redis --queue=email,sms,push` — Laravel polls them in that order per poll cycle.
- The group is the scaling unit. Metrics are aggregated across members (`pending`, `throughput`: summed; `oldest_job_age`: max). The SLA target is the group's SLA, not any individual queue's.
- A queue may appear in **at most one place**: either under `queues.{name}` or inside **one** group. `GroupConfiguration::assertNoQueueConflicts()` throws `InvalidConfigurationException` if this is violated — the manager catches it, logs it at critical level, and runs with all groups disabled until you restart it.
- Groups cannot use `ExclusiveProfile`. A pinned group is a contradiction — use a per-queue exclusive config instead.

**When to use:** queues that share a failure domain and have compatible SLA expectations, where you want idle capacity in one queue to absorb bursts on another without paying spawn latency.

### `ExclusiveProfile` — pinned single-worker queues

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;

'queues' => [
    'legacy-integration' => ExclusiveProfile::class,
],
```

- `workers.min = 1`, `workers.max = 1`, `workers.scalable = false`.
- The manager never evaluates scaling for this queue. Instead, it enforces exactly one live worker: respawns on death, terminates any duplicates.
- SLA breach events still fire for observability (operators need to know when a sequential queue falls behind) but scaling **will not** happen — the whole point is to preserve order.
- Jobs run strictly one at a time, in the order the queue driver delivers them.

**When to use:** third-party integrations that require single-connection semantics, customer workflows that assume jobs run in order, or any queue where two concurrent jobs would corrupt state.

> Custom variation: a `PinnedProfile` with `min == max == N` and `scalable: false` would enforce "exactly N workers, always." The `WorkerConfiguration` constructor validates this invariant. We ship `ExclusiveProfile` (N = 1) because it covers the most common case; write your own profile class if you need N > 1.

## Strategy Configuration

Strategies determine HOW workers are calculated. The package includes a hybrid strategy by default.

### Using Default Strategy

```php
'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy::class,
```

The hybrid strategy takes the **maximum of two** calculations — Little's Law for steady state, and backlog drain for SLA protection. Forecasting is not a third term: it feeds the arrival rate that Little's Law consumes. See [How It Works](how-it-works.md#2-calculation-phase).

### Custom Strategy

```php
'strategy' => \App\Autoscale\Strategies\MyCustomStrategy::class,
```

See [Custom Strategies](../advanced-usage/custom-strategies.md) for implementation guide.

### Strategy Parameters

There are none. `queue-autoscale.strategy` is a **plain class string** — `AutoscaleConfiguration::strategyClass()` reads it as a string and the service provider resolves it from the container. Writing it as an array (`['class' => ..., 'options' => [...]]`) is not understood and breaks the binding at boot.

Algorithm tuning lives under the global `scaling` key instead:

```php
'scaling' => [
    'fallback_job_time_seconds' => env('QUEUE_AUTOSCALE_FALLBACK_JOB_TIME', 2.0),
    'breach_threshold' => 0.5,   // ratio of the SLA window, not a percentage
    'cooldown_seconds' => 60,    // anti-flapping only, see below
],
```

Other shipped strategies: `BacklogOnlyStrategy`, `ConservativeStrategy`, `SimpleRateStrategy`. `ConservativeStrategy` carries its own hard-coded 25% safety buffer and 0.75 breach threshold as class constants — it does not read `scaling.breach_threshold`.

## Policy Configuration

Policies add cross-cutting concerns (notifications, logging, etc.) to scaling operations.

### Default Policies

The shipped default policies (set in the published config):

```php
'policies' => [
    \Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy::class,
    \Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy::class,
],
```

Available policy classes:

- `ConservativeScaleDownPolicy` — limits scale-down to `max(1, ceil(currentWorkers * 0.25))` per cycle: 25% of the current count, at least one worker
- `AggressiveScaleDownPolicy` — forces the full strategy target when the queue is idle and already at 1 or fewer workers; otherwise passes scale-down through untouched. Intended to be listed **after** `ConservativeScaleDownPolicy` so it can override it
- `NoScaleDownPolicy` — blocks scale-down, except when `currentWorkers` exceeds the host's capacity ceiling (resource-forced scale-down is allowed through). Takes a `CapacityCalculator` via constructor injection
- `BreachNotificationPolicy` — never modifies a decision; in `afterScaling()` it logs SLA breach risk and high SLA utilisation, gated by `AlertRateLimiter` (see [Alerting](../cookbook/_index.md))

Entries must be **class strings**. `PolicyExecutor` filters the array to `is_string($policy) && class_exists($policy)`, so a policy *instance* or a closure is silently dropped. Classes are resolved through `app()`, so constructor injection works.

Resource constraints and cooldown enforcement are built into the scaling engine itself, not expressed as policies — you don't configure them here.

### Adding Custom Policies

```php
'policies' => [
    // Shipped defaults
    \Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy::class,
    \Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy::class,

    // Your own policies — any class implementing ScalingPolicy
    \App\Autoscale\Policies\SlackNotificationPolicy::class,
    \App\Autoscale\Policies\CostOptimizationPolicy::class,
],
```

### Policy Order

Policies execute in the order listed, **after** the strategy, the capacity constraint, the config bounds and the failure fuse have already produced a `ScalingDecision`. `beforeScaling()` hooks run top-to-bottom and each sees the previous policy's modified decision; then the scaling action fires; then `afterScaling()` hooks run top-to-bottom.

An exception thrown by a policy is caught and logged to `manager.log_channel` — it does not abort scaling.

See [Scaling Policies](../advanced-usage/scaling-policies.md) for implementation guide.

## Manager Configuration

The AutoscaleManager orchestrates the entire autoscaling process.

### Evaluation Interval

The interval is set **on the command line**, and nowhere else:

```bash
php artisan queue:autoscale --interval=5   # 5 is the default
```

> `manager.evaluation_interval_seconds` is present in the published config file but **has no effect**. `AutoscaleConfiguration::evaluationIntervalSeconds()` exists and returns it, but nothing calls that method — the running loop uses the value passed from `--interval`. Set the interval in your supervisor or systemd unit.

Lower values (2-5s) react faster and cost more manager CPU; higher values (15-60s) are cheaper and slower. Keep the interval well below your tightest `sla.target_seconds`.

### Manager Options

```php
'manager' => [
    'evaluation_interval_seconds' => 5,   // NOT READ — see above
    'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
    'restart_scope' => env('QUEUE_AUTOSCALE_RESTART_SCOPE'),
    'honor_queue_restart' => env('QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART', true),
],
```

`restart_scope` controls the cache key used by `php artisan queue:autoscale:restart`. Leave it unset for the default `app.name` + `app.env` scope. Set `QUEUE_AUTOSCALE_RESTART_SCOPE` when multiple apps share the same cache backend and need isolated restart signals.

`honor_queue_restart` (default `true`) makes the manager also exit gracefully when Laravel's own `php artisan queue:restart` signal is issued, so standard deploy pipelines restart it automatically. Set it to `false` if `queue:restart` must only affect separately-supervised `queue:work` daemons. Note that `illuminate:queue:restart` is a global (unscoped) cache key — in multi-app setups sharing one cache backend, another app's `queue:restart` will also restart this manager; set `honor_queue_restart` to `false` (and use `queue:autoscale:restart` with `restart_scope`) to isolate.

## Advanced Options

### Multiple queues with different SLAs

Pick the profile that matches each queue's SLA:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

'sla_defaults' => BalancedProfile::class,

'queues' => [
    'critical'   => CriticalProfile::class,     // 10s SLA, 5-50 workers
    'default'    => BalancedProfile::class,     // 30s SLA, 1-10 workers
    'background' => BackgroundProfile::class,   // 300s SLA, 0-5 workers
],
```

### Per-queue overrides

When a profile is almost right but you want to adjust one or two values, pass an array. It deep-merges on top of `sla_defaults`:

```php
'queues' => [
    'exports' => [
        'sla' => ['target_seconds' => 45],
        'workers' => ['min' => 0, 'max' => 3],
    ],
],
```

### Multiple queue connections

Queue names are keys into the `queues` map. `AutoscaleConfiguration::configuredQueues()` reads an optional `connection` key from each entry (defaulting to `'default'`) so the manager knows which connection to seed metrics for:

```php
'queues' => [
    'notifications' => [
        'connection' => 'sqs',
        'sla' => ['target_seconds' => 30],
    ],
],
```

### Per-queue resource estimates

A queue whose workers are unusually heavy can declare its own capacity footprint. These are cold-start hints — once enough measured samples exist, measured values take precedence:

```php
'queues' => [
    'video-encode' => [
        'resources' => [
            'cpu_cores' => 0.5,   // defaults to limits.worker_cpu_core_estimate
            'memory_mb' => 2048,  // defaults to limits.worker_memory_mb_estimate
        ],
    ],
],
```

### Resource limits (global)

Caps are under the top-level `limits` key:

```php
'limits' => [
    'max_cpu_percent' => 85,           // Skip spawning when host CPU ≥ this
    'max_memory_percent' => 85,        // Skip spawning when host memory ≥ this
    'worker_memory_mb_estimate' => 128, // Assumed memory footprint per worker
    'worker_cpu_core_estimate' => 0.2,  // Baseline CPU cores per worker (fallback)
    'reserve_cpu_cores' => 0.2,         // Cores reserved for the OS/other services
],
```

These apply to every queue and group — they are how the package avoids spawning workers that would destabilise the host. See [Resource Constraints](../algorithms/resource-constraints.md) for the math.

### Business-hours scheduling

The config file is plain PHP, so any runtime logic is available:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;

$isBusinessHours = now()->isWeekday() && now()->hour >= 9 && now()->hour < 17;

'queues' => [
    'exports' => $isBusinessHours ? CriticalProfile::class : BackgroundProfile::class,
],
```

**Gotcha:** config is read once per manager start. Business-hours swaps require you to schedule a manager restart when the window changes (e.g. at 09:00 and 17:00).

## Environment Variables

A small set of environment variables is wired into the shipped config file. Anything else is a plain PHP key — change the file instead.

```bash
# Enable/disable the manager
QUEUE_AUTOSCALE_ENABLED=true

# Optional explicit manager/node ID override.
# Leave unset to use the built-in auto-generated node identity.
QUEUE_AUTOSCALE_MANAGER_ID=web-01

# Optional cache scope for php artisan queue:autoscale:restart.
# Set this if multiple apps share the same cache backend.
QUEUE_AUTOSCALE_RESTART_SCOPE=my-app-production

# Set to false if php artisan queue:restart must NOT restart the manager
QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART=true

# Optional signal backends.
# auto => null/no-op on single host, Redis-backed in cluster mode
# redis => force Redis-backed signal storage
# null => force fallback/no-op signal storage
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto

# Enable only when multiple managers run against the same queues
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false

# Fallback job time when metrics aren't available yet (seconds)
QUEUE_AUTOSCALE_FALLBACK_JOB_TIME=2.0

# Alert cooldown for BreachNotificationPolicy / AlertRateLimiter (seconds)
QUEUE_AUTOSCALE_ALERT_COOLDOWN=300

# Log channel the manager writes to
QUEUE_AUTOSCALE_LOG_CHANNEL=stack

# Failure fuse
QUEUE_AUTOSCALE_FUSE_ENABLED=true
QUEUE_AUTOSCALE_FUSE_STORE=auto        # auto|cache|null|FQCN

# Telemetry (no-op unless cboxdk/laravel-telemetry is installed)
QUEUE_AUTOSCALE_TELEMETRY_ENABLED=true
QUEUE_AUTOSCALE_TELEMETRY_CACHE_TTL=10

# Cluster tuning (only meaningful with cluster mode enabled)
QUEUE_AUTOSCALE_CLUSTER_HEARTBEAT_TTL=15
QUEUE_AUTOSCALE_CLUSTER_LEADER_LEASE=15
QUEUE_AUTOSCALE_CLUSTER_RECOMMENDATION_TTL=30
QUEUE_AUTOSCALE_CLUSTER_SUMMARY_TTL=30
QUEUE_AUTOSCALE_DECISION_HISTORY=3600
QUEUE_AUTOSCALE_DECISION_HISTORY_MAX=10000
```

That is the complete list. There is no `QUEUE_AUTOSCALE_EVALUATION_INTERVAL`, `QUEUE_AUTOSCALE_MIN_WORKERS`, `QUEUE_AUTOSCALE_MAX_WORKERS`, `QUEUE_AUTOSCALE_COOLDOWN` or `QUEUE_AUTOSCALE_MAX_PICKUP_TIME` — those keys are plain PHP values in the config file, or (for the interval) a CLI flag.

Per-queue SLA targets are **not** env-driven — they live in profile classes or queue-level override arrays. If you need per-queue env configuration, author a custom Profile class that reads env inside `resolve()`.

### Signal backend modes

- `QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto` keeps single-host mode Redis-free and switches to Redis automatically in cluster mode.
- `QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto` follows the same rule for spawn-latency compensation.
- Set either key to `redis` if you want Redis-backed predictive signals on a single host.
- Set either key to `null` if you want to force fallback behaviour even when Redis exists.

## Configuration Patterns

### Conservative — stability over responsiveness

Use `BalancedProfile` with a wider cooldown:

```php
'sla_defaults' => BalancedProfile::class,
'scaling' => ['cooldown_seconds' => 120],
```

### Aggressive — fast reactions to bursts

Use `CriticalProfile` (10s SLA, p99, short cooldown). Nothing else to tune — the profile's forecast policy is already aggressive.

### Cost-optimised — can scale to zero

Use `BackgroundProfile` (min=0, max=5) for queues that can tolerate multi-minute SLA:

```php
'queues' => [
    'cleanup' => BackgroundProfile::class,
],
```

### Multi-tier

Pick a profile per tier:

```php
'queues' => [
    'tier-1-realtime'   => CriticalProfile::class,
    'tier-2-user-facing' => HighVolumeProfile::class,
    'tier-3-standard'    => BalancedProfile::class,
    'tier-4-background'  => BackgroundProfile::class,
],
```

## Configuration Validation

There is no whole-config validation pass, and **a bad config does not fail `php artisan queue:autoscale` at startup**. Validation happens in constructors, during the evaluation cycle, and the manager keeps running.

### What actually throws

`WorkerConfiguration`, `SlaConfiguration` and `GroupConfiguration` guard their own invariants and throw `InvalidConfigurationException`:

- **`workers.min must be >= 0`** / **`workers.max (X) must be >= workers.min (Y)`** — inconsistent worker bounds.
- **`workers.tries must be >= 1`**, **`workers.max_time_seconds must be > 0`**, **`workers.timeout_seconds must be > 0`**.
- **`workers.timeout_seconds must be less than workers.max_time_seconds`** — a job that may outlive its own worker process can never finish.
- **`workers.scalable=false requires workers.min (X) to equal workers.max (Y)`** — non-scalable (pinned) configs must declare exactly one target count.
- **`workers.scalable=false requires workers.min >= 1`** — a pinned queue needs at least one worker.
- **`sla.target_seconds must be > 0`**, **`sla.percentile must be one of 50, 75, 90, 95, 99`**, **`sla.window_seconds must be >= 60`**, **`sla.min_samples must be >= 1`**.
- **`Group 'X' must declare at least one queue`**.
- **`Group 'X' has unsupported mode '...'`** — only `'priority'` is supported.
- **`Group 'X' cannot use a non-scalable profile`** — you pointed a group at `ExclusiveProfile`; use a per-queue exclusive config instead.
- **`Group 'X' lists queue 'Y' more than once`**.
- **`Queue 'X' is configured both in 'queues' and in group 'Y'`** and **`Queue 'X' appears in multiple groups (...)`** — from `GroupConfiguration::assertNoQueueConflicts()`.

A numerically-indexed `queues` array throws `InvalidArgumentException` from `AutoscaleConfiguration::configuredQueues()` — keys must be queue names.

### What happens when one throws

- **Per-queue config errors** surface during the evaluation cycle. The manager's run loop wraps every cycle in a `catch (\Throwable)`, logs `Autoscale evaluation failed` with the message and trace to `manager.log_channel`, sleeps, and runs the next cycle. You get one error line per interval, not a crash.
- **Group conflicts** are checked once and cached. On failure the manager logs `Group configuration is invalid — groups disabled until manager restart` at **critical** level, disables all groups for the lifetime of the process, and continues with per-queue autoscaling.

### The only real startup failures

`php artisan queue:autoscale` exits immediately in exactly two cases:

- `queue-autoscale.enabled` is false — prints `Queue autoscale is disabled in config` and returns a failure code.
- The host manager lock cannot be acquired, because another manager for this app is already running on this host. Use `--replace` to take over its lock.

Fix the config and restart the manager. Watch the log — the manager will not tell you on stdout.

## Configuration Testing

There's no separate dry-run command — the manager evaluates on a fixed interval. To test a config change without a deploy:

```bash
# Run the manager in very-verbose mode. It prints every decision with
# reasoning, but only spawns/terminates when the decision differs from
# the current worker count.
php artisan queue:autoscale -vvv --interval=5

# In another terminal, push some representative work onto the target
# queue. Anything your app already dispatches works — for a quick smoke
# test, queued closures via tinker:
php artisan tinker
>>> for ($i = 0; $i < 50; $i++) { dispatch(function () { sleep(1); })->onQueue('critical'); }
```

Watch the manager output. If the decisions surprise you, inspect the debug state directly:

```bash
php artisan queue:autoscale:debug --queue=critical --connection=redis
```

## See Also

- [How It Works](how-it-works.md) - Understanding the scaling algorithm
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Writing custom scaling strategies
- [Scaling Policies](../advanced-usage/scaling-policies.md) - Implementing scaling policies
- [Deployment](../advanced-usage/deployment.md) - Production deployment guide
- [Monitoring](monitoring.md) - Monitoring and observability
