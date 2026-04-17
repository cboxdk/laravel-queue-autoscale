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

`scaling.cooldown_seconds` (a top-level global setting) prevents rapid oscillation.

```php
'scaling' => ['cooldown_seconds' => 60],  // Default
```

**Shorter Cooldown (30-45s):** fast reactions, better for variable traffic, but risk of oscillation.

**Longer Cooldown (90-180s):** very stable, but slower to adapt and may overprovision during decreasing load.

### Worker Limits

Per-queue bounds live under the `workers` key — set via profile or override:

```php
'queues' => [
    'payments' => ['workers' => ['min' => 5, 'max' => 50]],  // Always warm
    'emails'   => ['workers' => ['min' => 0, 'max' => 20]],  // Can scale to zero
],
```

The right ceiling depends on:

```php
$maxWorkers = min(
    $systemCpuCores * 2,              // System capacity
    $budgetPerHour / $workerCost,     // Cost constraints
    $maxConcurrentJobs,               // Application limits
);
```

### SLA Target

`sla.target_seconds` drives scaling behavior. Change it via a profile or a per-queue override.

```php
'queues' => [
    'payments' => ['sla' => ['target_seconds' => 10]],
    'reports'  => ['sla' => ['target_seconds' => 300]],
],
```

**Aggressive SLA (5-15s):** very responsive, but higher cost and potential overprovisioning. Use `CriticalProfile` for the full bundle.

**Moderate SLA (30-90s):** balanced cost and performance — `BalancedProfile`.

**Relaxed SLA (120-300s):** cost-optimised — `BackgroundProfile`.

See [Workload Profiles](workload-profiles.md) for the full comparison.

## Strategy Optimization

### Choosing the Right Strategy

**HybridStrategy** (default):
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
    'class' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy::class,
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

### Worker configuration

Per-worker runtime knobs live under the `workers` key of a queue config:

```php
'queues' => [
    'exports' => [
        'workers' => [
            'timeout_seconds' => 300,  // --max-time= on queue:work
            'sleep_seconds' => 3,      // --sleep= on queue:work
            'tries' => 3,              // --tries= on queue:work
        ],
    ],
],
```

**Tuning `timeout_seconds`** (how long a worker is kept alive before recycling). Profile your jobs and set it at p95 + ~30%:

```php
// Look at recent job durations in your metrics store or database.
// Set timeout_seconds at p95 + 30%.
```

**Tuning `sleep_seconds`** (how long a worker sleeps when the queue is empty). Higher-frequency queues benefit from 1–2s; background queues save CPU with 5–10s.

### System resource limits

The global `limits` section protects the host from runaway spawning:

```php
'limits' => [
    'max_cpu_percent' => 85,            // Skip spawning at or above this
    'max_memory_percent' => 85,         // Same for memory
    'worker_memory_mb_estimate' => 128, // Used to derive a per-worker ceiling
    'reserve_cpu_cores' => 1,           // Cores kept for OS/other services
],
```

**How the worker ceiling is derived** (see [Resource Constraints](../algorithms/resource-constraints.md) for the full math):

```php
$maxByMemory = floor(
    $systemMemoryMb * ($limits['max_memory_percent'] / 100) / $limits['worker_memory_mb_estimate']
);

$maxByCpu = ($cpuCores - $limits['reserve_cpu_cores']) * 2;

$hostCeiling = min($maxByMemory, $maxByCpu);
```

The autoscaler's per-queue `workers.max` is further capped by this host ceiling.

### Queue prioritisation

Route jobs to appropriate queues:

```php
// High priority: tight SLA, always warm
dispatch(new CriticalJob())->onQueue('critical');

// Standard
dispatch(new StandardJob())->onQueue('default');

// Low priority
dispatch(new ReportJob())->onQueue('background');
```

And pick a profile per tier:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;

'queues' => [
    'critical'   => CriticalProfile::class,    // 10s SLA, 5-50 workers
    'default'    => BalancedProfile::class,    // 30s SLA, 1-10 workers
    'background' => BackgroundProfile::class,  // 300s SLA, 0-5 workers
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

**Diagnosis:** run the manager in `-vv` mode and watch the time between evaluation cycles and the `current → target` transitions. If several cycles pass with `current < target` and no spawn, the cooldown or a policy is blocking.

**Solutions:**
1. Reduce `manager.evaluation_interval_seconds` (default 5s)
2. Reduce `scaling.cooldown_seconds` (default 60s)
3. Swap to a profile with a more aggressive forecast policy (`CriticalProfile` or `BurstyProfile`)
4. Raise `workers.min` so cold-start latency is not a factor

### Issue: Worker Oscillation

**Symptoms:**
- Worker count rapidly changing
- Inefficient resource usage

**Diagnosis:** run the manager in `-vv` mode during the oscillation window. The log shows every decision with reasoning. If you see `scaled UP` and `scaled DOWN` for the same queue within one cooldown window, anti-flapping didn't help — the strategy itself is oscillating.

Alternatively listen on the `WorkersScaled` event and count direction reversals per queue per minute (see [Cookbook → Alert via Log](../cookbook/alert-via-log.md)).

**Solutions:**
1. Increase `scaling.cooldown_seconds`
2. Use a profile with a higher `sla.min_samples` (larger p95 window smooths noise)
3. Consider a custom policy that rejects small scale-down steps — see [ConservativeScaleDownPolicy](scaling-policies.md)

### Issue: High Costs

**Symptoms:**
- Worker count consistently at or near `workers.max`
- High cloud bills

**Diagnosis:** listen on the `ScalingDecisionMade` event and record how often the manager reports `limitingFactor === 'config'` — that means the configured max is the bottleneck, not capacity or demand. A single log listener with a counter suffices.

**Solutions:**
1. Optimise job performance — faster jobs need fewer workers
2. Relax the SLA: swap to `BalancedProfile` or `BackgroundProfile`, or raise `sla.target_seconds`
3. Lower `workers.max` if the high count is driving cost faster than it's helping SLA
4. Use queue prioritisation (critical vs. best-effort queues on separate profiles)
5. Batch similar small jobs together

### Issue: SLA Breaches

**Symptoms:**
- Jobs waiting longer than target
- `SlaBreached` events firing

**Diagnosis:** listen on `SlaBreached` / `SlaRecovered` and aggregate breach durations. Or run `php artisan queue:autoscale:debug --queue=X` during a breach to see pickup-time percentiles and backlog.

**Solutions:**
1. Increase `workers.max` (you may be capacity-constrained)
2. Increase `workers.min` (cold-start latency at scale-up may be the culprit)
3. Tighten `sla.target_seconds` — counter-intuitive, but a stricter SLA triggers earlier backlog-drain scaling
4. Check for stuck workers via `ps aux | grep queue:work` — a hung worker consumes a slot without draining
5. Lower `limits.max_cpu_percent` if the host is starving workers

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
