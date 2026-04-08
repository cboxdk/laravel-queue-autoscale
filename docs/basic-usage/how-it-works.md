---
title: "How It Works"
description: "Comprehensive guide to understanding how the autoscaler makes scaling decisions"
weight: 10
---

# How It Works

Queue Autoscale for Laravel uses a hybrid predictive algorithm to make intelligent scaling decisions.

## Overview

Queue Autoscale for Laravel uses a **hybrid predictive algorithm** that combines three different scaling approaches to make intelligent decisions about worker counts:

1. **Little's Law** - Steady-state calculation based on current workload
2. **Trend Prediction** - Proactive scaling based on traffic forecasts
3. **Backlog Drain** - Aggressive scaling to prevent SLA breaches

The autoscaler takes the **maximum** of these three calculations to ensure SLA compliance while being responsive to changing conditions.

## The Evaluation Loop

### 1. Metrics Retrieval Phase

Every evaluation cycle (default: 5 seconds), the autoscaler:

```
1. Retrieves all queues and metrics from laravel-queue-metrics
   └─ Single call: QueueMetrics::getAllQueuesWithMetrics()

2. Receives comprehensive queue data
   ├─ Queue connection and name
   ├─ Current worker count
   ├─ Processing rate (jobs/second)
   ├─ Pending job count (backlog depth)
   ├─ Oldest job age
   ├─ Trend data (historical rates and forecasts)
   └─ Processing time statistics

3. Loads per-queue configuration
   └─ SLA targets, min/max workers, cooldown periods
```

**Package Separation:**
- **laravel-queue-metrics** does: Queue discovery, connection scanning, metrics collection
- **laravel-queue-autoscale** does: Consumes metrics, applies algorithms, manages workers

### 2. Calculation Phase

For each queue received from the metrics package, the autoscaler calculates three target worker counts:

#### A. Little's Law (Steady State)

```
Workers_steady = Arrival_Rate × Average_Job_Time
```

**Purpose**: Baseline calculation for current workload
**When it dominates**: Stable traffic, no backlog
**Example**:
- Rate: 10 jobs/sec
- Avg time: 2 seconds/job
- Workers: 10 × 2 = 20 workers

#### B. Trend Prediction (Proactive)

```
Workers_predicted = Forecasted_Rate × Average_Job_Time
```

**Purpose**: Scale ahead of demand increases
**When it dominates**: Traffic trending upward
**Example**:
- Current rate: 10 jobs/sec
- Trend: +20% (forecasted: 12 jobs/sec)
- Avg time: 2 seconds/job
- Workers: 12 × 2 = 24 workers

#### C. Backlog Drain (SLA Protection)

```
Workers_drain = Backlog / (Time_Until_Breach / Avg_Job_Time)
```

**Purpose**: Prevent SLA violations
**When it dominates**: Old jobs approaching SLA target
**Example**:
- Backlog: 100 jobs
- Oldest job: 25 seconds old
- SLA target: 30 seconds
- Time remaining: 5 seconds
- Avg time: 2 seconds/job
- Jobs per worker: 5s / 2s = 2.5 jobs
- Workers: 100 / 2.5 = 40 workers

### 3. Decision Phase

```
1. Take maximum of three calculations
   target = max(steady, predicted, drain)

2. Apply constraints
   ├─ System capacity limits (CPU/memory from system-metrics)
   ├─ Configured min/max workers per queue
   └─ Cooldown periods (prevent rapid scaling)

3. Create scaling decision
   ├─ Current worker count
   ├─ Target worker count
   ├─ Reason for decision
   ├─ Predicted pickup time
   └─ SLA target
```

### 4. Execution Phase

```
1. Execute "before" policies
   ├─ Validation hooks
   ├─ Logging
   └─ External notifications

2. Scale workers
   ├─ If target > current: Spawn new workers
   ├─ If target < current: Terminate excess workers
   └─ If target = current: No action

3. Execute "after" policies
   ├─ Metrics collection
   ├─ Notifications
   └─ Cleanup

4. Broadcast events
   ├─ ScalingDecisionMade (every cycle)
   ├─ WorkersScaled (on changes)
   └─ SlaBreachPredicted (on breach risk)
```

