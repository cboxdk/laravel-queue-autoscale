---
title: "Monitoring"
description: "Complete guide to monitoring Queue Autoscale for Laravel in production with metrics and alerts"
weight: 12
---

# Monitoring

Complete guide to monitoring Queue Autoscale for Laravel in production.

## Table of Contents
- [Overview](#overview)
- [Key Metrics](#key-metrics)
- [Monitoring Strategies](#monitoring-strategies)
- [Integration Examples](#integration-examples)
- [Alerting](#alerting)
- [Dashboards](#dashboards)
- [Troubleshooting](#troubleshooting)

## Overview

Effective monitoring is critical for:
- **Performance**: Ensure autoscaling meets SLA targets
- **Cost**: Track resource usage and optimize spending
- **Reliability**: Detect and respond to failures quickly
- **Optimization**: Identify improvement opportunities

### Monitoring Layers

```
┌─────────────────────────────────────┐
│  Application Layer                  │
│  - Queue depth                      │
│  - Job processing rate              │
│  - SLA compliance                   │
└─────────────────────────────────────┘
            ↓
┌─────────────────────────────────────┐
│  Autoscale Layer                    │
│  - Scaling decisions                │
│  - Worker count                     │
│  - Decision confidence              │
└─────────────────────────────────────┘
            ↓
┌─────────────────────────────────────┐
│  Infrastructure Layer               │
│  - CPU/Memory usage                 │
│  - Process health                   │
│  - System resources                 │
└─────────────────────────────────────┘
```

## Key Metrics

### Application Metrics

#### Queue Depth
Number of pending jobs in the queue.

```php
// Via Laravel
$depth = Queue::size('default');

// Via Autoscale events
Event::listen(ScalingDecisionMade::class, function ($event) {
    $depth = $event->metrics->depth->pending;
});
```

**Monitor for:**
- Sustained high depth → May need higher max_workers
- Rapid growth → Potential traffic spike or processing issues

#### Processing Rate
Jobs processed per second.

```php
$rate = $event->metrics->processingRate;  // jobs/second
```

**Monitor for:**
- Declining rate → Worker performance degradation
- Zero rate with pending jobs → Workers may be stuck

#### Oldest Job Age
How long the oldest job has been waiting.

```php
$age = $event->metrics->depth->oldestJobAgeSeconds;
$sla = $event->config->maxPickupTimeSeconds;

$slaBreachRisk = $age / $sla;  // >0.9 = imminent breach
```

**Monitor for:**
- Age approaching SLA → Scale up needed
- Age always near zero → May be overprovisioned

### Autoscaling Metrics

#### Worker Count
Current number of active workers.

```php
Event::listen(WorkersScaled::class, function ($event) {
    $workers = $event->newCount;
});
```

**Monitor for:**
- Frequently at max_workers → Consider raising limit
- Rapid oscillation → Adjust cooldown or strategy

#### Scaling Frequency
How often scaling decisions change worker count.

```php
// Count decisions per hour
DB::table('scaling_decisions')
    ->where('created_at', '>=', now()->subHour())
    ->where('worker_change', '!=', 0)
    ->count();
```

**Monitor for:**
- High frequency → May indicate oscillation
- Zero changes with varying load → Strategy may be too conservative

#### Decision Confidence
Strategy confidence in scaling decisions.

```php
$confidence = $event->decision->confidence;  // 0.0 - 1.0
```

**Monitor for:**
- Low confidence (<0.7) → May need more historical data
- Always high → Strategy calibration working well

#### Predicted vs Actual Pickup Time
Compare predictions to reality.

```php
$predicted = $event->decision->predictedPickupTime;
// Later, measure actual
$actual = $actualMeasuredTime;
$error = abs($predicted - $actual) / $actual;
```

**Monitor for:**
- High error rate → Strategy may need tuning
- Consistent underestimation → SLA breach risk

### Resource Metrics

#### CPU Usage
Per-worker and system-wide CPU usage.

```php
$cpuPercent = $event->metrics->resources->cpuPercent;
```

**Monitor for:**
- High CPU with low throughput → Inefficient jobs
- CPU at limit → Need to scale

#### Memory Usage
Worker memory consumption.

```php
$memoryPercent = $event->metrics->resources->memoryPercent;
$availableMb = $event->metrics->resources->availableMemoryMb;
```

**Monitor for:**
- Memory leaks → Increasing usage over time
- Out of memory errors → Reduce worker_memory or max_workers

#### Worker Health
Worker process health status.

```php
Event::listen(WorkerHealthCheckFailed::class, function ($event) {
    $pid = $event->worker->pid();
    $reason = $event->reason;
});
```

**Monitor for:**
- Frequent health check failures → Job or infrastructure issues
- Specific worker consistently failing → Process-specific problem

## Monitoring Strategies

### Strategy 1: Event-Based Monitoring

Use Laravel events to push metrics:

```php
<?php

namespace App\Listeners;

use App\Services\MetricsCollector;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class CollectScalingMetrics
{
    public function __construct(
        private readonly MetricsCollector $metrics
    ) {}

    public function handle(ScalingDecisionMade $event): void
    {
        $tags = [
            'queue' => $event->config->queue,
            'connection' => $event->config->connection,
        ];

        // Application metrics
        $this->metrics->gauge('queue.depth', $event->metrics->depth->pending ?? 0, $tags);
        $this->metrics->gauge('queue.oldest_job_age', $event->metrics->depth->oldestJobAgeSeconds ?? 0, $tags);
        $this->metrics->gauge('queue.processing_rate', $event->metrics->processingRate ?? 0, $tags);

        // Autoscaling metrics
        $this->metrics->gauge('autoscale.worker_count', $event->currentWorkers, $tags);
        $this->metrics->gauge('autoscale.target_workers', $event->decision->targetWorkers, $tags);
        $this->metrics->gauge('autoscale.confidence', $event->decision->confidence, $tags);
        $this->metrics->gauge('autoscale.predicted_pickup_time', $event->decision->predictedPickupTime ?? 0, $tags);

        // SLA metrics
        $slaUsage = ($event->metrics->depth->oldestJobAgeSeconds ?? 0) / $event->config->maxPickupTimeSeconds;
        $this->metrics->gauge('autoscale.sla_usage_percent', $slaUsage * 100, $tags);

        // Resource metrics
        $this->metrics->gauge('autoscale.cpu_percent', $event->metrics->resources->cpuPercent ?? 0, $tags);
        $this->metrics->gauge('autoscale.memory_percent', $event->metrics->resources->memoryPercent ?? 0, $tags);
    }
}
```

### Strategy 2: Pull-Based Monitoring

Expose metrics endpoint for Prometheus:

```php
// routes/web.php
Route::get('/metrics', MetricsController::class);
```

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Queue;
use Cbox\LaravelQueueAutoscale\AutoscaleManager;

class MetricsController
{
    public function __invoke(AutoscaleManager $manager)
    {
        $metrics = [];

        foreach (config('queue-autoscale.queues') as $queueConfig) {
            $queue = $queueConfig['queue'];
            $connection = $queueConfig['connection'];

            // Queue metrics
            $metrics[] = "queue_depth{queue=\"{$queue}\"} " . Queue::connection($connection)->size($queue);

            // Worker metrics
            $workers = $manager->getWorkerCount($connection, $queue);
            $metrics[] = "queue_workers{queue=\"{$queue}\"} {$workers}";

            // SLA metrics
            $oldestAge = $this->getOldestJobAge($connection, $queue);
            $slaLimit = $queueConfig['max_pickup_time_seconds'];
            $slaUsage = ($oldestAge / $slaLimit) * 100;

            $metrics[] = "queue_oldest_job_age{queue=\"{$queue}\"} {$oldestAge}";
            $metrics[] = "queue_sla_usage_percent{queue=\"{$queue}\"} {$slaUsage}";
        }

        return response(implode("\n", $metrics))
            ->header('Content-Type', 'text/plain');
    }
}
```

### Strategy 3: Database Logging

Store metrics in database for analysis:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class LogScalingMetrics
{
    public function handle(ScalingDecisionMade $event): void
    {
        DB::table('autoscale_metrics')->insert([
            'queue' => $event->config->queue,
            'connection' => $event->config->connection,
            'timestamp' => now(),

            // Queue state
            'pending_jobs' => $event->metrics->depth->pending ?? null,
            'oldest_job_age' => $event->metrics->depth->oldestJobAgeSeconds ?? null,
            'processing_rate' => $event->metrics->processingRate ?? null,

            // Scaling decision
            'current_workers' => $event->currentWorkers,
            'target_workers' => $event->decision->targetWorkers,
            'worker_change' => $event->decision->targetWorkers - $event->currentWorkers,
            'decision_reason' => $event->decision->reason,
            'decision_confidence' => $event->decision->confidence,
            'predicted_pickup_time' => $event->decision->predictedPickupTime,

            // Trend data
            'trend_direction' => $event->metrics->trend->direction ?? null,
            'trend_forecast' => $event->metrics->trend->forecast ?? null,

            // Resource usage
            'cpu_percent' => $event->metrics->resources->cpuPercent ?? null,
            'memory_percent' => $event->metrics->resources->memoryPercent ?? null,
        ]);
    }
}
```

Query for analysis:

```sql
-- Average worker count per hour
SELECT
    DATE_FORMAT(timestamp, '%Y-%m-%d %H:00') as hour,
    AVG(current_workers) as avg_workers,
    MAX(current_workers) as peak_workers
FROM autoscale_metrics
WHERE queue = 'default'
  AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY hour;

-- SLA compliance rate
SELECT
    queue,
    COUNT(*) as total_evaluations,
    SUM(CASE WHEN oldest_job_age < predicted_pickup_time THEN 1 ELSE 0 END) as within_sla,
    (SUM(CASE WHEN oldest_job_age < predicted_pickup_time THEN 1 ELSE 0 END) / COUNT(*)) * 100 as sla_compliance_percent
FROM autoscale_metrics
WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY queue;
```

## Integration Examples

### Datadog

```php
<?php

namespace App\Services;

use DataDog\DogStatsd;

class DatadogMetricsCollector
{
    private DogStatsd $statsd;

    public function __construct()
    {
        $this->statsd = new DogStatsd([
            'host' => config('services.datadog.host'),
            'port' => config('services.datadog.port'),
        ]);
    }

    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $this->statsd->gauge("laravel.queue.autoscale.{$metric}", $value, $tags);
    }

    public function increment(string $metric, int $value = 1, array $tags = []): void
    {
        $this->statsd->increment("laravel.queue.autoscale.{$metric}", $value, $tags);
    }

    public function histogram(string $metric, float $value, array $tags = []): void
    {
        $this->statsd->histogram("laravel.queue.autoscale.{$metric}", $value, $tags);
    }
}
```

### CloudWatch

```php
<?php

namespace App\Services;

use Aws\CloudWatch\CloudWatchClient;

class CloudWatchMetricsCollector
{
    private CloudWatchClient $client;
    private string $namespace;

    public function __construct()
    {
        $this->client = new CloudWatchClient([
            'region' => config('services.aws.region'),
            'version' => 'latest',
        ]);

        $this->namespace = 'Laravel/QueueAutoscale';
    }

    public function putMetric(string $name, float $value, array $dimensions = []): void
    {
        $this->client->putMetricData([
            'Namespace' => $this->namespace,
            'MetricData' => [
                [
                    'MetricName' => $name,
                    'Value' => $value,
                    'Unit' => 'None',
                    'Timestamp' => time(),
                    'Dimensions' => $this->formatDimensions($dimensions),
                ],
            ],
        ]);
    }

    private function formatDimensions(array $dimensions): array
    {
        return collect($dimensions)->map(function ($value, $key) {
            return ['Name' => $key, 'Value' => $value];
        })->values()->all();
    }
}
```

### Prometheus

```php
<?php

namespace App\Services;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;

class PrometheusMetricsCollector
{
    private CollectorRegistry $registry;

    public function __construct()
    {
        Redis::setDefaultOptions(['host' => config('database.redis.default.host')]);
        $this->registry = new CollectorRegistry(new Redis());
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            'laravel_queue_autoscale',
            $name,
            'Autoscale metric',
            array_keys($labels)
        );

        $gauge->set($value, array_values($labels));
    }

    public function counter(string $name, int $value = 1, array $labels = []): void
    {
        $counter = $this->registry->getOrRegisterCounter(
            'laravel_queue_autoscale',
            $name,
            'Autoscale counter',
            array_keys($labels)
        );

        $counter->incBy($value, array_values($labels));
    }
}
```

## Alerting

### SLA Breach Alert

```php
<?php

namespace App\Listeners;

use App\Services\AlertingService;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class AlertOnSlaRisk
{
    public function __construct(
        private readonly AlertingService $alerting
    ) {}

    public function handle(ScalingDecisionMade $event): void
    {
        $oldestAge = $event->metrics->depth->oldestJobAgeSeconds ?? 0;
        $slaLimit = $event->config->maxPickupTimeSeconds;
        $slaUsage = $oldestAge / $slaLimit;

        if ($slaUsage >= 0.9) {
            $this->alerting->send([
                'severity' => 'critical',
                'title' => "SLA breach imminent: {$event->config->queue}",
                'message' => "Queue {$event->config->queue} is at {$slaUsage}% of SLA limit",
                'details' => [
                    'oldest_job_age' => $oldestAge,
                    'sla_limit' => $slaLimit,
                    'pending_jobs' => $event->metrics->depth->pending ?? 0,
                    'current_workers' => $event->currentWorkers,
                    'target_workers' => $event->decision->targetWorkers,
                ],
            ]);
        }
    }
}
```

### Capacity Alert

```php
public function handle(ScalingDecisionMade $event): void
{
    // Alert if we're at max capacity
    if ($event->decision->targetWorkers >= $event->config->maxWorkers) {
        $this->alerting->send([
            'severity' => 'warning',
            'title' => "Queue at maximum capacity: {$event->config->queue}",
            'message' => "Consider raising max_workers limit",
            'details' => [
                'max_workers' => $event->config->maxWorkers,
                'pending_jobs' => $event->metrics->depth->pending ?? 0,
            ],
        ]);
    }
}
```

### Cost Alert

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;

class AlertOnHighCosts
{
    private const HOURLY_BUDGET = 100.00;
    private const WORKER_COST = 0.50;

    public function handle(WorkersScaled $event): void
    {
        $currentHour = now()->format('Y-m-d-H');
        $cacheKey = "autoscale:cost:{$currentHour}";

        $currentSpend = Cache::get($cacheKey, 0.0);
        $newSpend = $event->newCount * self::WORKER_COST;

        Cache::put($cacheKey, $currentSpend + $newSpend, now()->addHours(2));

        if ($currentSpend + $newSpend > self::HOURLY_BUDGET * 0.8) {
            $this->alerting->send([
                'severity' => 'warning',
                'title' => 'Autoscale costs approaching budget',
                'message' => sprintf('Current: $%.2f, Budget: $%.2f', $currentSpend + $newSpend, self::HOURLY_BUDGET),
            ]);
        }
    }
}
```

## Dashboards

### Recommended Metrics for Dashboards

**Overview Dashboard:**
- Worker count (current, min, max, target)
- Queue depth over time
- Oldest job age vs SLA limit
- Processing rate
- Scaling frequency

**Performance Dashboard:**
- SLA compliance rate
- Predicted vs actual pickup time
- Decision confidence
- Trend forecast accuracy

**Resource Dashboard:**
- CPU usage per worker
- Memory usage per worker
- Worker health status
- Process lifecycle (spawned/terminated)

**Cost Dashboard:**
- Worker cost per hour
- Total autoscale cost (daily/weekly/monthly)
- Cost per job processed
- Cost efficiency trends

### Grafana Example

```json
{
  "dashboard": {
    "title": "Queue Autoscale Monitoring",
    "panels": [
      {
        "title": "Worker Count",
        "targets": [
          {"expr": "queue_workers{queue=\"default\"}"}
        ]
      },
      {
        "title": "Queue Depth",
        "targets": [
          {"expr": "queue_depth{queue=\"default\"}"}
        ]
      },
      {
        "title": "SLA Usage",
        "targets": [
          {"expr": "queue_sla_usage_percent{queue=\"default\"}"}
        ],
        "thresholds": [
          {"value": 80, "color": "yellow"},
          {"value": 90, "color": "red"}
        ]
      }
    ]
  }
}
```

## Troubleshooting

### Issue: Workers Not Scaling

**Symptoms:**
- Queue depth increasing
- Target workers calculated but not spawned

**Check:**
```bash
# Check autoscale manager status
php artisan queue:autoscale:status

# Check worker spawn errors
tail -f storage/logs/laravel.log | grep "worker spawn"

# Check system resources
free -m
ps aux | grep "queue:work"
```

**Common Causes:**
- Insufficient system resources
- Permission issues
- Worker spawn failures

### Issue: Oscillating Worker Count

**Symptoms:**
- Worker count rapidly changing
- Frequent scaling up and down

**Check:**
```sql
SELECT
    timestamp,
    current_workers,
    target_workers,
    decision_reason
FROM autoscale_metrics
WHERE queue = 'default'
ORDER BY timestamp DESC
LIMIT 20;
```

**Solutions:**
- Increase `scale_cooldown_seconds`
- Adjust strategy sensitivity
- Check for metric noise

### Issue: SLA Breaches

**Symptoms:**
- Jobs waiting longer than `max_pickup_time_seconds`
- Oldest job age exceeds SLA

**Check:**
```php
// Check if hitting max_workers
if ($currentWorkers >= $maxWorkers) {
    // Need to raise max_workers
}

// Check processing rate
if ($processingRate < expected) {
    // Workers may be slow or stuck
}
```

**Solutions:**
- Increase `max_workers`
- Optimize job performance
- Check for stuck workers

## See Also

- [Event Handling](event-handling.md) - Using events for monitoring
- [Scaling Policies](../advanced-usage/scaling-policies.md) - Policies for metrics collection
- [Deployment](../advanced-usage/deployment.md) - Production deployment
- [Troubleshooting](troubleshooting.md) - Detailed troubleshooting guide
