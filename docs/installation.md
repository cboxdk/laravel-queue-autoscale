---
title: "Installation"
description: "Step-by-step guide to install and configure Queue Autoscale for Laravel in your application"
weight: 2
---

# Installation

This guide walks you through installing and configuring Queue Autoscale for Laravel in your application.

## Requirements

Before installing, ensure your environment meets these requirements:

- **PHP**: 8.3, 8.4, or 8.5
- **Laravel**: 11.0 or higher
- **Composer**: Latest version recommended

## Step 1: Install Package

Install the package via Composer:

```bash
composer require cboxdk/laravel-queue-autoscale
```

The package will automatically register its service provider using Laravel's auto-discovery.

## Step 2: Publish Configuration

The fastest path is the interactive installer:

```bash
php artisan queue:autoscale:install
```

It will:

- publish `queue-autoscale` and `queue-metrics` config files
- guide you to the right preset for single-host vs cluster mode
- recommend the correct `QUEUE_METRICS_*` and `QUEUE_AUTOSCALE_*` env values
- optionally write those values into `.env`
- publish queue-metrics database migrations when you choose the low-traffic database preset

If you prefer the manual path, publish the configuration file yourself:

```bash
php artisan vendor:publish --tag=queue-autoscale-config
```

This creates `config/queue-autoscale.php` with sensible defaults.

## Step 3: Setup Metrics Package

Queue Autoscale for Laravel requires `cboxdk/laravel-queue-metrics` for queue discovery and metrics collection. This package is automatically installed as a dependency.

## Cluster mode

Cluster mode is optional, but when enabled it requires Redis for manager coordination. No cluster ID or host list is needed in config: managers auto-join the cluster from shared app and queue configuration.

If you run a single autoscale manager, Redis is **not** required for this package. Single-host autoscaling continues to work with non-Redis queue backends such as `database` and `sqs`.

The package now uses safe signal defaults:

- In single-host mode, `QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto` and `QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto` resolve to null/no-op backends, so the manager stays Redis-free.
- In cluster mode, those same `auto` settings resolve to Redis-backed implementations automatically.
- If you want Redis-backed pickup-time and spawn-latency signals on a single host too, set both values explicitly to `redis`.

```php
'cluster' => [
    'enabled' => env('QUEUE_AUTOSCALE_CLUSTER_ENABLED', false),
]
```

Use this when you run multiple `queue:autoscale` processes across replicas or hosts against the same queues.

### Choose your deployment shape

`php artisan queue:autoscale:install` maps directly to these presets and prevents invalid combinations.

#### Option A: Single host, no Redis

Good for low-traffic environments, database-backed metrics, and queues on `database` / `sqs` / similar backends.

```env
QUEUE_METRICS_STORAGE=database
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto
```

#### Option B: Single host, Redis-backed predictive signals

Use this when you want pickup-time percentiles and shared spawn-latency tracking even though you only run one manager.

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=redis
```

#### Option C: Cluster mode

Required when you run multiple `queue:autoscale` managers against the same queues.

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
QUEUE_AUTOSCALE_CLUSTER_ENABLED=true
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto
```

## Cluster mode

Cluster mode is optional, but when enabled it requires Redis for manager coordination. No cluster ID or host list is needed in config: managers auto-join the cluster from shared app and queue configuration.

If you run a single autoscale manager, Redis is **not** required for this package. Single-host autoscaling continues to work with non-Redis queue backends such as `database` and `sqs`.

```php
'cluster' => [
    'enabled' => env('QUEUE_AUTOSCALE_CLUSTER_ENABLED', false),
]
```

Use this when you run multiple `queue:autoscale` processes across replicas or hosts against the same queues.

### Publish Metrics Configuration

```bash
php artisan vendor:publish --tag=queue-metrics-config
```

### Configure Storage Backend

The metrics package needs a storage backend. Choose based on your needs:

#### Option A: Redis (Recommended)

Fast, in-memory storage ideal for production:

```env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
```

Ensure your `config/database.php` has the Redis connection configured.

#### Option B: Database

Persistent storage for historical metrics:

```env
QUEUE_METRICS_STORAGE=database
```

Then publish and run migrations:

```bash
php artisan vendor:publish --tag=laravel-queue-metrics-migrations
php artisan migrate
```

## Step 4: Configure Basic Settings

Edit `config/queue-autoscale.php`. The defaults already work for most apps (`BalancedProfile` as the default with 30s SLA, 1–10 workers). Adjust only when you want different behaviour for specific queues:

```php
<?php

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    // Every queue gets this profile unless overridden below.
    'sla_defaults' => BalancedProfile::class,

    // Per-queue overrides: a profile class OR a partial override array.
    'queues' => [
        'payments' => CriticalProfile::class,          // 10s SLA, 5-50 workers
        'emails'   => ['sla' => ['target_seconds' => 60]],
    ],
];
```

See [Workload Profiles](basic-usage/workload-profiles.md) for the full list of shipped profiles, and [Configuration](basic-usage/configuration.md) for the full nested key reference.

## Step 5: Start the Autoscaler

Run the autoscaler daemon:

```bash
php artisan queue:autoscale
```

The autoscaler will:
1. Discover all queues via the metrics package
2. Evaluate scaling decisions every 30 seconds (configurable)
3. Spawn and terminate workers automatically
4. Log scaling decisions and actions

### Running with Supervisor

For production, use Supervisor to keep the autoscaler running:

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php /path/to/artisan queue:autoscale
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

Start the process:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start queue-autoscale:*
```

## Verification

### See what the autoscaler sees

Inspect the raw queue state and metrics the manager is working with:

```bash
php artisan queue:autoscale:debug --queue=default --connection=redis
```

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

You should see the manager scale up, drain the backlog, and scale back down after `cooldown_seconds` (default 60).

## Troubleshooting

For a symptom-indexed guide (jobs piling up, workers dying, flapping, etc.), see [Troubleshooting](basic-usage/troubleshooting.md).

## Environment Variables

Common environment variables for configuration:

```env
# Enable/disable autoscaling
QUEUE_AUTOSCALE_ENABLED=true

# Metrics package storage
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default

# Default SLA settings
QUEUE_AUTOSCALE_MAX_PICKUP_TIME=30
QUEUE_AUTOSCALE_MIN_WORKERS=1
QUEUE_AUTOSCALE_MAX_WORKERS=10
QUEUE_AUTOSCALE_COOLDOWN=60

# Manager settings
QUEUE_AUTOSCALE_EVALUATION_INTERVAL=30
```

## Next Steps

Now that the package is installed:

1. Follow the [Quick Start](quickstart.md) guide for your first autoscaled queue
2. Learn [How It Works](basic-usage/how-it-works.md) to understand the scaling algorithm
3. Explore [Configuration Options](basic-usage/configuration.md) for advanced settings
4. Set up [Monitoring](basic-usage/monitoring.md) to track autoscaler performance

## Additional Resources

- [Metrics Package Documentation](https://github.com/cboxdk/laravel-queue-metrics)
- [System Metrics Package](https://github.com/cboxdk/system-metrics)
- [Deployment Guide](advanced-usage/deployment.md)
- [Troubleshooting Guide](basic-usage/troubleshooting.md)