## Example Scenarios

### Scenario 1: Gradual Traffic Increase

```
Time: 09:00 - Morning traffic starts
├─ Rate: 5 jobs/sec → Workers: 10 (Little's Law)
│
Time: 09:15 - Traffic increasing
├─ Rate: 8 jobs/sec
├─ Trend: +20% forecast → 9.6 jobs/sec
└─ Workers: 20 (Trend prediction wins)
│
Time: 09:30 - Peak traffic
├─ Rate: 12 jobs/sec
└─ Workers: 24 (Steady state sufficient)
```

**Result**: Smooth scaling without SLA breaches

### Scenario 2: Sudden Traffic Spike

```
Time: 10:00 - Normal traffic
├─ Rate: 10 jobs/sec
├─ Backlog: 0
└─ Workers: 20
│
Time: 10:01 - Marketing campaign starts
├─ Rate: 50 jobs/sec (5x increase!)
├─ Backlog: 200 jobs accumulating
├─ Oldest job: 15 seconds old
│
Time: 10:02 - Autoscaler responds
├─ Steady: 50 × 2 = 100 workers
├─ Predicted: 60 × 2 = 120 workers (trend up)
├─ Backlog drain: 200 / ((30-15)/2) = 27 workers
└─ Workers: 120 (Predicted wins)
│
Time: 10:03 - Jobs aging, SLA at risk
├─ Oldest job: 28 seconds (2s from breach!)
├─ Backlog drain: 200 / ((30-28)/2) = 200 workers
└─ Workers: 200 (SLA protection kicks in!)
```

**Result**: Aggressive scaling prevents SLA breach

### Scenario 3: Traffic Decrease

```
Time: 17:00 - Peak traffic ending
├─ Rate: 20 jobs/sec
└─ Workers: 40
│
Time: 17:15 - Traffic declining
├─ Rate: 15 jobs/sec
├─ Trend: -20% forecast → 12 jobs/sec
├─ Workers: 30 (Little's Law)
└─ Cooldown prevents immediate scale-down
│
Time: 17:20 - Cooldown expires
├─ Rate: 10 jobs/sec
└─ Workers: 20 (gradual scale-down)
│
Time: 18:00 - Minimal traffic
├─ Rate: 2 jobs/sec
└─ Workers: 4 → 1 (min_workers)
```

**Result**: Gradual, cost-effective scale-down

## SLA Target Behavior

### How SLA Targets Work

Instead of saying "I want 10 workers", you say:
```php
'max_pickup_time_seconds' => 30
```

This means: **"Jobs should start processing within 30 seconds of being queued"**

The autoscaler calculates how many workers are needed to meet this target.

### Breach Prevention

The autoscaler is **proactive** about SLA targets:

```
SLA Target: 30 seconds
Breach Threshold: 80% (24 seconds) - configurable

┌─────────────────────────────────────┐
│  0s    12s    24s         30s       │
│  ├──────┴──────┼───────────┤        │
│  Safe         Action      Breach    │
│              Threshold               │
└─────────────────────────────────────┘

When oldest job reaches 24s:
→ Backlog drain algorithm activates
→ Aggressive scaling to prevent breach
```

### Multiple SLA Tiers

You can configure different SLAs per queue:

```php
'sla_defaults' => [
    'max_pickup_time_seconds' => 60,  // Default: 1 minute
],

'queue_overrides' => [
    'critical' => [
        'max_pickup_time_seconds' => 10,  // 10 seconds
    ],
    'emails' => [
        'max_pickup_time_seconds' => 300,  // 5 minutes
    ],
],
```

## Worker Lifecycle

### Spawning Workers

