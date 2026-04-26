# Queue Autoscale for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cboxdk/laravel-queue-autoscale.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-queue-autoscale)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-queue-autoscale/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/cboxdk/laravel-queue-autoscale/actions?query=workflow%3Atests+branch%3Amain)
[![GitHub Code Quality Action Status](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-queue-autoscale/code-quality.yml?branch=main&label=code%20quality&style=flat-square)](https://github.com/cboxdk/laravel-queue-autoscale/actions?query=workflow%3Acode-quality+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/cboxdk/laravel-queue-autoscale.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-queue-autoscale)

**Intelligent, predictive autoscaling for Laravel queues with SLA/SLO-based optimization.**

Queue Autoscale for Laravel is a smart queue worker manager that automatically scales your queue workers based on workload, predicted demand, and service level objectives. Unlike traditional reactive solutions, it uses a **hybrid predictive algorithm** combining queueing theory (Little's Law), trend analysis, and backlog-based scaling to maintain your SLA targets.

## Features

- 🎯 **SLA/SLO-Based Scaling** - Define max pickup time instead of arbitrary worker counts
- 📈 **Predictive Algorithm** - Proactive scaling using trend analysis and forecasting
- 🔬 **Queueing Theory Foundation** - Little's Law (L = λW) for steady-state calculations
- ⚡ **SLA Breach Prevention** - Aggressive backlog drain when approaching SLA violations
- 🖥️ **Resource-Aware** - Respects CPU and memory limits from system metrics
- 🔄 **Metrics-Driven** - Uses [`laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics) for queue discovery and all metrics
- 🌐 **Cluster-Aware** - Multiple autoscale managers auto-join via Redis, elect a leader, and distribute worker targets across hosts
- 🌐 **Cluster-Aware** - Multiple autoscale managers auto-join via Redis, elect a leader, and distribute worker targets across hosts
- 🎛️ **Extensible** - Custom scaling strategies and policies via interfaces
- 📊 **Event Broadcasting** - React to scaling decisions, SLA predictions, worker changes
- 🛡️ **Graceful Shutdown** - SIGTERM → SIGKILL worker termination
- 🚀 **High Performance** - Drift-corrected evaluation loop and efficient process management
- 🔒 **Secure by Design** - Safe process spawning and resource isolation
- 💎 **DX First** - Clean API following Spatie package conventions

## Requirements

- PHP 8.3+
- Laravel 11.0+
- [`cboxdk/laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics) ^1.0.0
- [`cboxdk/system-metrics`](https://github.com/cboxdk/system-metrics) ^1.2

## Installation

Install via Composer:

```bash
composer require cboxdk/laravel-queue-autoscale
```

Run the interactive installer to publish config, choose the right topology, and generate the matching `.env` values:

```bash
php artisan queue:autoscale:install
```

It guides you through three safe presets:
- single host, low traffic, no Redis infrastructure
- single host with Redis-backed metrics and predictive signals
- multi-host cluster with Redis coordination

If you prefer the manual path, you can still publish the config files yourself:

Publish the configuration file:

```bash
php artisan vendor:publish --tag=queue-autoscale-config
```

### Setup Metrics Package

The autoscaler requires [`laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics) for queue discovery and metrics collection:

```bash
# Install metrics package (if not auto-installed via dependency)
composer require cboxdk/laravel-queue-metrics

# Publish metrics configuration
php artisan vendor:publish --tag=queue-metrics-config
```

Configure storage backend in `.env`:

```env
# Option A: Redis (recommended - fast, in-memory)
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default

# Option B: Database (persistent storage)
QUEUE_METRICS_STORAGE=database
```

Queue Autoscale itself can now run in three safe modes:

```env
# Single host without Redis
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto

# Single host with Redis-backed predictive signals
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=redis

# Multi-host cluster
QUEUE_AUTOSCALE_CLUSTER_ENABLED=true
```

`auto` keeps single-host mode Redis-free and switches to Redis-backed coordination automatically in cluster mode.

The installer can also write the recommended values straight into `.env` for you.

If using database storage, publish and run migrations:

```bash
php artisan vendor:publish --tag=laravel-queue-metrics-migrations
php artisan migrate
```

**📚 See [Metrics Package Documentation](https://github.com/cboxdk/laravel-queue-metrics) for advanced configuration.**

## Quick Start

### 1. Configure SLA Targets (Optional)

**Zero Config:** By default, the package uses the "Balanced" profile (30s SLA). You can skip configuration if this suits you.

To customize, edit `config/queue-autoscale.php`:

```php
return [
    'enabled' => true,
    
    // Choose a preset profile: 'balanced', 'critical', 'high_volume', 'bursty', 'background'
    'sla_defaults' => \Cbox\LaravelQueueAutoscale\Configuration\ProfilePresets::balanced(),

    // ... or customize manually
    'queues' => [
        'critical' => [
            'max_pickup_time_seconds' => 5,   // Strict SLA
            'max_workers' => 20,
        ],
    ],
];
```

### 2. Run the Autoscaler

```bash
php artisan queue:autoscale

# Inspect cluster leader, hosts, capacity, and workload targets
php artisan queue:autoscale:cluster

# Inspect cluster leader, hosts, capacity, and workload targets
php artisan queue:autoscale:cluster

# Replace the existing local manager on this host/app
php artisan queue:autoscale --replace
```

The autoscaler will:
- Receive all queues and metrics from [`laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics)
- Apply scaling algorithms to meet SLA targets
- Scale workers up/down based on calculations
- Respect CPU/memory limits from [`system-metrics`](https://github.com/cboxdk/system-metrics)
- Log all scaling decisions

### 3. Monitor with Events

```php
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Events\SlaBreachPredicted;

Event::listen(WorkersScaled::class, function (WorkersScaled $event) {
    Log::info("Scaled {$event->queue}: {$event->from} → {$event->to} workers");
    Log::info("Reason: {$event->reason}");
});

Event::listen(SlaBreachPredicted::class, function (SlaBreachPredicted $event) {
    // Alert when SLA breach is predicted
    Notification::route('slack', env('SLACK_ALERT_WEBHOOK'))
        ->notify(new SlaBreachAlert($event->decision));
});
```

## How It Works

### Hybrid Predictive Algorithm

The autoscaler uses three complementary approaches and takes the **maximum** (most conservative):

#### 1. **Rate-Based (Little's Law)**
```
Workers = Arrival Rate × Avg Processing Time
```
Calculates steady-state workers needed for current load.

#### 2. **Trend-Based (Predictive)**
```
Workers = Predicted Rate × Avg Processing Time
```
Uses trend analysis to predict future arrival rates and scale proactively.

#### 3. **Backlog-Based (SLA Protection)**
```
Workers = Backlog / (Time Until SLA Breach / Avg Job Time)
```
Aggressively scales when approaching SLA violations.

### Resource Constraints

All calculations are bounded by:
- **System capacity** - CPU/memory limits from `system-metrics`
- **Config bounds** - min/max workers from configuration
- **Cooldown periods** - Prevents scaling thrash

See [Architecture Documentation](docs/algorithms/architecture.md) for detailed algorithm explanation.

## Configuration Reference

### SLA Defaults

```php
'sla_defaults' => [
    // Maximum time a job should wait before being picked up (seconds)
    'max_pickup_time_seconds' => 30,

    // Minimum workers to maintain (even if queue is empty)
    'min_workers' => 1,

    // Maximum workers allowed for this queue
    'max_workers' => 10,

    // Cooldown period between scaling operations (seconds)
    'scale_cooldown_seconds' => 60,
],
```

### Per-Queue Overrides

```php
'queues' => [
    'queue-name' => [
        'max_pickup_time_seconds' => 60,
        'min_workers' => 2,
        'max_workers' => 20,
        'scale_cooldown_seconds' => 30,
    ],
],
```

### Prediction Settings

```php
'prediction' => [
    // How far ahead to forecast (seconds)
    'forecast_horizon_seconds' => 60,

    // When to trigger backlog drain (0-1, e.g., 0.8 = 80% of SLA time)
    'breach_threshold' => 0.8,
],
```

### Resource Limits

```php
'resource_limits' => [
    // Maximum CPU usage percentage
    'max_cpu_percent' => 90,

    // Number of CPU cores to reserve
    'reserve_cpu_cores' => 0.5,

    // Maximum memory usage percentage
    'max_memory_percent' => 85,

    // Estimated memory per worker (MB)
    'worker_memory_mb_estimate' => 128,
],
```

### Worker Settings

```php
'worker' => [
    // Worker process arguments
    'tries' => 3,
    'timeout_seconds' => 3600,
    'sleep_seconds' => 3,

    // Graceful shutdown timeout before SIGKILL
    'shutdown_timeout_seconds' => 30,
],
```

### Strategy Configuration

```php
'strategy' => [
    // Scaling strategy class (must implement ScalingStrategyContract)
    'class' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class,
],
```

## Custom Scaling Strategies

Implement your own scaling logic:

```php
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

class CustomStrategy implements ScalingStrategyContract
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Your custom logic here
        return (int) ceil($metrics->processingRate * 2);
    }

    public function getLastReason(): string
    {
        return 'Custom strategy: doubled the processing rate';
    }

    public function getLastPrediction(): ?float
    {
        return null; // Optional: estimated pickup time
    }
}
```

Register in config:

```php
'strategy' => [
    'class' => \App\Scaling\CustomStrategy::class,
],
```

## Scaling Policies

Add before/after hooks to scaling operations:

```php
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class NotifySlackPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): void
    {
        if ($decision->shouldScaleUp()) {
            Slack::notify("About to scale up {$decision->queue}");
        }
    }

    public function afterScaling(ScalingDecision $decision, bool $success): void
    {
        if (!$success) {
            Slack::notify("Failed to scale {$decision->queue}");
        }
    }
}
```

Register in config:

```php
'policies' => [
    \App\Policies\NotifySlackPolicy::class,
],
```

## Events

Subscribe to scaling events:

### ScalingDecisionMade

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

Event::listen(ScalingDecisionMade::class, function (ScalingDecisionMade $event) {
    $decision = $event->decision;

    Log::info('Scaling decision', [
        'queue' => $decision->queue,
        'current' => $decision->currentWorkers,
        'target' => $decision->targetWorkers,
        'reason' => $decision->reason,
    ]);
});
```

### WorkersScaled

```php
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

Event::listen(WorkersScaled::class, function (WorkersScaled $event) {
    Metrics::gauge('queue.workers', $event->to, [
        'queue' => $event->queue,
        'action' => $event->action, // 'scaled_up' or 'scaled_down'
    ]);
});
```

### SlaBreachPredicted

```php
use Cbox\LaravelQueueAutoscale\Events\SlaBreachPredicted;

Event::listen(SlaBreachPredicted::class, function (SlaBreachPredicted $event) {
    $decision = $event->decision;

    // Alert when pickup time is predicted to exceed SLA
    if ($decision->isSlaBreachRisk()) {
        PagerDuty::alert("SLA breach predicted for {$decision->queue}");
    }
});
```

## Advanced Usage

### Running as Daemon

Use Supervisor to keep the autoscaler running:

```ini
[program:queue-autoscale]
command=php /path/to/artisan queue:autoscale
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/path/to/logs/autoscale.log
```

### Custom Evaluation Interval

```bash
php artisan queue:autoscale --interval=10
```

Default is 5 seconds between evaluations.

### Debugging

Enable detailed logging:

```php
'log_channel' => 'stack', // Use your preferred channel
```

View scaling decisions:

```bash
tail -f storage/logs/laravel.log | grep autoscale
```

## Metrics Integration

**This package does NOT discover queues or collect metrics itself.** All queue discovery and metrics collection is delegated to [`laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics):

```php
use Cbox\LaravelQueueMetrics\QueueMetrics;

// The ONLY source of queue data for autoscaling
$allQueues = QueueMetrics::getAllQueuesWithMetrics();

foreach ($allQueues as $queue) {
    echo "Queue: {$queue->connection}/{$queue->queue}\n";
    echo "Processing Rate: {$queue->processingRate} jobs/sec\n";
    echo "Backlog: {$queue->depth->pending} jobs\n";
    echo "Oldest Job: {$queue->depth->oldestJobAgeSeconds}s\n";
    echo "Trend: {$queue->trend->direction}\n";
}
```

**Package Responsibilities:**

### [laravel-queue-metrics](https://github.com/cboxdk/laravel-queue-metrics) (dependency)
- ✅ Scans all configured queue connections
- ✅ Discovers active queues
- ✅ Collects queue depth and age metrics
- ✅ Calculates processing rates
- ✅ Analyzes trends and forecasts

### laravel-queue-autoscale (this package)
- ✅ Applies scaling algorithms (Little's Law, Trend Prediction, Backlog Drain)
- ✅ Makes SLA-based scaling decisions
- ✅ Manages worker pool lifecycle (spawn/terminate)
- ✅ Enforces resource constraints (CPU/memory limits)
- ✅ Executes scaling policies and broadcasts events

## Comparison with Horizon

| Feature | Laravel Horizon | Queue Autoscale |
|---------|----------------|-----------------|
| **Scaling Logic** | Manual supervisor config | Automatic predictive |
| **Optimization Goal** | Worker count targets | SLA/SLO targets |
| **Algorithm** | Static configuration | Hybrid (Little's Law + Trend + Backlog) |
| **Resource Awareness** | No | Yes (CPU/memory limits) |
| **Queue Discovery** | Manual queue config | Via metrics package |
| **Prediction** | Reactive only | Proactive trend-based |
| **SLA Protection** | No | Yes (breach prevention) |
| **Extensibility** | Limited | Full (strategies, policies) |

## Testing

Run the test suite:

```bash
composer test
```

Run with coverage:

```bash
composer test:coverage
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

Please see [Contributing Guide](docs/advanced-usage/contributing.md) for details.

## Security

If you discover any security related issues, please email security@cbox.dk instead of using the issue tracker.

## Credits

- [Sylvester Damgaard](https://github.com/sylvesterdamgaard)
- [All Contributors](../../contributors)

## Resources

### Documentation

- **[Architecture](docs/algorithms/architecture.md)** - Deep dive into the hybrid predictive algorithm, queueing theory, and system design
- **[Troubleshooting](docs/basic-usage/troubleshooting.md)** - Common issues, debugging tips, and solutions
- **[examples/README.md](examples/README.md)** - Practical examples and templates for custom strategies and policies

### Examples

- **Custom Strategies**
  - [TimeBasedStrategy](examples/Strategies/TimeBasedStrategy.php) - Scale workers based on time-of-day patterns
  - [CostOptimizedStrategy](examples/Strategies/CostOptimizedStrategy.php) - Prioritize cost efficiency with conservative scaling

- **Custom Policies**
  - [SlackNotificationPolicy](examples/Policies/SlackNotificationPolicy.php) - Send Slack alerts on scaling events
  - [MetricsLoggingPolicy](examples/Policies/MetricsLoggingPolicy.php) - Log detailed metrics to dedicated file

- **Configuration Patterns**
  - [config-examples.php](examples/config-examples.php) - 8 real-world configuration examples for different use cases

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
