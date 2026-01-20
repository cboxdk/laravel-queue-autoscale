# Laravel Queue Autoscale - Examples

This directory contains practical examples for extending Laravel Queue Autoscale with custom strategies and policies.

## Directory Structure

```
examples/
├── Strategies/           # Custom scaling strategy implementations
│   ├── TimeBasedStrategy.php
│   └── CostOptimizedStrategy.php
├── Policies/             # Custom scaling policy implementations
│   ├── SlackNotificationPolicy.php
│   └── MetricsLoggingPolicy.php
├── config-examples.php   # Advanced configuration examples
└── README.md            # This file
```

## Using These Examples

### 1. Copy to Your Application

```bash
# Create directory structure
mkdir -p app/QueueAutoscale/{Strategies,Policies}

# Copy strategy examples
cp vendor/cboxdk/laravel-queue-autoscale/examples/Strategies/*.php app/QueueAutoscale/Strategies/

# Copy policy examples
cp vendor/cboxdk/laravel-queue-autoscale/examples/Policies/*.php app/QueueAutoscale/Policies/
```

### 2. Update Namespaces

Ensure the namespace matches your application structure:

```php
<?php

namespace App\QueueAutoscale\Strategies;  // Your app namespace

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class TimeBasedStrategy implements ScalingStrategyContract
{
    // Implementation...
}
```

### 3. Configure in queue-autoscale.php

```php
<?php

return [
    // Use custom strategy
    'strategy' => \App\QueueAutoscale\Strategies\TimeBasedStrategy::class,

    // Register custom policies
    'policies' => [
        \App\QueueAutoscale\Policies\SlackNotificationPolicy::class,
        \App\QueueAutoscale\Policies\MetricsLoggingPolicy::class,
    ],
];
```

### 4. Ensure Metrics Package is Configured

The autoscaler requires metrics from `laravel-queue-metrics`:

```bash
# Install metrics package (if not already installed)
composer require cboxdk/laravel-queue-metrics

# Publish configuration
php artisan vendor:publish --tag=queue-metrics-config

# Configure storage backend in .env
QUEUE_METRICS_STORAGE=redis  # or 'database'
```

**See:** [Metrics package setup guide](https://github.com/cboxdk/laravel-queue-metrics#installation)

## Available Examples

### Strategies

#### TimeBasedStrategy
Scales workers based on time-of-day patterns. Perfect for applications with predictable daily traffic patterns.

**Use Cases:**
- E-commerce sites with business hour traffic
- B2B platforms with working hours usage
- Applications with scheduled batch processing

**Configuration:**
```php
'strategy' => new \App\QueueAutoscale\Strategies\TimeBasedStrategy([
    '09:00-17:00' => ['multiplier' => 2.0, 'min_workers' => 3],  // Business hours
    '17:00-21:00' => ['multiplier' => 1.5, 'min_workers' => 2],  // Evening
    '21:00-09:00' => ['multiplier' => 0.5, 'min_workers' => 1],  // Night
]),
```

#### CostOptimizedStrategy
Prioritizes cost efficiency by scaling conservatively while maintaining SLA compliance.

**Use Cases:**
- Startups with limited budgets
- Development/staging environments
- Non-critical background processing

**Configuration:**
```php
'strategy' => new \App\QueueAutoscale\Strategies\CostOptimizedStrategy(
    utilizationTarget: 0.85,    // Target 85% worker utilization
    scaleUpThreshold: 0.90,     // Scale up at 90%
    scaleDownThreshold: 0.60,   // Scale down at 60%
),
```

### Policies

#### SlackNotificationPolicy
Sends Slack notifications when significant scaling events occur.

**Setup:**
```bash
# Add to .env
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
```

**Configuration:**
```php
'policies' => [
    new \App\QueueAutoscale\Policies\SlackNotificationPolicy(
        webhookUrl: config('services.slack.webhook_url'),
        significantChangeThreshold: 2,  // Notify on changes >= 2 workers
    ),
],
```

#### MetricsLoggingPolicy
Logs detailed metrics for every scaling decision to a dedicated log file.

**Configuration:**
```php
'policies' => [
    new \App\QueueAutoscale\Policies\MetricsLoggingPolicy(
        logChannel: 'autoscale-metrics',
        logToSeparateFile: true,
    ),
],
```

**View Logs:**
```bash
tail -f storage/logs/autoscale-metrics.log
```

## Configuration Examples

See `config-examples.php` for complete configuration patterns:

1. **High-Traffic E-commerce** - Fast response, strict SLAs
2. **Cost-Optimized Startup** - Minimal resources, relaxed SLAs
3. **Enterprise Multi-Tenant SaaS** - Multiple priority tiers
4. **Media Processing Platform** - Resource-intensive jobs
5. **Real-Time Analytics** - High throughput, near real-time
6. **Development/Staging** - Minimal resources for testing
7. **Hybrid Strategy** - Time-based strategy switching
8. **Queue Isolation** - Per-team or per-service queues

## Creating Your Own

### Custom Strategy Template

```php
<?php

namespace App\QueueAutoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class MyCustomStrategy implements ScalingStrategyContract
{
    private string $lastReason = 'No calculation performed yet';
    private ?float $lastPrediction = null;

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Your scaling logic here
        $targetWorkers = 0;

        // Set explanation
        $this->lastReason = 'Your reasoning here';

        // Set prediction (optional)
        $this->lastPrediction = 0.0;

        return $targetWorkers;
    }

    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    public function getLastPrediction(): ?float
    {
        return $this->lastPrediction;
    }
}
```

### Custom Policy Template

```php
<?php

namespace App\QueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class MyCustomPolicy implements ScalingPolicy
{
    public function before(ScalingDecision $decision): void
    {
        // Actions before scaling occurs
        // - Validation
        // - Preparation
        // - Logging
    }

    public function after(ScalingDecision $decision): void
    {
        // Actions after scaling completes
        // - Notifications
        // - Metrics
        // - Cleanup
    }
}
```

## Testing Your Extensions

```bash
# Run tests
composer test

# Test your strategy in isolation
php artisan tinker
> $strategy = new \App\QueueAutoscale\Strategies\MyCustomStrategy();
> $config = new \Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration(...);
> $metrics = (object)['processingRate' => 10.0, ...];
> $workers = $strategy->calculateTargetWorkers($metrics, $config);
> dump($workers, $strategy->getLastReason());
```

## Best Practices

### Strategies

1. **Always return non-negative integers**
2. **Set `lastReason` for debugging**
3. **Handle null/missing metrics gracefully**
4. **Consider SLA breach scenarios**
5. **Test with edge cases** (zero rate, high backlog, etc.)

### Policies

1. **Fail silently** - Don't disrupt autoscaling
2. **Be idempotent** - Handle multiple calls safely
3. **Log errors** - Track policy failures
4. **Keep it fast** - Avoid blocking operations
5. **Clean up resources** - Prevent leaks

## Need Help?

- Check the main [README.md](../README.md) for core concepts
- Review [ARCHITECTURE.md](../ARCHITECTURE.md) for algorithm details
- See [TROUBLESHOOTING.md](../TROUBLESHOOTING.md) for common issues
- Open an issue on GitHub for bugs or questions
