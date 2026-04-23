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
- [Worker Topology (v2)](#worker-topology-v2)
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
php artisan vendor:publish --tag=laravel-queue-metrics-migrations
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

Five profiles ship with the package (`BalancedProfile`, `CriticalProfile`, `HighVolumeProfile`, `BurstyProfile`, `BackgroundProfile`, plus the single-worker `ExclusiveProfile`). See [Workload Profiles](workload-profiles.md) for what each one sets.

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

A fully-resolved queue configuration has four sections. You rarely need to see all of them — a profile populates them all — but here's the reference when you need to override specific keys:

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
        'tries' => 5,                // --tries= on queue:work
        'timeout_seconds' => 3600,   // --max-time= on queue:work
        'sleep_seconds' => 1,        // --sleep= on queue:work
        'shutdown_timeout_seconds' => 30,
        'scalable' => true,          // set false for pinned/exclusive queues
    ],
    'spawn_compensation' => [
        'enabled' => true,
        'fallback_seconds' => 2.0,
        'min_samples' => 3,
        'ema_alpha' => 0.3,
    ],
],
```

**The keys most operators touch:**

- `sla.target_seconds` — your SLA pickup target.
- `workers.min` / `workers.max` — floor and ceiling on concurrency.
- `workers.scalable = false` — pin the queue and bypass the scaling engine (see [ExclusiveProfile](#exclusiveprofile--pinned-single-worker-queues)).

Global scaling keys (cooldown, breach threshold, fallback job time) live under `scaling.*` at the top level — see the published config file.

## Worker Topology (v2)

v2 introduces three new capabilities on top of per-queue autoscaling. Each is expressed as its own top-level config key. See [Queue Topology](queue-topology.md) for the conceptual explanation; this section is the config reference.

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

**When to use:** queues managed by Horizon or another supervisor, throwaway queues during migrations, or queues with workers started manually via `queue:work` under systemd/supervisord.

### `groups` — multi-queue workers with strict priority

```php
'groups' => [
    'notifications' => [
        'queues'     => ['email', 'sms', 'push'],   // priority order
        'profile'    => BalancedProfile::class,     // optional — defaults to sla_defaults
        'connection' => 'redis',                    // optional — defaults to 'default'
        'mode'       => 'priority',                 // only supported mode in v2
        'overrides'  => [                           // optional partial override
            'sla' => ['target_seconds' => 45],
        ],
    ],
],
```

- Each worker spawned for the group invokes `queue:work redis --queue=email,sms,push` — Laravel polls them in that order per poll cycle.
- The group is the scaling unit. Metrics are aggregated across members (`pending`, `throughput`: summed; `oldest_job_age`: max). The SLA target is the group's SLA, not any individual queue's.
- A queue may appear in **at most one place**: either under `queues.{name}` or inside **one** group. Startup validation throws `InvalidConfigurationException` if this is violated.
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

The hybrid strategy combines:
- Little's Law for steady-state
- Trend prediction for growing loads
- Backlog drain for SLA breaches

### Custom Strategy

```php
'strategy' => \App\Autoscale\Strategies\MyCustomStrategy::class,
```

See [Custom Strategies](../advanced-usage/custom-strategies.md) for implementation guide.

### Strategy Parameters

Some strategies accept additional configuration:

```php
'strategy' => [
    'class' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy::class,
    'options' => [
        'trend_weight' => 0.7,        // How much to trust trend predictions
        'safety_margin' => 1.2,       // 20% buffer for uncertainty
        'min_trend_samples' => 3,     // Samples needed for trend analysis
    ],
],
```

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

- `ConservativeScaleDownPolicy` — limits scale-down to one worker per cycle (prevents thrashing)
- `AggressiveScaleDownPolicy` — allows rapid scale-down (for cost optimisation)
- `NoScaleDownPolicy` — never scales down (for strict capacity guarantees)
- `BreachNotificationPolicy` — logs SLA breach risks with built-in rate limiting (see [Alerting](../cookbook/_index.md))

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

Policies execute in the order listed. `beforeScaling()` hooks run top-to-bottom (each may modify the decision), then the scaling action fires, then `afterScaling()` hooks run top-to-bottom.

See [Scaling Policies](../advanced-usage/scaling-policies.md) for implementation guide.

## Manager Configuration

The AutoscaleManager orchestrates the entire autoscaling process.

### Evaluation Interval

```php
'evaluation_interval_seconds' => 30,  // Check every 30 seconds
```

How often to evaluate scaling decisions:
- **Lower values (10-30s)**: More responsive, higher resource usage
- **Higher values (60-120s)**: Less responsive, lower resource usage

Balance based on:
- Queue traffic patterns
- SLA requirements
- System resources

### Manager Options

```php
'manager' => [
    'evaluation_interval_seconds' => 30,
    'max_concurrent_evaluations' => 5,    // Parallel queue evaluations
    'enable_metrics_collection' => true,  // Collect performance data
    'metrics_retention_hours' => 24,      // How long to keep metrics
],
```

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

Queue names are keys into the `queues` map; the connection is resolved from your Laravel queue config. For one queue on a non-default connection, add a `connection` key:

```php
'queues' => [
    'notifications' => [
        'connection' => 'sqs',
        'sla' => ['target_seconds' => 30],
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
    'reserve_cpu_cores' => 1,           // Cores reserved for the OS/other services
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
```

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

Config validation runs at manager startup. If anything is wrong, `php artisan queue:autoscale` fails with a specific `InvalidConfigurationException` pointing at the offending key. The common ones:

- **`workers.min must be >= 0`** / **`workers.max (X) must be >= workers.min (Y)`** — inconsistent worker bounds.
- **`workers.scalable=false requires workers.min (X) to equal workers.max (Y)`** — non-scalable (pinned) configs must declare exactly one target count.
- **`workers.scalable=false requires workers.min >= 1`** — a pinned queue needs at least one worker.
- **`Group 'X' cannot use a non-scalable profile`** — you pointed a group at `ExclusiveProfile`; use a per-queue exclusive config instead.
- **`Queue 'X' is configured both in 'queues' and in group 'Y'`** — each queue may only appear once across `queues` and all groups.
- **`Queue 'X' appears in multiple groups (...)`** — a queue may only belong to one group.
- **`Group 'X' must declare at least one queue`** — the group's `queues` list was empty.

Fix the config and restart the manager.

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
