---
title: "Installation"
description: "Step-by-step guide to install and configure Laravel Queue Autoscale in your application"
weight: 2
---

# Installation

This guide walks you through installing and configuring Laravel Queue Autoscale in your Laravel application.

## Requirements

Before installing, ensure your environment meets these requirements:

- **PHP**: 8.3 or 8.4
- **Laravel**: 11.0 or higher
- **Composer**: Latest version recommended

## Step 1: Install Package

Install the package via Composer:

```bash
composer require cboxdk/laravel-queue-autoscale
```

The package will automatically register its service provider using Laravel's auto-discovery.

## Step 2: Publish Configuration

Publish the configuration file to customize settings:

```bash
php artisan vendor:publish --tag=queue-autoscale-config
```

This creates `config/queue-autoscale.php` with sensible defaults.

## Step 3: Setup Metrics Package

Laravel Queue Autoscale requires `cboxdk/laravel-queue-metrics` for queue discovery and metrics collection. This package is automatically installed as a dependency.

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

Edit `config/queue-autoscale.php` to configure your first queue:

```php
<?php

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    'sla_defaults' => [
        'max_pickup_time_seconds' => 30,  // Jobs picked up within 30s
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 60,
    ],

    // Per-queue overrides
    'queues' => [
        // Example: Custom settings for email queue
        'emails' => [
            'max_pickup_time_seconds' => 60,  // Less strict
            'max_workers' => 5,
        ],
    ],
];
```

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

### Check Autoscaler Status

View current scaling status:

```bash
php artisan queue:autoscale:status
```

### Validate Configuration

Verify your configuration is correct:

```bash
php artisan queue:autoscale:validate
```

### Test Dry-Run

Evaluate scaling decisions without actually spawning workers:

```bash
php artisan queue:autoscale:evaluate --dry-run
```

## Troubleshooting

### Autoscaler Not Discovering Queues

**Issue**: No queues are being autoscaled.

**Solution**: Ensure the metrics package is properly configured and collecting metrics:

```bash
# Check metrics collection
php artisan queue:metrics:status

# Verify storage backend is accessible
php artisan tinker
> Cache::store('redis')->get('test'); // For Redis
```

### Workers Not Spawning

**Issue**: Autoscaler runs but doesn't spawn workers.

**Common causes:**
- `enabled` set to `false` in config
- No queues with pending jobs
- Already at `max_workers` limit
- System resource limits reached

**Solution**: Check logs for detailed information:

```bash
tail -f storage/logs/laravel.log
```

### Permission Errors

**Issue**: Workers fail to spawn with permission errors.

**Solution**: Ensure the PHP process has permission to spawn sub-processes:

```bash
# Check current user
whoami

# Verify artisan is executable
ls -la artisan

# Test manual worker spawn
php artisan queue:work redis --queue=default --once
```

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