```
1. Autoscaler determines need for new workers
2. WorkerSpawner creates Symfony Process:
   php artisan queue:work {connection} --queue={queue}
3. Process starts in background
4. WorkerPool tracks process metadata:
   ├─ PID
   ├─ Connection/queue
   ├─ Spawn time
   └─ Health status
```

### Monitoring Workers

```
Every evaluation cycle:
1. ProcessHealthCheck verifies worker health
   ├─ Process still running?
   ├─ Process responding?
   └─ Process memory/CPU within limits?

2. Dead workers removed from pool
3. Health data used for scaling decisions
```

### Terminating Workers

```
When scaling down:
1. Select workers to terminate (oldest first)
2. Send SIGTERM (graceful shutdown)
3. Wait 10 seconds for graceful exit
4. Send SIGKILL if still running (force)
5. Remove from worker pool
```

**Why graceful shutdown matters:**
- Allows jobs to complete
- Prevents job failures
- Maintains data integrity

## Resource Constraints

### System Capacity

The `CapacityCalculator` uses `system-metrics` package to determine available resources:

```
Available CPU cores: 8
Available memory: 16 GB
Current worker cost: ~100 MB RAM per worker

Max workers by RAM: 16000 MB / 100 MB = 160 workers
Max workers by CPU: 8 cores × 2 = 16 workers (conservative)

Capacity limit: min(160, 16) = 16 workers
```

### Configuration Limits

```php
'min_workers' => 1,   // Always maintain at least 1
'max_workers' => 10,  // Never exceed 10
```

### Cooldown Periods

```php
'scale_cooldown_seconds' => 60,
```

**Purpose**: Prevent rapid scaling oscillations

```
Scale up at 10:00 → Workers: 5 → 10
Cooldown until 10:01
Can scale again at 10:01
```

## Metrics and Visibility

### What Gets Logged

Every evaluation cycle logs:
```
[autoscale] Queue: redis/default
  Current: 5 workers
  Target: 8 workers
  Reason: "trend predicts rate increase: 10.00/s → 12.00/s"
  Action: Spawning 3 workers
```

### What Events Fire

```php
// Every cycle
ScalingDecisionMade::class

// On worker changes
WorkersScaled::class
  ->from(5)
  ->to(8)
  ->change(+3)

// On SLA risk
SlaBreachPredicted::class
  ->queue('default')
  ->predictedPickupTime(28.5)
  ->slaTarget(30)
```

### What Metrics Are Tracked

From `laravel-queue-metrics` package:
- Processing rate (jobs/second)
- Active worker count
- Pending job count
- Oldest job age
- Trend data (historical rates)

### Metrics Package Setup

All metrics are collected by the `laravel-queue-metrics` package. Ensure it's properly configured:

**Storage Setup:**

```env
# Redis (recommended for autoscaling)
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default

# OR Database (for persistent metrics)
QUEUE_METRICS_STORAGE=database
```

**Installation:**

```bash
composer require cboxdk/laravel-queue-metrics
php artisan vendor:publish --tag=queue-metrics-config
```

**Learn more:** [Metrics Package Documentation](https://github.com/cboxdk/laravel-queue-metrics)

## Common Questions

### Q: Why did workers scale up when queue was empty?

**A**: Trend prediction detected traffic increase before jobs arrived. This is **proactive scaling**.

### Q: Why didn't workers scale down immediately?

**A**: Cooldown period prevents rapid scaling. Wait for cooldown to expire.

### Q: Why are there more workers than jobs?

**A**: Workers are scaled for **rate**, not backlog. A high job rate needs many workers even if backlog is small.

### Q: Can I force immediate scaling?

**A**: Reduce `scale_cooldown_seconds` but be cautious of oscillations.

### Q: What happens if system runs out of resources?

**A**: `CapacityCalculator` limits workers to available CPU/memory automatically.

## Next Steps

- [Configuration Guide](configuration.md) - Configure SLA targets and limits
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Write your own scaling logic
- [Monitoring Guide](monitoring.md) - Track autoscaler performance
- [Algorithm Details](../algorithms/architecture.md) - Deep dive into math
