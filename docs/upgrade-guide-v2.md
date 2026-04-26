---
title: "Upgrading from v1 to v2"
description: "Step-by-step migration guide for the breaking v2.0 release"
weight: 5
---

# Upgrading from v1 to v2

Version 2 is a ground-up redesign that introduces genuine forecasting, spawn-latency compensation, and p95-based SLA signals. This guide walks through the upgrade.

## Step 1 — Update the package

```bash
composer require cboxdk/laravel-queue-autoscale:^2.0
```

## Step 2 — Migrate the config file

```bash
php artisan queue-autoscale:migrate-config
```

This writes `config/queue-autoscale.v2.php` next to your current file. Review and replace.

## Step 3 — Update code references

| v1                                     | v2                                                |
|----------------------------------------|---------------------------------------------------|
| `$config->maxPickupTimeSeconds`        | `$config->sla->targetSeconds`                     |
| `$config->minWorkers`                  | `$config->workers->min`                           |
| `$config->maxWorkers`                  | `$config->workers->max`                           |
| `$config->scaleCooldownSeconds`        | `config('queue-autoscale.scaling.cooldown_seconds')` |
| `ProfilePresets::balanced()`           | `BalancedProfile::class` (resolved at runtime)    |
| `TrendScalingPolicy::MODERATE`         | `ModerateForecastPolicy::class`                   |
| `PredictiveStrategy`                   | `HybridStrategy`                                  |

## Step 4 — Verify

Run your test suite. The package now uses p95 pickup time over a sliding window, compensated for measured worker spawn latency. You do not need to do anything to benefit from forecasting — it activates automatically when your arrival rate history has 5+ samples and a high enough R² under the configured policy.

## What's New in v2

Three new topology capabilities, each additive and optional:

### `excluded` — leave these queues alone

Use when Horizon or another supervisor already manages a queue, or for throwaway migration queues. Glob patterns via `fnmatch`.

```php
'excluded' => ['horizon-managed', 'legacy-*'],
```

### `groups` — multi-queue workers with strict priority

When several queues share a workload budget and you want idle workers in one to absorb bursts on another, declare a group. Each worker runs `queue:work --queue=a,b,c` with Laravel's per-poll priority semantics.

```php
'groups' => [
    'notifications' => [
        'queues'  => ['email', 'sms', 'push'],
        'profile' => BalancedProfile::class,
    ],
],
```

A queue may appear in `queues` OR in a group's `queues` list — never both. Startup validation enforces this.

### `ExclusiveProfile` — pinned single-threaded queues

For queues that must process jobs sequentially (customer integrations, non-thread-safe APIs), use the new profile. The package becomes a process supervisor for the queue: exactly one live worker, respawned on death, never scaled.

```php
'queues' => [
    'legacy-integration' => ExclusiveProfile::class,
],
```

See [Queue Topology](basic-usage/queue-topology.md) for the conceptual model and decision guidance, and [Configuration](basic-usage/configuration.md#worker-topology-v2) for the config reference.

## Customising the pipeline

Every algorithm is class-replaceable. For example, to use your own forecaster:

```php
// In AppServiceProvider::register()
$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract::class,
    \App\MyCustomForecaster::class,
);
```

See [Custom Strategies](advanced-usage/custom-strategies.md) for the public extension points.
