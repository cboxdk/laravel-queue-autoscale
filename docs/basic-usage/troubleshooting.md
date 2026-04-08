---
title: "Troubleshooting"
description: "Queue Autoscale for Laravel troubleshooting — common issues and solutions for deployment and operation"
weight: 13
---

# Troubleshooting

Common issues and solutions when using Queue Autoscale for Laravel.

## Installation Issues

### Package Not Found

**Problem**: `composer require cboxdk/laravel-queue-autoscale` fails with "Package not found"

**Solution**:
```bash
# Ensure you're using the correct package name
composer require cboxdk/laravel-queue-autoscale

# If developing locally, add to composer.json:
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-queue-autoscale"
        }
    ]
}
```

### Dependency Conflicts

**Problem**: Composer can't resolve dependencies with `laravel-queue-metrics` or `system-metrics`

**Solution**:
```bash
# Update to latest versions
composer update cboxdk/laravel-queue-metrics cboxdk/system-metrics

# Check minimum versions
composer show cboxdk/laravel-queue-metrics  # Should be ^0.0.1
composer show cboxdk/system-metrics          # Should be ^1.2
```

## Configuration Issues

### Config File Not Publishing

**Problem**: `php artisan vendor:publish --tag=queue-autoscale-config` does nothing

**Solution**:
```bash
# Clear config cache first
php artisan config:clear

# Publish with provider flag
php artisan vendor:publish --provider="Cbox\LaravelQueueAutoscale\LaravelQueueAutoscaleServiceProvider"

# Manual alternative: copy file
cp vendor/cboxdk/laravel-queue-autoscale/config/queue-autoscale.php config/
```

### Strategy Class Not Found

**Problem**: "Class 'App\CustomStrategy' not found"

**Solution**:
```php
// In config/queue-autoscale.php, use full namespace
'strategy' => \App\QueueAutoscale\Strategies\CustomStrategy::class,

// Ensure class implements ScalingStrategyContract
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class CustomStrategy implements ScalingStrategyContract
{
    // Implementation...
}

// Run composer autoload
composer dump-autoload
```

## Runtime Issues

### No Workers Being Spawned

**Problem**: Autoscaler runs but doesn't spawn any workers

**Checklist**:
1. Check if queues are being discovered:
```php
// Add debug logging in AutoscaleManager
Log::info('Discovered queues', ['queues' => $queues]);
```

2. Verify queue has pending jobs:
```bash
php artisan queue:list
```

3. Check SLA configuration isn't too lenient:
```php
// In config/queue-autoscale.php
'sla_defaults' => [
    'max_pickup_time_seconds' => 30, // Try lower value
    'min_workers' => 1,               // Ensure at least 1
],
```

4. Check system capacity constraints:
```bash
# Test system metrics directly
php artisan tinker
> app(\Cbox\SystemMetrics\SystemMetrics::class)->limits()
```

### Workers Scaling Too Aggressively

**Problem**: Too many workers being spawned, overwhelming system

**Solution**:
```php
// In config/queue-autoscale.php
'sla_defaults' => [
    'max_workers' => 5, // Lower maximum
    'scale_cooldown_seconds' => 120, // Longer cooldown
],

// Or use cost-optimized strategy
'strategy' => \App\QueueAutoscale\Strategies\CostOptimizedStrategy::class,
```

### Workers Not Scaling Down

**Problem**: Workers remain active even when queue is empty

**Explanation**: This is **expected behavior**. Workers respect `min_workers` configuration.

**Solution**:
```php
// In config/queue-autoscale.php
'sla_defaults' => [
    'min_workers' => 0, // Allow scaling to zero
],

// Note: min_workers >= 1 ensures queue is always monitored
// Set to 0 only if acceptable to have delays on first jobs
```

### Workers Terminating Unexpectedly

**Problem**: Workers shut down before finishing jobs

**Checklist**:
1. Check graceful shutdown timeout:
```php
// Workers get 10 seconds for graceful shutdown
// If jobs take longer, increase in WorkerTerminator
private int $gracefulTimeoutSeconds = 30; // Increase this
```

2. Verify SIGTERM handling in jobs:
```php
// In your job classes
public function handle()
{
    pcntl_signal(SIGTERM, function() {
        $this->shouldStop = true;
    });

    while (!$this->shouldStop) {
        // Work...
    }
}
```

3. Check system resources aren't exhausted:
```bash
# Monitor during autoscaling
top -p $(pgrep -d',' php)
```

## Performance Issues

### High CPU Usage

**Problem**: Autoscaler consuming excessive CPU

**Solution**:
```php
// In config/queue-autoscale.php
'evaluation_interval_seconds' => 10, // Increase from 5

// Reduce metrics polling frequency
'metrics' => [
    'cache_ttl_seconds' => 30, // Cache metrics longer
],
```

### Memory Leaks

**Problem**: Memory usage grows over time

**Solution**:
```php
// Restart autoscaler periodically via Supervisor
[program:autoscale]
autorestart=true
stopwaitsecs=30
stopasgroup=true
killasgroup=true

// Add to your supervisor config
[program:autoscale]
command=php artisan queue:autoscale
process_name=%(program_name)s
autorestart=true
stopwaitsecs=30
# Restart after 1 hour to prevent memory leaks
startsecs=3600
```

### Scaling Decisions Taking Too Long

**Problem**: Evaluation cycle exceeds interval time

