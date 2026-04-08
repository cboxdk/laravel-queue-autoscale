---
title: "Performance Tuning"
description: "Queue Autoscale for Laravel performance optimization for maximum efficiency and cost-effectiveness"
weight: 14
---

# Performance Tuning

Optimize Queue Autoscale for Laravel for maximum efficiency and cost-effectiveness.

## Table of Contents
- [Overview](#overview)
- [Configuration Tuning](#configuration-tuning)
- [Strategy Optimization](#strategy-optimization)
- [Resource Efficiency](#resource-efficiency)
- [Scaling Patterns](#scaling-patterns)
- [Cost Optimization](#cost-optimization)
- [Troubleshooting Performance](#troubleshooting-performance)

## Overview

Performance tuning focuses on:
- **Response Time**: How quickly autoscaling reacts to load changes
- **Resource Efficiency**: Minimizing wasted capacity
- **Cost Effectiveness**: Balancing performance and expenses
- **SLA Compliance**: Meeting service level agreements consistently

### Performance Metrics

**Key Indicators:**
- SLA compliance rate (target: >99%)
- Average worker utilization (target: 70-90%)
- Scaling latency (time to adjust workers)
- Cost per job processed
- Oscillation rate (unnecessary scaling events)

## Configuration Tuning

### Evaluation Interval

The `evaluation_interval_seconds` controls how often scaling decisions are made.

```php
'evaluation_interval_seconds' => 30,  // Default
```

**Faster Intervals (10-20s):**
- ✅ Quicker response to traffic spikes
- ✅ Better SLA compliance for burst traffic
- ❌ Higher CPU overhead
- ❌ More potential for oscillation

**Slower Intervals (60-120s):**
- ✅ Lower system overhead
- ✅ More stable, less oscillation
- ❌ Slower reaction to traffic changes
- ❌ Risk of SLA breaches during spikes

**Recommendation:**
```php
// Bursty traffic: Fast response needed
'evaluation_interval_seconds' => 15,

// Steady traffic: Optimize for stability
'evaluation_interval_seconds' => 60,

// Mixed traffic: Balanced approach
'evaluation_interval_seconds' => 30,
```

### Cooldown Period

The `scale_cooldown_seconds` prevents rapid oscillation.

```php
'scale_cooldown_seconds' => 60,  // Default
```

**Shorter Cooldown (30-45s):**
- ✅ Faster reactions to changing load
- ✅ Better for highly variable traffic
- ❌ Risk of oscillation
- ❌ More frequent scaling operations

**Longer Cooldown (90-180s):**
- ✅ Very stable, minimal oscillation
- ✅ Lower scaling overhead
- ❌ Slower to adapt
- ❌ May overprovision during decreasing load

**Recommendation:**
```php
// Critical queue: Balance speed and stability
'scale_cooldown_seconds' => 30,

// Standard queue: Favor stability
'scale_cooldown_seconds' => 60,

// Background queue: Maximize stability
'scale_cooldown_seconds' => 120,
```

### Worker Limits

Set appropriate `min_workers` and `max_workers`.

```php
'min_workers' => 1,
'max_workers' => 20,
```

**Min Workers:**
```php
// Can scale to zero: Cost-optimized for idle queues
'min_workers' => 0,

// Always ready: Instant processing for critical queues
'min_workers' => 5,

// Balanced: Some baseline capacity
'min_workers' => 2,
```

**Max Workers:**
```php
// Calculate based on:
$maxWorkers = min(
    $systemCpuCores * 2,              // System capacity
    $budgetPerHour / $workerCost,     // Cost constraints
    $maxConcurrentJobs                // Application limits
);
```

### SLA Target

The `max_pickup_time_seconds` drives scaling behavior.

```php
'max_pickup_time_seconds' => 60,  // Default
```

**Aggressive SLA (5-15s):**
- ✅ Very responsive system
- ✅ Excellent user experience
- ❌ Higher costs (more workers)
- ❌ May overprovision

**Moderate SLA (30-90s):**
- ✅ Balanced cost and performance
- ✅ Good for most applications
- ❌ Noticeable delays during spikes

**Relaxed SLA (120-300s):**
- ✅ Cost-optimized
- ✅ Suitable for background tasks
- ❌ Slow responsiveness
- ❌ Not suitable for user-facing features

**Recommendation by Queue Type:**
```php
'queues' => [
    // User-facing: Aggressive SLA
    ['queue' => 'notifications', 'max_pickup_time_seconds' => 10],

    // Business-critical: Moderate SLA
    ['queue' => 'orders', 'max_pickup_time_seconds' => 30],

    // Background: Relaxed SLA
    ['queue' => 'reports', 'max_pickup_time_seconds' => 300],
],
```

## Strategy Optimization

### Choosing the Right Strategy

**HybridPredictiveStrategy** (default):
- ✅ Best all-around performance
- ✅ Adapts to different traffic patterns
- ✅ Predictive capabilities
- Use for: Most production workloads

**Custom Strategies:**
- Consider if you have:
  - Very specific traffic patterns
  - Domain-specific knowledge
  - Unique cost constraints
  - Integration with external data

### Tuning Hybrid Strategy

```php
'strategy' => [
    'class' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridPredictiveStrategy::class,
    'options' => [
        'trend_weight' => 0.7,        // How much to trust trend predictions (0-1)
        'safety_margin' => 1.2,       // Safety buffer (1.0 = no buffer, 1.5 = 50% buffer)
        'min_trend_samples' => 3,     // Samples needed for trend analysis
    ],
],
```

**Aggressive Scaling (Responsive):**
```php
'options' => [
    'trend_weight' => 0.8,        // Trust predictions more
    'safety_margin' => 1.3,       // 30% safety buffer
    'min_trend_samples' => 2,     // React quickly
]
```

**Conservative Scaling (Stable):**
```php
'options' => [
    'trend_weight' => 0.5,        // Less trust in predictions
    'safety_margin' => 1.1,       // 10% safety buffer
    'min_trend_samples' => 5,     // Wait for more data
]
```

## Resource Efficiency

### Worker Configuration

Optimize per-worker resource allocation:

```php
'worker_memory' => 256,    // MB per worker
'worker_timeout' => 300,   // seconds
'worker_sleep' => 3,       // seconds when idle
```

**Memory:**
```php
// Measure actual usage
$averageMemory = DB::table('worker_metrics')
    ->avg('memory_mb');

// Set 20% above average
'worker_memory' => (int) ceil($averageMemory * 1.2),
```

**Timeout:**
```php
// Analyze job durations
$p95Duration = DB::table('jobs')
    ->selectRaw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration) as p95')
    ->value('p95');

// Set timeout at P95 + 30%
'worker_timeout' => (int) ceil($p95Duration * 1.3),
```

**Sleep:**
```php
// High-frequency queue: Check often
'worker_sleep' => 1,

// Standard queue: Balance
'worker_sleep' => 3,

// Low-frequency queue: Save CPU
'worker_sleep' => 10,
```

### System Resource Limits

Prevent resource exhaustion:

```php
'resource_limits' => [
    'max_total_workers' => 100,          // Global worker cap
    'max_memory_percent' => 80,          // Max system memory usage
    'max_cpu_percent' => 90,             // Max CPU usage
    'reserved_memory_mb' => 1024,        // Reserve for system
],
```

**Calculate Optimal Max Workers:**
```php
$systemMemoryMb = 16384;  // 16 GB
$reservedMemoryMb = 2048;  // 2 GB reserved
$workerMemoryMb = 256;     // Per worker

$maxWorkersByMemory = floor(
    ($systemMemoryMb - $reservedMemoryMb) / $workerMemoryMb
);  // 56 workers

$cpuCores = 8;
$maxWorkersByCpu = $cpuCores * 2;  // 16 workers

// Use the more conservative limit
$maxWorkers = min($maxWorkersByMemory, $maxWorkersByCpu);  // 16
```

### Queue Prioritization

Route jobs to appropriate queues:

```php
// High priority: Fast SLA, dedicated workers
dispatch(new CriticalJob())->onQueue('critical');

// Normal priority: Standard processing
dispatch(new StandardJob())->onQueue('default');

// Low priority: Batch processing
dispatch(new ReportJob())->onQueue('background');
```

Configure different performance profiles:

```php
'queues' => [
    [
        'queue' => 'critical',
        'max_pickup_time_seconds' => 5,
        'min_workers' => 5,
        'max_workers' => 30,
        'scale_cooldown_seconds' => 20,
    ],
    [
        'queue' => 'default',
        'max_pickup_time_seconds' => 60,
        'min_workers' => 1,
        'max_workers' => 15,
        'scale_cooldown_seconds' => 60,
    ],
    [
        'queue' => 'background',
        'max_pickup_time_seconds' => 300,
        'min_workers' => 0,
        'max_workers' => 5,
        'scale_cooldown_seconds' => 120,
    ],
],
```

## Scaling Patterns

### Pattern 1: Predictable Daily Traffic

For traffic with daily patterns (business hours):

```php
use Illuminate\Support\Facades\Schedule;

// Scale up before business hours
Schedule::call(function () {
    app(AutoscaleManager::class)->overrideMinWorkers('default', 10);
})->weekdays()->at('08:30');

// Scale down after business hours
Schedule::call(function () {
    app(AutoscaleManager::class)->overrideMinWorkers('default', 2);
})->weekdays()->at('18:00');
```

Or use time-based strategy:

```php
'strategy' => \App\Strategies\TimeBasedStrategy::class,
```

### Pattern 2: Event-Driven Spikes

For predictable events (sales, releases):

```php
// Before major event
Event::listen(MajorEventStarting::class, function () {
    app(AutoscaleManager::class)->scaleToCapacity('orders', percentage: 80);
});

// After event
Event::listen(MajorEventEnded::class, function () {
    app(AutoscaleManager::class)->resetToNormal('orders');
});
```

### Pattern 3: Gradual Ramp-Up

For smooth scaling during increases:

```php
'options' => [
    'max_scale_up_percent' => 50,    // Max 50% increase per evaluation
    'max_scale_down_percent' => 25,  // Max 25% decrease per evaluation
]
```

Implementation in custom strategy:

```php
$targetWorkers = $this->calculateTarget($metrics, $config);
$currentWorkers = $metrics->activeWorkerCount;

// Limit increase
if ($targetWorkers > $currentWorkers) {
    $maxIncrease = (int) ceil($currentWorkers * 0.5);  // 50%
    $targetWorkers = min($targetWorkers, $currentWorkers + $maxIncrease);
}

// Limit decrease
if ($targetWorkers < $currentWorkers) {
    $maxDecrease = (int) ceil($currentWorkers * 0.25);  // 25%
    $targetWorkers = max($targetWorkers, $currentWorkers - $maxDecrease);
}
```

## Cost Optimization

### Calculate Cost Per Job

```php
$workerCostPerHour = 0.50;
$averageJobDuration = 10;  // seconds
$jobsPerWorkerPerHour = 3600 / $averageJobDuration;  // 360 jobs

$costPerJob = $workerCostPerHour / $jobsPerWorkerPerHour;  // $0.00139
```

### Optimize Worker Utilization

**Target: 70-90% utilization**

```php
// Calculate current utilization
$processingTime = $averageJobDuration * $jobsProcessedPerHour;
$availableTime = $workers * 3600;
$utilization = $processingTime / $availableTime;

if ($utilization < 0.7) {
    // Underutilized: Reduce workers
} elseif ($utilization > 0.9) {
    // Overutilized: Add workers
}
```

### Cost-Aware Strategy

Implement budget constraints:

```php
class CostAwareStrategy implements ScalingStrategyContract
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Calculate ideal workers
        $idealWorkers = $this->calculateIdeal($metrics, $config);

        // Apply budget constraint
        $hourlyBudget = 100.00;
        $workerCost = 0.50;
        $maxAffordableWorkers = (int) floor($hourlyBudget / $workerCost);

        return min($idealWorkers, $maxAffordableWorkers);
    }
}
```

### Spot Instance Strategy

For cloud deployments, use spot instances for cost savings:

```php
'worker_spawn_strategy' => 'spot',  // Use spot instances
'worker_fallback_strategy' => 'on_demand',  // Fallback to on-demand

'max_spot_workers' => 15,       // Most workers on spot
'min_on_demand_workers' => 3,   // Guarantee with on-demand
```

## Troubleshooting Performance

### Issue: Slow Scaling Response

**Symptoms:**
- Jobs pile up before workers scale
- Slow reaction to traffic spikes

**Diagnosis:**
```sql
SELECT
    timestamp,
    pending_jobs,
    current_workers,
    target_workers,
    TIMESTAMPDIFF(SECOND, LAG(timestamp) OVER (ORDER BY timestamp), timestamp) as seconds_between_evals
FROM autoscale_metrics
WHERE queue = 'default'
ORDER BY timestamp DESC
LIMIT 20;
```

**Solutions:**
1. Reduce `evaluation_interval_seconds`
2. Reduce `scale_cooldown_seconds`
3. Increase `trend_weight` for more predictive scaling
4. Raise `min_workers` for baseline capacity

### Issue: Worker Oscillation

**Symptoms:**
- Worker count rapidly changing
- Inefficient resource usage

**Diagnosis:**
```sql
SELECT
    COUNT(*) as scaling_events,
    SUM(ABS(worker_change)) as total_churn
FROM autoscale_decisions
WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    AND worker_change != 0;
```

**Solutions:**
1. Increase `scale_cooldown_seconds`
2. Add safety margin in strategy
3. Implement gradual scaling limits
4. Smooth out metric noise

### Issue: High Costs

**Symptoms:**
- Worker count consistently at or near max
- High cloud bills

**Diagnosis:**
```sql
SELECT
    AVG(current_workers) as avg_workers,
    MAX(current_workers) as peak_workers,
    COUNT(*) as evaluations,
    SUM(CASE WHEN current_workers = max_workers THEN 1 ELSE 0 END) / COUNT(*) * 100 as percent_at_max
FROM autoscale_metrics
WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

**Solutions:**
1. Optimize job performance (faster jobs = fewer workers)
2. Increase `max_pickup_time_seconds` (relax SLA)
3. Implement cost-aware strategy
4. Use queue prioritization
5. Batch similar jobs together

### Issue: SLA Breaches

**Symptoms:**
- Jobs waiting longer than target
- Poor user experience

**Diagnosis:**
```sql
SELECT
    AVG(oldest_job_age) as avg_age,
    MAX(oldest_job_age) as max_age,
    AVG(max_pickup_time_seconds) as sla_target,
    SUM(CASE WHEN oldest_job_age > max_pickup_time_seconds THEN 1 ELSE 0 END) / COUNT(*) * 100 as breach_rate
FROM autoscale_metrics
WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

**Solutions:**
1. Increase `max_workers`
2. Reduce `max_pickup_time_seconds` (stricter SLA triggers earlier scaling)
3. Optimize job performance
4. Check for stuck workers
5. Implement worker health checks

## Performance Benchmarks

### Expected Performance

| Traffic Pattern | SLA Compliance | Avg Utilization | Scaling Latency |
|----------------|----------------|-----------------|-----------------|
| Steady          | >99%           | 75-85%          | N/A (stable)    |
| Gradual increase| >98%           | 70-80%          | 30-60s          |
| Sudden spike    | >95%           | 60-90%          | 15-45s          |
| Burst traffic   | >90%           | 50-95%          | 10-30s          |

### Tuning for Your Workload

Measure and optimize iteratively:

```php
// 1. Baseline measurement (1 week)
$this->measureBaseline();

// 2. Identify bottlenecks
$this->analyzeMetrics();

// 3. Apply optimizations
$this->tuneConfiguration();

// 4. Measure improvement
$this->comparePerformance();

// 5. Repeat
```

## See Also

- [Configuration](configuration.md) - Detailed configuration options
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Custom strategy development
- [Monitoring](monitoring.md) - Performance monitoring
- [How It Works](how-it-works.md) - Algorithm explanation
