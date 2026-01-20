---
title: "Quick Start"
description: "Get your first queue autoscaled in 5 minutes with this step-by-step tutorial"
weight: 3
---

# Quick Start

This guide gets you from zero to a working autoscaled queue in 5 minutes.

## Prerequisites

- Laravel Queue Autoscale [installed](installation.md)
- Metrics package configured
- A Laravel application with queue jobs

## Step 1: Define Your SLA Target

The most important decision: **How quickly should jobs start processing?**

Edit `config/queue-autoscale.php`:

```php
return [
    'enabled' => true,

    'sla_defaults' => [
        // Jobs should start within 30 seconds
        'max_pickup_time_seconds' => 30,

        // Worker limits
        'min_workers' => 1,   // Always keep at least 1
        'max_workers' => 10,  // Never exceed 10

        // Prevent rapid scaling
        'scale_cooldown_seconds' => 60,
    ],
];
```

**Choosing the right SLA:**
- **5-15 seconds**: Real-time/critical operations
- **30-60 seconds**: Standard user-facing features
- **120-300 seconds**: Background processing, reports
- **600+ seconds**: Batch jobs, analytics

## Step 2: Start the Autoscaler

Run the autoscaler daemon:

```bash
php artisan queue:autoscale
```

You'll see output like:

```
[autoscale] Starting autoscaler...
[autoscale] Evaluation interval: 30 seconds
[autoscale] Discovered queues: redis/default
[autoscale] Queue: redis/default - Current: 0 workers, Target: 1 worker
[autoscale] Spawning 1 worker for redis/default
```

## Step 3: Dispatch Some Jobs

Create a test job or use an existing one:

```php
use App\Jobs\ProcessOrder;

// Dispatch jobs to the queue
for ($i = 0; $i < 100; $i++) {
    ProcessOrder::dispatch($order);
}
```

## Step 4: Watch It Scale

The autoscaler will automatically:

1. **Detect new jobs** via metrics package
2. **Calculate required workers** using the hybrid algorithm
3. **Spawn workers** to meet SLA target
4. **Scale down** when traffic decreases

You'll see logs like:

```
[autoscale] Queue: redis/default
  Current: 1 workers
  Target: 5 workers
  Reason: "trend predicts rate increase: 2.00/s → 5.00/s"
  Action: Spawning 4 workers

[autoscale] Queue: redis/default
  Current: 5 workers
  Target: 8 workers
  Reason: "backlog drain to prevent SLA breach (25s / 30s target)"
  Action: Spawning 3 workers

[autoscale] Queue: redis/default
  Current: 8 workers
  Target: 3 workers
  Reason: "workload decreased: 1.00/s processing rate"
  Action: Terminating 5 workers (after cooldown)
```

## Understanding What Happened

### The Algorithm in Action

When you dispatched 100 jobs, the autoscaler:

1. **Little's Law**: Calculated steady-state workers needed
   - Rate: 10 jobs/sec
   - Avg time: 2 seconds/job
   - Workers: 10 × 2 = 20 workers

2. **Trend Prediction**: Detected traffic increase
   - Forecasted rate: 12 jobs/sec
   - Workers: 12 × 2 = 24 workers

3. **Backlog Drain**: Checked SLA risk
   - No breach risk detected
   - Workers: N/A

4. **Decision**: max(20, 24, N/A) = **24 workers**
   - But limited by `max_workers` = 10
   - **Final: 10 workers**

### Why This Matters

**Without autoscaling:**
- You'd manually set `numprocs=10` in supervisor
- Under load: Jobs wait longer (SLA miss)
- Light load: Wasting resources on idle workers

**With autoscaling:**
- Maintains SLA automatically
- Scales up for peak traffic
- Scales down to save resources
- Responds to trends proactively

## Common Scenarios

### Scenario: Gradual Traffic Increase

```
09:00 - Morning traffic starts
├─ Rate: 5 jobs/sec
└─ Workers: 1 → 10 (gradual increase)

09:30 - Peak traffic
├─ Rate: 12 jobs/sec
└─ Workers: 10 (at max_workers limit)

10:00 - Traffic normalizes
├─ Rate: 8 jobs/sec
└─ Workers: 10 → 6 (gradual decrease after cooldown)
```

### Scenario: Sudden Traffic Spike

