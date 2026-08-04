---
title: "Installation"
description: "Install Queue Autoscale for Laravel, publish its config, wire up the metrics package and start the daemon"
weight: 1
---

# Installation

This guide walks you through installing and configuring Queue Autoscale for Laravel in your application.

## Requirements

From the package's `composer.json`:

| Requirement | Constraint |
|---|---|
| PHP | `^8.3 \| ^8.4 \| ^8.5` |
| `ext-pcntl` | required — the manager uses POSIX signals |
| `ext-posix` | required — worker liveness checks and termination |
| `illuminate/contracts` | `^11.0 \|\| ^12.0 \|\| ^13.0` |
| `cboxdk/laravel-queue-metrics` | `^3.0` |
| `symfony/process` | `^7.0 \|\| ^8.0` |
| `spatie/laravel-package-tools` | `^1.16` |

Notes:

- `cboxdk/system-metrics` is **not** a direct requirement. It arrives transitively through `cboxdk/laravel-queue-metrics`, and the capacity calculator uses it from there.
- `cboxdk/laravel-telemetry` is optional (dev/suggest only). Install it if you want the autoscaler's gauges, counters and OTLP events; everything is a no-op without it. It requires Laravel 12+.
- `ext-pcntl` and `ext-posix` are usually absent on Windows. Run the manager on Linux or macOS.

## Step 1: Install Package

```bash
composer require cboxdk/laravel-queue-autoscale
```

The package registers its service provider through Laravel's auto-discovery.

## Step 2: Publish Configuration

The fastest path is the interactive installer:

```bash
php artisan queue:autoscale:install
```

Its full signature:

```text
queue:autoscale:install {--topology=} {--metrics-connection=} {--publish-migrations}
                        {--write-env} {--env-file=} {--force} {--no-publish}
```

`--topology=` accepts one of three presets: `single-low`, `single-redis`, `cluster`.

The installer will:

- publish the `queue-autoscale` and `queue-metrics` config files
- guide you to the right preset for single-host vs cluster mode
- recommend the correct `QUEUE_METRICS_*` and `QUEUE_AUTOSCALE_*` env values
- optionally write those values into `.env` (`--write-env`)
- publish queue-metrics database migrations when you choose the low-traffic database preset (`--publish-migrations`)

If you prefer the manual path, publish the configuration file yourself:

```bash
php artisan vendor:publish --tag=queue-autoscale-config
```

This creates `config/queue-autoscale.php` with sensible defaults.

## Step 3: Set Up the Metrics Package

Queue Autoscale requires `cboxdk/laravel-queue-metrics` for queue discovery and metrics collection. It is installed automatically as a dependency, but it needs its own configuration.

### Publish its configuration

```bash
php artisan vendor:publish --tag=queue-metrics-config
```

### Choose a storage backend

**Option A: Redis** — fast, in-memory, recommended for production:

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
```

Ensure the Redis connection exists in `config/database.php`.

**Option B: Database** — persistent historical metrics:

```env
QUEUE_METRICS_STORAGE=database
```

Then publish and run its migrations:

```bash
php artisan vendor:publish --tag=queue-metrics-migrations
php artisan migrate
```

## Step 4: Choose Your Deployment Shape

Cluster mode is optional. When enabled it requires Redis for manager coordination; managers auto-join the cluster from the shared app and queue configuration, so there is no cluster ID, seed list or host registration step.

**If you run a single autoscale manager, Redis is not required by this package.** Single-host autoscaling works with non-Redis queue backends such as `database` and `sqs`.

The two signal backends default to `auto`:

- In single-host mode, `auto` resolves to null/no-op implementations, so the manager stays Redis-free.
- In cluster mode, `auto` resolves to Redis-backed implementations automatically.
- Set either to `redis` explicitly if you want Redis-backed predictive signals on a single host.

`php artisan queue:autoscale:install --topology=` maps directly to these three presets and prevents invalid combinations.

### Option A: `single-low` — single host, no Redis

Good for low-traffic environments, database-backed metrics, and queues on `database` / `sqs` / similar backends.

```env
QUEUE_METRICS_STORAGE=database
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto
```

### Option B: `single-redis` — single host, Redis-backed predictive signals

Use this when you want pickup-time percentiles and shared spawn-latency tracking even though you only run one manager.

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=redis
```

### Option C: `cluster` — multiple managers

Required when you run multiple `queue:autoscale` managers against the same queues.

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
QUEUE_AUTOSCALE_CLUSTER_ENABLED=true
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto
```

Only one `queue:autoscale` process is allowed per app per host, in either mode. See [Cluster Scaling](cluster-scaling.md) for how leadership and host recommendations work.

## Step 5: Configure Basic Settings

Edit `config/queue-autoscale.php`. The defaults already work for most apps (`BalancedProfile` as the default: 30s SLA, 1–10 workers). Adjust only when you want different behaviour for specific queues:

```php
<?php

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    // Every queue gets this profile unless overridden below.
    'sla_defaults' => BalancedProfile::class,

    // Per-queue entries: a profile class OR a literal partial-override array.
    'queues' => [
        'payments' => CriticalProfile::class,          // 10s SLA, 5-50 workers
        'emails'   => ['sla' => ['target_seconds' => 60]],
    ],
];
```

See [Workload Profiles](workload-profiles.md) for the full list of shipped profiles, and [Configuration](configuration.md) for the full nested key reference.

## Step 6: Start the Autoscaler

```bash
php artisan queue:autoscale
```

Full signature:

```text
queue:autoscale {--interval=5} {--replace}
```

- `--interval=` sets the evaluation interval in seconds. **This is the only place the interval is set** — `manager.evaluation_interval_seconds` in the config file is not read by anything.
- `--replace` stops the existing local manager and takes over its host lock. Without it, starting a second manager for the same app on the same host fails.

The autoscaler will:

1. Discover queues via the metrics package, plus any queue or group you declared in config
2. Evaluate scaling decisions every `--interval` seconds (5 by default)
3. Spawn and terminate `queue:work` workers automatically
4. Log scaling decisions and actions to `manager.log_channel`

### Running with Supervisor

For production, use Supervisor to keep the autoscaler running:

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php /path/to/artisan queue:autoscale --interval=5
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/logs/autoscale.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start queue-autoscale:*
```

