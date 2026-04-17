---
title: "Workload Profiles"
description: "The six shipped profile classes — what each one sets, and when to pick it"
weight: 16
---

# Workload Profiles

A profile is a PHP class whose `resolve()` method returns a pre-tuned bundle of SLA, forecast, worker, and spawn-compensation settings. Point a queue at a profile class and get a reasonable default without tuning individual keys.

Six profiles ship with the package:

| Profile | SLA | Min | Max | Percentile | Forecast policy | Intended use |
|---|---|---|---|---|---|---|
| **CriticalProfile** | 10s | 5 | 50 | p99 | Aggressive | Payments, order fulfilment |
| **HighVolumeProfile** | 20s | 3 | 40 | p95 | Moderate | Emails, batch notifications |
| **BalancedProfile** ⭐ | 30s | 1 | 10 | p95 | Moderate | General purpose default |
| **BurstyProfile** | 60s | 0 | 100 | p90 | Aggressive | Webhook storms, campaign fanouts |
| **BackgroundProfile** | 300s | 0 | 5 | p95 | Hint-only | Cleanup, analytics |
| **ExclusiveProfile** (v2) | 60s | 1 (pinned) | 1 (pinned) | p95 | Disabled | Sequential integrations |

`BalancedProfile` is the default `sla_defaults`. Change it only if your typical queue has a tighter or looser SLA than 30 seconds.

## Using a profile

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

'sla_defaults' => BalancedProfile::class,