```
10:00 - Normal traffic
├─ Rate: 10 jobs/sec
├─ Backlog: 0
└─ Workers: 5

10:01 - Marketing campaign launches
├─ Rate: 50 jobs/sec (5x increase!)
├─ Backlog: 200 jobs accumulating
└─ Workers: 5 → 10 (immediate scale-up)

10:02 - SLA at risk detected
├─ Oldest job: 28 seconds (approaching 30s target)
├─ Backlog drain activates
└─ Workers: 10 (already at max, SLA breach prevented)
```

### Scenario: Different Queue Priorities

Configure different SLAs per queue:

```php
'sla_defaults' => [
    'max_pickup_time_seconds' => 60,  // Default: 1 minute
],

'queues' => [
    'critical' => [
        'max_pickup_time_seconds' => 10,  // 10 seconds
        'min_workers' => 5,
        'max_workers' => 50,
    ],
    'emails' => [
        'max_pickup_time_seconds' => 300,  // 5 minutes
        'min_workers' => 0,
        'max_workers' => 5,
    ],
],
```

## Monitoring Your Autoscaler

### View Current Status

```bash
php artisan queue:autoscale:status
```

Output:
```
Queue: redis/default
  Status: Scaling
  Current Workers: 8
  Target Workers: 8
  SLA Target: 30 seconds
  Predicted Pickup: 15 seconds
  Last Scaled: 45 seconds ago
```

### Check Metrics

```bash
# View queue metrics
php artisan queue:metrics:show redis/default

# Watch live metrics
watch -n 1 'php artisan queue:metrics:show redis/default'
```

### Enable Verbose Logging

For debugging, increase log verbosity:

```bash
php artisan queue:autoscale -vvv
```

## Production Deployment

Once you're comfortable with local testing, deploy to production:

### 1. Update Configuration

Set environment-specific values in `.env`:

```env
QUEUE_AUTOSCALE_ENABLED=true
QUEUE_METRICS_STORAGE=redis

# Production SLA targets
QUEUE_AUTOSCALE_MAX_PICKUP_TIME=30
QUEUE_AUTOSCALE_MAX_WORKERS=50
```

### 2. Setup Supervisor

Create `/etc/supervisor/conf.d/queue-autoscale.conf`:

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php /var/www/artisan queue:autoscale
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/autoscale.log
stopwaitsecs=3600
```

### 3. Start Services

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start queue-autoscale:*
```

### 4. Verify Operation

```bash
# Check supervisor status
sudo supervisorctl status queue-autoscale:*

# View logs
tail -f /var/www/storage/logs/autoscale.log

# Check metrics
php artisan queue:autoscale:status
```

## Next Steps

Congratulations! You now have a working autoscaled queue. Here's what to explore next:

### Understand the Algorithm
Learn how scaling decisions are made: [How It Works](basic-usage/how-it-works.md)

### Optimize Configuration
Fine-tune settings for your workload: [Configuration Guide](basic-usage/configuration.md)

### Add Monitoring
Track performance and scaling events: [Monitoring](basic-usage/monitoring.md)

### Custom Logic
Implement custom scaling strategies: [Custom Strategies](advanced-usage/custom-strategies.md)

### Production Hardening
Secure and optimize for production: [Deployment Guide](advanced-usage/deployment.md)

## Troubleshooting

### Workers Not Scaling

**Check metrics collection:**
```bash
php artisan queue:metrics:status
```

**Verify configuration:**
```bash
php artisan queue:autoscale:validate
```

**Review logs:**
```bash
tail -f storage/logs/laravel.log | grep autoscale
```

### SLA Breaches Occurring

If jobs wait longer than your SLA target:

1. **Increase max_workers:**
   ```php
   'max_workers' => 20,  // From 10
   ```

2. **Decrease cooldown:**
   ```php
   'scale_cooldown_seconds' => 30,  // From 60
   ```

3. **Check system resources:**
   ```bash
   # CPU/Memory limits may prevent scaling
   php artisan queue:autoscale:status --verbose
   ```

### Too Many Workers

If the autoscaler spawns too many workers:

1. **Decrease max_workers:**
   ```php
   'max_workers' => 5,  // From 10
   ```

2. **Relax SLA target:**
   ```php
   'max_pickup_time_seconds' => 60,  // From 30
   ```

3. **Increase cooldown:**
   ```php
   'scale_cooldown_seconds' => 120,  // From 60
   ```

## Getting Help

- **Documentation**: See [Troubleshooting Guide](basic-usage/troubleshooting.md)
- **Issues**: [GitHub Issues](https://github.com/cboxdk/laravel-queue-autoscale/issues)
- **Discussions**: [GitHub Discussions](https://github.com/cboxdk/laravel-queue-autoscale/discussions)