On deploy, your standard `php artisan queue:restart` step is enough: with `manager.honor_queue_restart` at its default `true`, the manager honours that signal on its next evaluation tick and exits gracefully, and Supervisor starts it again from the new release. Use `php artisan queue:autoscale:restart` instead if you need a restart signal scoped to autoscale managers only.

## Verification

### See what the autoscaler sees

```bash
php artisan queue:autoscale:debug --queue=default --connection=redis
```

Full signature: `queue:autoscale:debug {--queue=default} {--connection=}`.

If the numbers shown here are wrong or zero, the problem is with metrics collection, not with the autoscaler itself.

### Run the manager in verbose mode

```bash
php artisan queue:autoscale -vv
```

Every evaluation cycle prints the decision, the limiting factor, and the scaling action. Let it run for a minute while you push some work onto the queue — any real job from your app will do. For a quick smoke test via tinker:

```bash
php artisan tinker
>>> for ($i = 0; $i < 50; $i++) { dispatch(function () { sleep(1); }); }
```

You should see the manager scale up and drain the backlog, then scale back down gradually as `ConservativeScaleDownPolicy` releases workers 25% at a time.

## Available Commands

| Command | Purpose |
|---|---|
| `queue:autoscale` | The daemon. `--interval=5`, `--replace`, plus `-v`/`-vv`/`-vvv` |
| `queue:autoscale:debug` | Dump queue state and metrics. `--queue=`, `--connection=` |
| `queue:autoscale:cluster` | Cluster leader, managers, capacity, workload targets. `--json` |
| `queue:autoscale:install` | Interactive installer (see Step 2) |
| `queue:autoscale:restart` | Signal running managers to restart gracefully |
| `queue-autoscale:migrate-config` | Translate a **v1** config file to v2 shape. `--source=`, `--destination=` |

## Troubleshooting

For a symptom-indexed guide (jobs piling up, workers dying, flapping, etc.), see [Troubleshooting](troubleshooting.md).

## Environment Variables

The env vars the shipped config actually reads:

```env
# Manager
QUEUE_AUTOSCALE_ENABLED=true
QUEUE_AUTOSCALE_MANAGER_ID=
QUEUE_AUTOSCALE_LOG_CHANNEL=stack
QUEUE_AUTOSCALE_RESTART_SCOPE=
QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART=true

# Signal backends: auto|redis|null|FQCN
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto

# Failure fuse
QUEUE_AUTOSCALE_FUSE_ENABLED=true
QUEUE_AUTOSCALE_FUSE_STORE=auto

# Scaling + alerting
QUEUE_AUTOSCALE_FALLBACK_JOB_TIME=2.0
QUEUE_AUTOSCALE_ALERT_COOLDOWN=300

# Cluster
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_CLUSTER_HEARTBEAT_TTL=15
QUEUE_AUTOSCALE_CLUSTER_LEADER_LEASE=15
QUEUE_AUTOSCALE_CLUSTER_RECOMMENDATION_TTL=30
QUEUE_AUTOSCALE_CLUSTER_SUMMARY_TTL=30
QUEUE_AUTOSCALE_DECISION_HISTORY=3600
QUEUE_AUTOSCALE_DECISION_HISTORY_MAX=10000

# Telemetry (no-op without cboxdk/laravel-telemetry)
QUEUE_AUTOSCALE_TELEMETRY_ENABLED=true
QUEUE_AUTOSCALE_TELEMETRY_CACHE_TTL=10

# Metrics package
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
```

There is no `QUEUE_AUTOSCALE_EVALUATION_INTERVAL`, `QUEUE_AUTOSCALE_MIN_WORKERS`, `QUEUE_AUTOSCALE_MAX_WORKERS`, `QUEUE_AUTOSCALE_COOLDOWN` or `QUEUE_AUTOSCALE_MAX_PICKUP_TIME`. Worker bounds and SLA targets live in profile classes or per-queue override arrays; the interval is a CLI flag.

## Next Steps

1. Follow the [Quick Start](../quickstart.md) guide for your first autoscaled queue
2. Learn [How It Works](how-it-works.md) to understand the scaling algorithm
3. Explore [Configuration](configuration.md) for the full key reference
4. Set up [Monitoring](monitoring.md) to track autoscaler behaviour

## Additional Resources

- [Metrics Package Documentation](https://github.com/cboxdk/laravel-queue-metrics)
- [Deployment guides](../deployment/_index.md) — self-hosted VPS, Forge, Ploi, Docker
- [Troubleshooting](troubleshooting.md)
