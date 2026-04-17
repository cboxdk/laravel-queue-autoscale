---
title: "Deployment"
description: "Complete guide for deploying Queue Autoscale for Laravel to production environments"
weight: 32
---

# Deployment

> **Looking for platform-specific steps?** Start at [Deployment → Platforms](../deployment/_index.md). You'll find short, concrete guides for self-hosted VPS, Laravel Forge, Ploi, and Docker.

This page is the **general production reference** — prerequisites, installation, and deeper operational topics that apply across all platforms.

Complete guide for deploying Queue Autoscale for Laravel to production.

## Prerequisites

Before deploying, ensure you have:

- ✅ Laravel 11.0+ application
- ✅ PHP 8.2+ runtime
- ✅ Queue backend configured (Redis, Database, SQS, etc.)
- ✅ Process manager (Supervisor recommended)
- ✅ `laravel-queue-metrics` package installed
- ✅ `system-metrics` package installed

## Installation Steps

### 1. Install Package

```bash
composer require cboxdk/laravel-queue-autoscale
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=queue-autoscale-config
```

This creates `config/queue-autoscale.php`.

### 3. Install & Configure Metrics Package

The autoscaler **requires** `laravel-queue-metrics` for queue discovery and metrics collection. Without proper metrics configuration, the autoscaler cannot function.

#### Install Package

```bash
composer require cboxdk/laravel-queue-metrics
```

> **Note:** This package may auto-install as a dependency. Verify with `composer show cboxdk/laravel-queue-metrics`.

#### Publish Configuration

```bash
php artisan vendor:publish --tag=queue-metrics-config
```

This creates `config/queue-metrics.php`.

#### Configure Storage Backend

Choose a storage driver for metrics data:

**Option A: Redis (Recommended for Production)**

Redis provides fast, in-memory metrics storage with automatic TTL cleanup:

```env
# .env
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default  # Must match config/database.php redis connection
```

Ensure Redis is configured in `config/database.php`:

```php
'redis' => [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
    ],
],
```

**Option B: Database (For Persistence)**

Database storage persists metrics across restarts:

```env
# .env
QUEUE_METRICS_STORAGE=database
```

Publish and run migrations:

```bash
php artisan vendor:publish --tag=laravel-queue-metrics-migrations
php artisan migrate
```

**Storage Comparison:**

| Feature | Redis | Database |
|---------|-------|----------|
| Performance | ~1-2ms overhead per job | ~5-15ms overhead per job |
| Persistence | In-memory (lost on restart) | Persistent across restarts |
| TTL Cleanup | Automatic | Manual/scheduled cleanup |
| Historical Data | Limited (TTL-based) | Full retention |
| Best For | Production autoscaling | Historical analysis & debugging |

#### Verify Installation

Test that metrics collection works:

```bash
php artisan tinker
```

```php
> \Cbox\LaravelQueueMetrics\Facades\QueueMetrics::getSystemOverview();
# Should return object with queue metrics data
```