**Solution**:
```bash
# Check what's slow
php artisan queue:autoscale --verbose

# Profile the strategy
'strategy' => new class implements ScalingStrategyContract {
    public function calculateTargetWorkers($metrics, $config): int {
        $start = microtime(true);
        // Your logic...
        Log::info('Strategy execution time', [
            'duration_ms' => (microtime(true) - $start) * 1000
        ]);
    }
}
```

## Integration Issues

### Laravel Horizon Conflicts

**Problem**: Both Horizon and Autoscale managing same queues

**Solution**:
```php
// Option 1: Use Autoscale for specific queues only
// In config/queue-autoscale.php
'queue_whitelist' => ['emails', 'notifications'],

// Option 2: Disable Horizon autoscaling
// In config/horizon.php
'auto_scaling' => [
    'enabled' => false,
],
```

### Supervisor Not Starting Autoscaler

**Problem**: `supervisorctl start autoscale` fails

**Checklist**:
1. Check supervisor config syntax:
```bash
supervisorctl reread
supervisorctl update
```

2. Verify PHP path:
```ini
[program:autoscale]
command=/usr/bin/php artisan queue:autoscale  ; Use absolute path
```

3. Check permissions:
```bash
# Supervisor runs as www-data, ensure it can execute artisan
sudo chown -R www-data:www-data /path/to/app
```

4. View logs:
```bash
tail -f /var/log/supervisor/autoscale-stderr.log
tail -f /var/log/supervisor/autoscale-stdout.log
```

## Debugging Tips

### Enable Verbose Logging

```php
// In config/queue-autoscale.php
'logging' => [
    'level' => 'debug',
    'channel' => 'stack',
],

// Or set via environment
LOG_LEVEL=debug
```

### Monitor Scaling Decisions

```bash
# Watch scaling events in real-time
tail -f storage/logs/laravel.log | grep autoscale

# Filter for specific queue
tail -f storage/logs/laravel.log | grep "autoscale.*default"
```

### Subscribe to Events

```php
// In EventServiceProvider.php
use Cbox\LaravelQueueAutoscale\Events\{
    ScalingDecisionMade,
    WorkersScaled,
    SlaBreachPredicted
};

protected $listen = [
    ScalingDecisionMade::class => [
        LogScalingDecision::class,
    ],
    WorkersScaled::class => [
        NotifyScalingChange::class,
    ],
    SlaBreachPredicted::class => [
        AlertSlaBreach::class,
    ],
];
```

### Test Strategy Independently

```php
// In tinker
$strategy = app(\Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract::class);
$config = new \Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration(
    connection: 'redis',
    queue: 'default',
    maxPickupTimeSeconds: 30,
    minWorkers: 1,
    maxWorkers: 10,
    scaleCooldownSeconds: 60,
);

$metrics = (object)[
    'processingRate' => 10.0,
    'activeWorkerCount' => 20,
    'depth' => (object)[
        'pending' => 100,
        'oldestJobAgeSeconds' => 25,
    ],
    'trend' => (object)['direction' => 'stable'],
];

$workers = $strategy->calculateTargetWorkers($metrics, $config);
dump($workers, $strategy->getLastReason());
```

### Check Worker Processes

```bash
# List all queue workers
ps aux | grep "queue:work"

# Count workers per queue
ps aux | grep "queue:work" | grep -c "default"

# Check worker memory usage
ps aux | grep "queue:work" | awk '{print $6, $11}'
```

## Common Mistakes

### ❌ Wrong: Implementing ScalingPolicy without both methods
```php
class MyPolicy implements ScalingPolicy {
    public function before(ScalingDecision $decision): void {
        // Implementation
    }
    // Missing after() method!
}
```

### ✅ Right: Implement both methods
```php
class MyPolicy implements ScalingPolicy {
    public function before(ScalingDecision $decision): void {
        // Before logic
    }

    public function after(ScalingDecision $decision): void {
        // After logic
    }
}
```

### ❌ Wrong: Not returning required methods in strategy
```php
class MyStrategy implements ScalingStrategyContract {
    public function calculateTargetWorkers($metrics, $config): int {
        return 5;
    }
    // Missing getLastReason() and getLastPrediction()!
}
```

### ✅ Right: Implement all interface methods
```php
class MyStrategy implements ScalingStrategyContract {
    private string $lastReason = '';
    private ?float $lastPrediction = null;

    public function calculateTargetWorkers($metrics, $config): int {
        $this->lastReason = 'My reasoning';
        $this->lastPrediction = 10.5;
        return 5;
    }

    public function getLastReason(): string {
        return $this->lastReason;
    }

    public function getLastPrediction(): ?float {
        return $this->lastPrediction;
    }
}
```

## Getting Help

If you're still experiencing issues:

1. **Check logs**: `storage/logs/laravel.log`
2. **Enable debug mode**: Set `LOG_LEVEL=debug` in `.env`
3. **Check metrics source**: Verify `laravel-queue-metrics` is collecting data
4. **Test system metrics**: Run `SystemMetrics::limits()` to check resource detection
5. **Simplify configuration**: Start with defaults, add customizations incrementally
6. **Review events**: Subscribe to autoscale events for detailed insight

**Still stuck?** Open an issue on GitHub with:
- Laravel version
- PHP version
- Configuration file (`config/queue-autoscale.php`)
- Relevant log excerpts
- Steps to reproduce
