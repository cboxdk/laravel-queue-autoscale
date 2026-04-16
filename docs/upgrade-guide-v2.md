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

## Customising the pipeline

Every algorithm is class-replaceable. For example, to use your own forecaster:

```php
// In AppServiceProvider::register()
$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract::class,
    \App\MyCustomForecaster::class,
);
```

See `docs/superpowers/specs/2026-04-16-predictive-autoscaling-v2-design.md` for the full architecture.