**📚 Resources:**
- [Metrics Package Documentation](https://github.com/cboxdk/laravel-queue-metrics)
- [Packagist: laravel-queue-metrics](https://packagist.org/packages/cboxdk/laravel-queue-metrics)

### 4. Configure SLA Targets

Edit `config/queue-autoscale.php`:

```php
return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    'sla_defaults' => [
        'max_pickup_time_seconds' => 30,  // Jobs picked up within 30s
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 60,
    ],

    // Override for specific queues
    'queue_overrides' => [
        'critical' => [
            'max_pickup_time_seconds' => 10,
            'max_workers' => 20,
        ],
    ],
];
```

### 5. Test Locally

```bash
# Run autoscaler in foreground
php artisan queue:autoscale

# In another terminal, dispatch test jobs
php artisan tinker
> dispatch(new \App\Jobs\TestJob);
```

Watch the autoscaler logs to verify it scales workers.

## Production Deployment

### Option 1: Supervisor (Recommended)

#### Install Supervisor

```bash
# Ubuntu/Debian
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install supervisor

# macOS
brew install supervisor
```

#### Create Supervisor Config

Create `/etc/supervisor/conf.d/queue-autoscale.conf`:

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php /path/to/your/app/artisan queue:autoscale
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/autoscale-supervisor.log
stopwaitsecs=30
```

**Important settings:**
- `stopasgroup=true` - Stops all spawned workers when autoscaler stops
- `killasgroup=true` - Kills all spawned workers if force-stopped
- `stopwaitsecs=30` - Allows 30s for graceful shutdown
- `user=www-data` - Run as web server user (adjust for your system)

#### Start Autoscaler

```bash
# Reload supervisor config
sudo supervisorctl reread
sudo supervisorctl update

# Start autoscaler
sudo supervisorctl start queue-autoscale

# Check status
sudo supervisorctl status queue-autoscale

# View logs
sudo supervisorctl tail -f queue-autoscale
```

### Option 2: Systemd

Create `/etc/systemd/system/queue-autoscale.service`:

```ini
[Unit]
Description=Queue Autoscale for Laravel
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/path/to/your/app
ExecStart=/usr/bin/php /path/to/your/app/artisan queue:autoscale
Restart=always
RestartSec=10
KillMode=mixed
KillSignal=SIGTERM
TimeoutStopSec=30

# Logging
StandardOutput=append:/path/to/your/app/storage/logs/autoscale-systemd.log
StandardError=append:/path/to/your/app/storage/logs/autoscale-systemd-error.log

[Install]
WantedBy=multi-user.target
```

#### Start Service

```bash
# Reload systemd
sudo systemctl daemon-reload

# Enable auto-start on boot
sudo systemctl enable queue-autoscale

# Start service
sudo systemctl start queue-autoscale

# Check status
sudo systemctl status queue-autoscale

# View logs
sudo journalctl -u queue-autoscale -f
```

### Option 3: Docker

#### Dockerfile

Add to your application's Dockerfile:

```dockerfile
FROM php:8.2-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Copy application
COPY . /var/www/html
WORKDIR /var/www/html

# Install composer dependencies
RUN composer install --no-dev --optimize-autoloader

# Supervisor config for autoscaler
COPY docker/supervisor-autoscale.conf /etc/supervisor/conf.d/

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
```

#### docker/supervisor-autoscale.conf

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php artisan queue:autoscale
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

#### docker-compose.yml

```yaml
version: '3.8'

services:
  autoscale:
    build: .
    container_name: queue-autoscale
    environment:
      - QUEUE_AUTOSCALE_ENABLED=true
      - LOG_LEVEL=info
    volumes:
      - ./storage:/var/www/html/storage
    restart: unless-stopped
    networks:
      - app-network

  redis:
    image: redis:7-alpine
    networks:
      - app-network

networks:
  app-network:
    driver: bridge
```

## Environment Configuration

### Required Environment Variables

```env
# Queue Autoscale
QUEUE_AUTOSCALE_ENABLED=true

# Queue Connection (must match your queue config)
QUEUE_CONNECTION=redis

# Redis (if using Redis queue)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=info
```

### Optional Environment Variables

```env
# Override config values
AUTOSCALE_EVALUATION_INTERVAL=5
AUTOSCALE_DEFAULT_SLA=30
AUTOSCALE_MIN_WORKERS=1
AUTOSCALE_MAX_WORKERS=10
```

## Scaling Configuration

### Production Recommendations

#### High-Traffic Application

```php
'sla_defaults' => [
    'max_pickup_time_seconds' => 15,  // Fast pickup
    'min_workers' => 5,                // Always ready
    'max_workers' => 50,               // High capacity
    'scale_cooldown_seconds' => 30,    // Quick response
],

'evaluation_interval_seconds' => 5,  // Frequent checks
```

#### Medium-Traffic Application

```php
'sla_defaults' => [
    'max_pickup_time_seconds' => 30,
    'min_workers' => 2,
    'max_workers' => 20,
    'scale_cooldown_seconds' => 60,
],

'evaluation_interval_seconds' => 5,
```

#### Low-Traffic Application

```php
'sla_defaults' => [
    'max_pickup_time_seconds' => 60,
    'min_workers' => 0,                // Scale to zero
    'max_workers' => 10,
    'scale_cooldown_seconds' => 120,   // Conservative
],

'evaluation_interval_seconds' => 10,
```

## Monitoring

### Log Monitoring

```bash
# Real-time log monitoring
tail -f storage/logs/laravel.log | grep autoscale

# Filter for specific queue
tail -f storage/logs/laravel.log | grep "autoscale.*default"

# Watch scaling events
tail -f storage/logs/laravel.log | grep "WorkersScaled"
```

### Supervisor Monitoring

```bash
# Status check
sudo supervisorctl status queue-autoscale

# Restart if needed
sudo supervisorctl restart queue-autoscale

# View recent logs
sudo supervisorctl tail queue-autoscale

# Follow logs
sudo supervisorctl tail -f queue-autoscale
```

### Health Checks

Create a health check endpoint:

```php
// routes/web.php
Route::get('/health/autoscale', function () {
    $lastDecision = cache()->get('autoscale:last_decision');

    if (!$lastDecision || $lastDecision < now()->subMinutes(5)) {
        return response()->json(['status' => 'unhealthy'], 503);
    }

    return response()->json(['status' => 'healthy']);
});
```

Add to autoscaler:

```php
// In a custom policy
public function after(ScalingDecision $decision): void
{
    cache()->put('autoscale:last_decision', now(), 600);
}
```

## Performance Optimization

### 1. Queue Backend

**Redis** (Recommended for high throughput)
```php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => 'default',
    'retry_after' => 90,
    'block_for' => null,
],
```

**Database** (Good for moderate throughput)
```php
'database' => [
    'driver' => 'database',
    'table' => 'jobs',
    'queue' => 'default',
    'retry_after' => 90,
],
```

### 2. System Resources

Ensure adequate resources:

```bash
# Check CPU cores
nproc

# Check memory
free -h

# Monitor resource usage
htop

# Watch worker processes
watch -n 1 'ps aux | grep "queue:work"'
```

### 3. Worker Configuration

Configure worker limits in queue:work command:

The autoscaler spawns workers with:
```bash
php artisan queue:work {connection} \
    --queue={queue} \
    --tries=3 \
    --timeout=60 \
    --memory=128 \
    --sleep=1
```

Customize in `WorkerSpawner` if needed.

## Troubleshooting Deployment

### Workers Not Starting

**Check Supervisor:**
```bash
sudo supervisorctl status
sudo supervisorctl tail queue-autoscale stderr
```

**Check Permissions:**
```bash
# Ensure storage is writable
chmod -R 775 storage
chown -R www-data:www-data storage

# Check process user can execute artisan
sudo -u www-data php artisan queue:autoscale
```

### Workers Not Stopping

**Check graceful shutdown:**
```bash
# Test manual stop
sudo supervisorctl stop queue-autoscale

# Should terminate all workers within 30s
ps aux | grep "queue:work"
```

**Increase timeout if needed:**
```ini
# In supervisor config
stopwaitsecs=60  # Increase to 60s
```

### Memory Issues

**Limit per-worker memory:**
```bash
# In WorkerSpawner, add memory limit
--memory=128  # 128 MB per worker
```

**Monitor memory usage:**
```bash
watch -n 1 'ps aux | grep "queue:work" | awk "{sum+=\$6} END {print sum/1024\" MB\"}"'
```

### CPU Overload

**Reduce max workers:**
```php
'max_workers' => 10,  // Lower this
```

**Increase evaluation interval:**
```php
'evaluation_interval_seconds' => 10,  // Check less often
```

## Security Considerations

### 1. User Permissions

```bash
# Run as non-root user
user=www-data  # In supervisor config

# Limit file permissions
chmod 644 config/queue-autoscale.php
```

### 2. Resource Limits

```php
// Prevent resource exhaustion
'max_workers' => 50,  // Hard cap
'evaluation_interval_seconds' => 5,  // Not too frequent
```

### 3. Queue Isolation

```php
// Separate critical from non-critical
'queue_overrides' => [
    'critical' => [
        'max_workers' => 20,
    ],
    'low-priority' => [
        'max_workers' => 5,
    ],
],
```

## Backup and Recovery

### Graceful Shutdown

```bash
# Stop accepting new evaluations
sudo supervisorctl stop queue-autoscale

# Wait for current evaluation to complete
sleep 10

# All workers should terminate gracefully
```

### Emergency Stop

```bash
# Force stop (SIGKILL)
sudo supervisorctl stop queue-autoscale
sudo pkill -9 -f "queue:autoscale"
sudo pkill -9 -f "queue:work"
```

### Recovery

```bash
# Clear any stuck jobs (if needed)
php artisan queue:restart

# Start autoscaler
sudo supervisorctl start queue-autoscale

# Verify workers spawn
ps aux | grep "queue:work"
```

## Deployment Checklist

### Pre-Deployment

- [ ] Package installed via Composer
- [ ] Configuration published and customized
- [ ] SLA targets defined for all queues
- [ ] Local testing completed
- [ ] Resource limits configured
- [ ] Logging configured
- [ ] Monitoring alerts configured

### Deployment

- [ ] Supervisor/systemd config deployed
- [ ] Service enabled for auto-start
- [ ] Service started successfully
- [ ] Workers spawning as expected
- [ ] Logs showing normal operation
- [ ] Health check endpoint responding

### Post-Deployment

- [ ] Monitor for 24 hours
- [ ] Check SLA compliance
- [ ] Review scaling decisions
- [ ] Adjust configuration if needed
- [ ] Document any customizations
- [ ] Train team on operation

## Next Steps

- [Monitoring Guide](../basic-usage/monitoring.md) - Set up comprehensive monitoring
- [Performance Tuning](../basic-usage/performance.md) - Optimize for your workload
- [Troubleshooting](../basic-usage/troubleshooting.md) - Common issues and solutions
