---
title: "Configuration"
description: "Complete reference for configuring Laravel Queue Autoscale with SLA targets and worker limits"
weight: 11
---

# Configuration

Complete reference for configuring Laravel Queue Autoscale.

## Table of Contents
- [Prerequisites: Metrics Package Setup](#prerequisites-metrics-package-setup)
- [Basic Configuration](#basic-configuration)
- [Queue Configuration](#queue-configuration)
- [Strategy Configuration](#strategy-configuration)
- [Policy Configuration](#policy-configuration)
- [Manager Configuration](#manager-configuration)
- [Advanced Options](#advanced-options)
- [Environment Variables](#environment-variables)
- [Configuration Patterns](#configuration-patterns)

## Prerequisites: Metrics Package Setup

Laravel Queue Autoscale depends on `laravel-queue-metrics` for all queue discovery and metrics collection. **The autoscaler cannot function without proper metrics configuration.**

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

The package is configured through the published configuration file:

```bash
php artisan vendor:publish --tag="queue-autoscale-config"
```

This creates `config/queue-autoscale.php` with sensible defaults.

### Minimal Configuration

```php
<?php

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    'queues' => [
        [
            'connection' => 'redis',
            'queue' => 'default',
            'max_pickup_time_seconds' => 60,
            'min_workers' => 1,
            'max_workers' => 10,
        ],
    ],
];
```

## Queue Configuration

Each queue requires specific configuration parameters:

### Required Parameters

#### `connection` (string)
The Laravel queue connection name.

```php
'connection' => 'redis',  // Must match config/queue.php connections
```

#### `queue` (string)
The queue name to autoscale.

```php
'queue' => 'default',  // Or 'emails', 'reports', etc.
```

#### `max_pickup_time_seconds` (int)
**SLA Target**: Maximum acceptable time before a job starts processing.

```php
'max_pickup_time_seconds' => 30,  // 30 second SLA
```

This is the most important parameter. The autoscaler will:
- Scale UP if jobs wait longer than this
- Scale DOWN if jobs are processed faster than this
- Maintain worker count to meet this target

#### `min_workers` (int)
Minimum number of workers to maintain.

```php
'min_workers' => 0,  // Can scale to zero for low-priority queues
'min_workers' => 2,  // Always maintain 2 workers for critical queues
```

Setting to 0 allows complete scale-down during idle periods.

#### `max_workers` (int)
Maximum number of workers allowed.

```php
'max_workers' => 20,  // Hard limit on worker count
```

Prevents runaway scaling and resource exhaustion.

### Optional Parameters

#### `scale_cooldown_seconds` (int)
Minimum time between scaling operations.

```php
'scale_cooldown_seconds' => 60,  // Default: 60 seconds
```

Prevents rapid scaling oscillations. During cooldown:
- No scaling operations occur
- System waits for metrics to stabilize

#### `worker_timeout` (int)
Maximum job execution time in seconds.

```php
'worker_timeout' => 300,  // 5 minutes
```

Workers are terminated if jobs exceed this duration.

#### `worker_memory` (int)
Memory limit per worker in MB.

```php
'worker_memory' => 512,  // 512MB per worker
```

#### `worker_sleep` (int)
Seconds to sleep when queue is empty.

```php
'worker_sleep' => 3,  // Default: 3 seconds
```

#### `worker_tries` (int)
Number of times to attempt a job.

```php
'worker_tries' => 3,  // Retry failed jobs up to 3 times
```

#### `worker_backoff` (int)
Seconds to wait before retrying a failed job.

```php
'worker_backoff' => 10,  // Wait 10 seconds between retries
```

### Full Queue Configuration Example

```php
'queues' => [
    [
        // Identity
        'connection' => 'redis',
        'queue' => 'critical',

        // SLA & Capacity
        'max_pickup_time_seconds' => 10,  // Strict 10-second SLA
        'min_workers' => 5,                // Always ready
        'max_workers' => 50,               // High capacity

        // Scaling Behavior
        'scale_cooldown_seconds' => 30,    // Fast reactions

        // Worker Configuration
        'worker_timeout' => 120,
        'worker_memory' => 256,
        'worker_sleep' => 1,
        'worker_tries' => 2,
        'worker_backoff' => 5,
    ],
],
```

## Strategy Configuration

Strategies determine HOW workers are calculated. The package includes a hybrid strategy by default.

### Using Default Strategy

```php
'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridPredictiveStrategy::class,
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
    'class' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridPredictiveStrategy::class,
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

```php
'policies' => [
    \Cbox\LaravelQueueAutoscale\Scaling\Policies\ResourceConstraintPolicy::class,
    \Cbox\LaravelQueueAutoscale\Scaling\Policies\CooldownEnforcementPolicy::class,
],
```

### Adding Custom Policies

```php
'policies' => [
    // Built-in policies
    \Cbox\LaravelQueueAutoscale\Scaling\Policies\ResourceConstraintPolicy::class,
    \Cbox\LaravelQueueAutoscale\Scaling\Policies\CooldownEnforcementPolicy::class,

    // Custom policies
    \App\Autoscale\Policies\SlackNotificationPolicy::class,
    \App\Autoscale\Policies\MetricsLoggingPolicy::class,
    \App\Autoscale\Policies\CostOptimizationPolicy::class,
],
```

### Policy Order

Policies execute in the order defined. Common patterns:

```php
'policies' => [
    // 1. Validate constraints first
    \Cbox\LaravelQueueAutoscale\Scaling\Policies\ResourceConstraintPolicy::class,

    // 2. Check cooldown
    \Cbox\LaravelQueueAutoscale\Scaling\Policies\CooldownEnforcementPolicy::class,

    // 3. Notify stakeholders
    \App\Policies\SlackNotificationPolicy::class,

    // 4. Log metrics last
    \App\Policies\MetricsLoggingPolicy::class,
],
```

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

### Multiple Queues

Configure different settings per queue:

```php
'queues' => [
    // Critical queue - strict SLA, high capacity
    [
        'connection' => 'redis',
        'queue' => 'critical',
        'max_pickup_time_seconds' => 10,
        'min_workers' => 5,
        'max_workers' => 50,
        'scale_cooldown_seconds' => 30,
    ],

    // Default queue - moderate SLA
    [
        'connection' => 'redis',
        'queue' => 'default',
        'max_pickup_time_seconds' => 60,
        'min_workers' => 1,
        'max_workers' => 20,
        'scale_cooldown_seconds' => 60,
    ],

    // Background queue - relaxed SLA, can scale to zero
    [
        'connection' => 'redis',
        'queue' => 'background',
        'max_pickup_time_seconds' => 300,
        'min_workers' => 0,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 120,
    ],
],
```

### Multiple Connections

Support different queue drivers:

```php
'queues' => [
    // Redis queue
    [
        'connection' => 'redis',
        'queue' => 'default',
        'max_pickup_time_seconds' => 60,
        'min_workers' => 1,
        'max_workers' => 10,
    ],

    // Database queue
    [
        'connection' => 'database',
        'queue' => 'emails',
        'max_pickup_time_seconds' => 120,
        'min_workers' => 0,
        'max_workers' => 5,
    ],

    // SQS queue
    [
        'connection' => 'sqs',
        'queue' => 'notifications',
        'max_pickup_time_seconds' => 30,
        'min_workers' => 2,
        'max_workers' => 15,
    ],
],
```

### Resource Limits

Prevent system resource exhaustion:

```php
'resource_limits' => [
    'max_total_workers' => 100,          // Across all queues
    'max_memory_percent' => 80,          // Max system memory usage
    'max_cpu_percent' => 90,             // Max CPU usage
    'reserved_memory_mb' => 1024,        // Reserve for system
],
```

### Health Checks

Configure health check behavior:

```php
'health_checks' => [
    'enabled' => true,
    'interval_seconds' => 60,            // Check worker health every minute
    'max_unresponsive_seconds' => 300,   // Kill workers after 5 minutes
    'restart_dead_workers' => true,      // Auto-restart crashed workers
],
```

## Environment Variables

Override configuration via environment:

```bash
# Enable/disable autoscaling
QUEUE_AUTOSCALE_ENABLED=true

# Manager settings
QUEUE_AUTOSCALE_EVALUATION_INTERVAL=30

# Default queue settings
QUEUE_AUTOSCALE_MAX_PICKUP_TIME=60
QUEUE_AUTOSCALE_MIN_WORKERS=1
QUEUE_AUTOSCALE_MAX_WORKERS=10
QUEUE_AUTOSCALE_COOLDOWN=60

# Resource limits
QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS=100
QUEUE_AUTOSCALE_MAX_MEMORY_PERCENT=80
```

### Queue-Specific Environment Variables

For per-queue configuration:

```bash
# Critical queue
QUEUE_AUTOSCALE_CRITICAL_MAX_PICKUP_TIME=10
QUEUE_AUTOSCALE_CRITICAL_MIN_WORKERS=5
QUEUE_AUTOSCALE_CRITICAL_MAX_WORKERS=50

# Default queue
QUEUE_AUTOSCALE_DEFAULT_MAX_PICKUP_TIME=60
QUEUE_AUTOSCALE_DEFAULT_MIN_WORKERS=1
QUEUE_AUTOSCALE_DEFAULT_MAX_WORKERS=20
```

Reference in config:

```php
'queues' => [
    [
        'connection' => 'redis',
        'queue' => 'critical',
        'max_pickup_time_seconds' => env('QUEUE_AUTOSCALE_CRITICAL_MAX_PICKUP_TIME', 10),
        'min_workers' => env('QUEUE_AUTOSCALE_CRITICAL_MIN_WORKERS', 5),
        'max_workers' => env('QUEUE_AUTOSCALE_CRITICAL_MAX_WORKERS', 50),
    ],
],
```

## Configuration Patterns

### Pattern 1: Conservative Scaling

Slow, steady scaling with high stability:

```php
[
    'max_pickup_time_seconds' => 120,    // Relaxed SLA
    'min_workers' => 2,                  // Always some capacity
    'max_workers' => 10,                 // Moderate ceiling
    'scale_cooldown_seconds' => 120,     // Long cooldown for stability
]
```

**Use when:**
- Traffic is predictable
- Slow ramp-up is acceptable
- Stability > responsiveness

### Pattern 2: Aggressive Scaling

Fast reactions to traffic changes:

```php
[
    'max_pickup_time_seconds' => 15,     // Strict SLA
    'min_workers' => 0,                  // Can scale to zero
    'max_workers' => 50,                 // High ceiling
    'scale_cooldown_seconds' => 30,      // Short cooldown for fast reactions
]
```

**Use when:**
- Traffic is unpredictable/bursty
- Fast response is critical
- Resources are plentiful

### Pattern 3: Cost-Optimized

Minimize worker count while meeting SLA:

```php
[
    'max_pickup_time_seconds' => 180,    // Relaxed SLA
    'min_workers' => 0,                  // Scale to zero when idle
    'max_workers' => 5,                  // Low ceiling
    'scale_cooldown_seconds' => 180,     // Long cooldown prevents churning
]
```

**Use when:**
- Cost is primary concern
- SLA is flexible
- Queue can tolerate delays

### Pattern 4: Business Hours

Different behavior for peak vs off-peak:

```php
'queues' => [
    [
        'connection' => 'redis',
        'queue' => 'default',
        'max_pickup_time_seconds' => now()->isWeekday() && now()->hour >= 9 && now()->hour < 17
            ? 30   // Strict during business hours
            : 300, // Relaxed outside business hours
        'min_workers' => now()->isWeekday() && now()->hour >= 9 && now()->hour < 17
            ? 5    // Higher minimum during business hours
            : 0,   // Can scale to zero outside business hours
        'max_workers' => 20,
    ],
],
```

Or use environment-based configuration:

```bash
# .env.production
QUEUE_AUTOSCALE_BUSINESS_HOURS_MIN_WORKERS=5
QUEUE_AUTOSCALE_AFTER_HOURS_MIN_WORKERS=0
```

### Pattern 5: Multi-Tier Queues

Different queues for different priority levels:

```php
'queues' => [
    // Tier 1: Critical/Real-time
    [
        'queue' => 'critical',
        'max_pickup_time_seconds' => 5,
        'min_workers' => 10,
        'max_workers' => 100,
    ],

    // Tier 2: High Priority
    [
        'queue' => 'high',
        'max_pickup_time_seconds' => 30,
        'min_workers' => 3,
        'max_workers' => 30,
    ],

    // Tier 3: Normal
    [
        'queue' => 'default',
        'max_pickup_time_seconds' => 120,
        'min_workers' => 1,
        'max_workers' => 15,
    ],

    // Tier 4: Background/Batch
    [
        'queue' => 'background',
        'max_pickup_time_seconds' => 600,
        'min_workers' => 0,
        'max_workers' => 5,
    ],
],
```

## Configuration Validation

The package validates configuration on startup:

```php
php artisan queue:autoscale:validate
```

Common validation errors:

### Invalid SLA

```
Error: max_pickup_time_seconds must be > 0
Queue: default
```

Fix: Set a positive value:
```php
'max_pickup_time_seconds' => 60,  // Not 0 or negative
```

### Invalid Worker Limits

```
Error: min_workers (15) exceeds max_workers (10)
Queue: critical
```

Fix: Ensure min ≤ max:
```php
'min_workers' => 5,
'max_workers' => 10,  // Must be >= min_workers
```

### Missing Required Fields

```
Error: Required field 'connection' is missing
Queue: default
```

Fix: Include all required fields:
```php
[
    'connection' => 'redis',     // Required
    'queue' => 'default',        // Required
    'max_pickup_time_seconds' => 60,  // Required
    'min_workers' => 1,          // Required
    'max_workers' => 10,         // Required
]
```

## Configuration Testing

Test your configuration before deploying:

```bash
# Validate configuration
php artisan queue:autoscale:validate

# Dry-run evaluation (doesn't spawn workers)
php artisan queue:autoscale:evaluate --dry-run

# Test specific queue
php artisan queue:autoscale:evaluate --queue=critical --dry-run
```

## See Also

- [How It Works](how-it-works.md) - Understanding the scaling algorithm
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Writing custom scaling strategies
- [Scaling Policies](../advanced-usage/scaling-policies.md) - Implementing scaling policies
- [Deployment](../advanced-usage/deployment.md) - Production deployment guide
- [Monitoring](monitoring.md) - Monitoring and observability