'queues' => [
    'payments' => CriticalProfile::class,
],
```

Or override a single key on top of a profile:

```php
'queues' => [
    'exports' => [
        'sla' => ['target_seconds' => 45],
        'workers' => ['max' => 20],
    ],
],
```

The deep merge keeps every value the profile defines except what you explicitly overrode.

## The five autoscaling profiles

Each profile below lists the exact values from its `resolve()` method. All settings defaulted elsewhere (like `workers.timeout_seconds = 3600`, `spawn_compensation.enabled = true`) are omitted here for brevity — check the class source if you need the full shape.

### `CriticalProfile`

**Use for:** payment processing, order fulfilment, real-time user-facing operations where a 10-second pickup SLA is a hard requirement.

**Don't use for:** background work. `workers.min = 5` keeps five workers running 24/7.

```php
'sla' => [
    'target_seconds' => 10,
    'percentile' => 99,
    'window_seconds' => 120,
    'min_samples' => 20,
],
'workers' => ['min' => 5, 'max' => 50, 'sleep_seconds' => 1],
'forecast' => ['policy' => AggressiveForecastPolicy::class, 'horizon_seconds' => 60],
'spawn_compensation' => ['min_samples' => 3, 'ema_alpha' => 0.3],
```

Aggressive forecasting blends predicted traffic into the scaling decision, so spikes are met before backlog builds. The p99 signal tolerates one outlier per 100 jobs without over-reacting.

### `HighVolumeProfile`

**Use for:** high-throughput queues where absolute latency is less critical than keeping up — bulk emails, notifications, mass imports.

**Don't use for:** queues with tight per-job SLA requirements or spiky traffic.

```php
'sla' => [
    'target_seconds' => 20,
    'percentile' => 95,
    'window_seconds' => 300,
    'min_samples' => 50,
],
'workers' => ['min' => 3, 'max' => 40, 'sleep_seconds' => 2],
'forecast' => ['policy' => ModerateForecastPolicy::class, 'horizon_seconds' => 60],
```

Higher `min_samples` (50) means the autoscaler waits for more measurements before trusting the p95 — appropriate when throughput is high and noise is averaged out quickly.

### `BalancedProfile` ⭐

**Use for:** the default. Anything user-facing but not real-time: exports, synchronous-equivalent workflows, general-purpose queues.

**Don't use for:** queues where defaults don't fit either direction (too loose for critical, too tight for background).

```php
'sla' => [
    'target_seconds' => 30,
    'percentile' => 95,
    'window_seconds' => 300,
    'min_samples' => 20,
],
'workers' => ['min' => 1, 'max' => 10, 'sleep_seconds' => 3],
'forecast' => ['policy' => ModerateForecastPolicy::class, 'horizon_seconds' => 60],
```

This is the `sla_defaults` in the published config. Most apps never change it.

### `BurstyProfile`

**Use for:** queues with highly variable traffic — webhook receivers, campaign fanouts, anything where you go from 0 to 1000 jobs in seconds then back to idle.

**Don't use for:** steady-state high-throughput queues (use `HighVolumeProfile` instead — `BurstyProfile`'s `workers.min = 0` means cold-starts on every burst).

```php
'sla' => [
    'target_seconds' => 60,
    'percentile' => 90,
    'window_seconds' => 600,
    'min_samples' => 20,
],
'workers' => ['min' => 0, 'max' => 100, 'sleep_seconds' => 3],
'forecast' => ['policy' => AggressiveForecastPolicy::class, 'horizon_seconds' => 120],
```

Longer forecast horizon (120s) so the aggressive forecast catches ramps earlier. `min = 0` lets the queue scale fully to zero between bursts to save cost.

### `BackgroundProfile`

**Use for:** cleanup jobs, analytics batches, reports — anywhere a 5-minute pickup SLA is comfortably acceptable.

**Don't use for:** anything a user is waiting for.

```php
'sla' => [
    'target_seconds' => 300,
    'percentile' => 95,
    'window_seconds' => 900,
    'min_samples' => 20,
],
'workers' => ['min' => 0, 'max' => 5, 'sleep_seconds' => 10],
'forecast' => ['policy' => HintForecastPolicy::class, 'horizon_seconds' => 300],
```

`HintForecastPolicy` barely influences the scaling decision — for a 5-minute SLA queue, reactive scaling is fine, and the extra prediction machinery isn't worth the compute. `sleep_seconds = 10` also reduces idle-worker CPU.

## The pinned profile

### `ExclusiveProfile`

**Use for:** queues where jobs must run **one at a time in order**. Customer integrations that require single-connection semantics, legacy APIs with strict per-client rate limits, anything where two concurrent jobs would corrupt state.

**Don't use for:** anything you want the autoscaler to actually scale. This profile disables scaling entirely.

```php
'sla' => ['target_seconds' => 60, 'percentile' => 95, 'min_samples' => 20],
'workers' => ['min' => 1, 'max' => 1, 'scalable' => false],
'forecast' => ['policy' => DisabledForecastPolicy::class],
'spawn_compensation' => ['enabled' => false],
```

`scalable = false` flips the autoscaler into supervisor mode for this queue: it ensures exactly one live worker and respawns on death, but never evaluates scaling. SLA breach events still fire so operators see when the queue is falling behind — but no scaling happens, because the whole point is to preserve ordering.

See [Queue Topology → Exclusive Queues](queue-topology.md#exclusive-sequential-queues) for the full behaviour model.

## Custom profiles

If none of the shipped profiles matches your workload, write your own. It's a small class:

```php
<?php

namespace App\QueueAutoscale\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

final readonly class ReportsProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 90,
                'percentile' => 95,
                'window_seconds' => 600,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => ModerateForecastPolicy::class,
                'horizon_seconds' => 120,
                'history_seconds' => 600,
            ],
            'workers' => [
                'min' => 0,
                'max' => 8,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 5,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
```

Use it in config:

```php
'queues' => [
    'reports' => \App\QueueAutoscale\Profiles\ReportsProfile::class,
],
```

Start from the shipped profile closest to your target and tweak. Most common changes:

- **Different SLA target.** Change `sla.target_seconds`.
- **Different scaling aggressiveness.** Swap `forecast.policy` between `Aggressive`, `Moderate`, `Hint`, `Disabled`.
- **Different worker bounds.** Change `workers.min` and `workers.max`.
- **Pinned count > 1.** Set `workers.min = workers.max = N` and `workers.scalable = false`.

## See Also

- [Queue Topology](queue-topology.md) — when to use per-queue, groups, exclusive, excluded
- [Configuration](configuration.md) — the full config reference
- [How It Works](how-it-works.md) — the algorithms a profile's values tune
- [Custom Strategies](../advanced-usage/custom-strategies.md) — replacing entire scaling algorithms, not just tuning them
