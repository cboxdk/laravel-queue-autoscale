---
title: "Upgrading from v2 to v3"
description: "What v3 changed, which code references have to be rewritten, and what the config migration command really does"
weight: 20
---

# Upgrading from v2 to v3

v3 restructured the configuration objects, replaced the strategy and profile APIs, and added
forecasting, spawn-latency compensation and p95-based SLA signals. Upgrading is mostly a matter of
renaming things — but the config migration step is **not** automated for v2, so read Step 2
carefully.

## Step 1 — Update the package

```bash
composer require cboxdk/laravel-queue-autoscale:^3.0
```

v3 requires PHP **8.3+** and the `pcntl` and `posix` extensions. It runs on Laravel 11, 12 and 13.

## Step 2 — Migrate the config file

The published config shape changed in v3. There is a migration command, but **it only handles the
v1 shape**:

```bash
php artisan queue-autoscale:migrate-config
```

What it actually does, from `src/Commands/MigrateConfigCommand.php`:

| | |
|---|---|
| Default `--source` | `config/queue-autoscale.php` |
| Default `--destination` | `config/queue-autoscale.v2.php` |
| Direction | **v1 → v2** |
| v1 detection | `sla_defaults` is an array containing `max_pickup_time_seconds` |
| If detection fails | Warns `Source does not look like a v1 config. Skipping.` and exits successfully without writing anything |
| Output format | `var_export()`ed PHP array |

It does **not** write `queue-autoscale.v3.php`, and it does **not** perform the v2 → v3 step. If you
are coming from v2, the command will look at your config, decide it is not v1, and do nothing.

Coming from v1, it maps: `sla_defaults.max_pickup_time_seconds` → `sla.target_seconds`,
`min_workers`/`max_workers` → `workers.min`/`workers.max`, and `scale_cooldown_seconds` → the global
`scaling.cooldown_seconds`. Per-queue overrides get the same treatment plus `connection`. Anything
else is reported with a warning and dropped. `strategy` is rewritten to `HybridStrategy::class`
regardless of what it was.

### Migrating from v2 by hand

Publish the v3 config next to your existing one and port your values across:

```bash
php artisan vendor:publish --tag=queue-autoscale-config --force
```

The keys that need attention:

```php
// Was: a literal defaults array. Now: a ProfileContract class string OR a literal array.
'sla_defaults' => \Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile::class,

// Per queue: either a profile class string, or a partial override array deep-merged over sla_defaults.
'queues' => [
    'critical' => \Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile::class,
    'exports' => [
        'sla' => ['target_seconds' => 45],
        'workers' => ['max' => 20],
    ],
],

// A plain class string. An array value here breaks boot.
'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy::class,

// A list of class strings. Instances and closures are silently dropped.
'policies' => [
    \Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy::class,
    \Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy::class,
],
```

Note the trap in `queues`: the `['profile' => ..., 'overrides' => [...]]` shape is a **groups-only**
form. Under `queues`, those two keys are merged in as unrecognised junk and do nothing. See
[Configuration](../basic-usage/configuration.md) for the complete key reference.

## Step 3 — Update code references

| v2 | v3 |
|---|---|
| `$config->maxPickupTimeSeconds` | `$config->sla->targetSeconds` |
| `$config->minWorkers` | `$config->workers->min` |
| `$config->maxWorkers` | `$config->workers->max` |
| `$config->scaleCooldownSeconds` | `config('queue-autoscale.scaling.cooldown_seconds')` |
| `ProfilePresets::balanced()` | `BalancedProfile::class` (resolved at runtime) |
| `TrendScalingPolicy` enum cases | `ForecastPolicyContract` classes, e.g. `ModerateForecastPolicy::class` |
| `PredictiveStrategy` | `HybridStrategy` |

`ProfilePresets` and `PredictiveStrategy` no longer exist — references to them are fatal, not
deprecated.

Custom strategies must now type-hint the real DTOs:

```php
public function calculateTargetWorkers(
    \Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData $metrics,
    \Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration $config,
): int;
```

See [Custom Strategies](custom-strategies.md) for the full contract and metrics shape, and
[Policy Execution Internals](scaling-policies.md) for the policy contract.

## Step 4 — Verify

```bash
php artisan queue:autoscale -vvv
```

Watch a few evaluation cycles. Config problems surface as `InvalidConfigurationException` from the
`WorkerConfiguration`, `SlaConfiguration`, `ForecastConfiguration` and `GroupConfiguration`
constructors — for example `workers.max` below `workers.min`, an `sla.percentile` outside
`50|75|90|95|99`, or `sla.window_seconds` under `60`.

Forecasting needs no configuration to benefit from: it activates once the arrival-rate history has
enough samples and the configured forecast policy accepts the fit.

## What's new in v3

### Predictive core

`HybridStrategy` combines Little's Law with backlog drain and an arrival-rate forecast, corrects for
retry noise, subtracts measured worker spawn latency from the SLA budget, and uses a p95 pickup-time
signal from the pickup-time store instead of raw oldest-job age where enough samples exist. See
[How It Works](../basic-usage/how-it-works.md).

### `excluded` — leave these queues alone

Glob patterns matched with `fnmatch`. Excluded queues are never managed.

```php
'excluded' => ['externally-managed', 'legacy-*'],
```

### `groups` — multi-queue workers with strict priority

Each worker in a group runs `queue:work --queue=a,b,c`, giving Laravel's left-to-right priority
polling, and the group scales as one unit against aggregated metrics.

```php
'groups' => [
    'notifications' => [
        'queues' => ['email', 'sms', 'push'],
        'profile' => BalancedProfile::class,
    ],
],
```

A queue may appear in `queues` **or** in one group's `queues` list — never both, and never in two
groups. `GroupConfiguration::assertNoQueueConflicts()` rejects the configuration otherwise.

### `ExclusiveProfile` — pinned single-threaded queues

For queues that must process sequentially. The manager becomes a plain supervisor for them: the
pinned worker count is maintained and respawned on death, and no scaling signal is applied.

```php
'queues' => [
    'legacy-integration' => ExclusiveProfile::class,
],
```

See [Queue Topology](../basic-usage/queue-topology.md) for the conceptual model and
[Configuration](../basic-usage/configuration.md#worker-topology-v3) for the reference.

## Later v3 releases worth knowing about

### v3.3.0 — fractional CPU cores

`cboxdk/laravel-queue-metrics` moved to `^3.0` (bringing `system-metrics` v3) and the CPU fields on
`ClusterManagerState` became floats:

- `$cpuCores`: `int` → `float`
- `$cpuUsableCores`: `int` → `float` (computed as total cores minus reserved cores)
- `$cpuReservedCores`: `int` → `float`

The cluster summary fields `cpu_cores`, `cpu_usable_cores` and `cpu_reserved_cores` can therefore
carry values like `0.5` in cgroup-constrained environments. Update your type expectations if you
read them in a dashboard or event listener. No config changes, no migration step.

## Customising the pipeline

Every algorithm is class-replaceable through the container. For example, to substitute your own
forecaster:

```php
// AppServiceProvider::register()
$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract::class,
    \App\Autoscale\MyCustomForecaster::class,
);
```

See [Custom Strategies](custom-strategies.md) for the public extension points.
