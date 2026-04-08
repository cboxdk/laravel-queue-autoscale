---
title: "Architecture"
description: "Deep dive into the Queue Autoscale for Laravel architecture, scaling algorithm, and design decisions"
weight: 50
---

# Architecture

This document provides a deep dive into the Queue Autoscale for Laravel architecture, scaling algorithm, and design decisions.

## Table of Contents

- [Overview](#overview)
- [Theoretical Foundation](#theoretical-foundation)
- [Hybrid Algorithm](#hybrid-algorithm)
- [System Architecture](#system-architecture)
- [Data Flow](#data-flow)
- [Scaling Decision Process](#scaling-decision-process)
- [Resource Management](#resource-management)
- [Extension Points](#extension-points)
- [Performance Considerations](#performance-considerations)

## Overview

Queue Autoscale for Laravel uses a **hybrid predictive algorithm** that combines three complementary approaches:

1. **Rate-Based Scaling** (Little's Law) - Steady-state calculation
2. **Trend-Based Scaling** (Predictive) - Proactive forecasting
3. **Backlog-Based Scaling** (SLA Protection) - Breach prevention

The system takes the **maximum** of all three calculations to ensure SLA compliance while being resource-aware.

## Theoretical Foundation

### Little's Law (L = λW)

The foundation of our rate-based scaling is **Little's Law**, a fundamental theorem in queueing theory:

```
L = λ × W

Where:
- L = Average number of items in system (workers needed)
- λ = Arrival rate (jobs/second)
- W = Average time in system (seconds/job)
```

**Why Little's Law?**
- Mathematically proven relationship between queue length, arrival rate, and processing time
- Works for any stable queueing system regardless of arrival distribution
- Provides theoretical minimum workers needed for steady-state operation

**Our Implementation:**

```php
public function calculate(float $arrivalRate, float $avgProcessingTime): float
{
    if ($arrivalRate <= 0 || $avgProcessingTime <= 0) {
        return 0.0;
    }

    return $arrivalRate * $avgProcessingTime;
}
```

**Example:**
- Processing rate: 10 jobs/sec
- Average job time: 2 seconds
- Workers needed: 10 × 2 = 20 workers

### SLA/SLO-Based Approach

Instead of targeting worker counts, we target **service level objectives**:

```
SLA: Jobs must be picked up within max_pickup_time_seconds
```

This transforms the scaling problem from:
- ❌ "How many workers should we have?" (infrastructure-focused)
- ✅ "How can we meet our SLA?" (business-focused)

**Benefits:**
- Business-aligned metrics instead of technical ones
- Easier to reason about and communicate
- Natural scaling boundaries (SLA compliance vs violation)
- Predictable system behavior

## Hybrid Algorithm

### Algorithm Overview

```
target_workers = max(
    steady_state_workers,    // Little's Law with current rate
    predictive_workers,      // Little's Law with predicted rate
    backlog_drain_workers    // SLA breach prevention
)

final_workers = constrain(
    target_workers,
    min: config.min_workers,
    max: min(config.max_workers, system_capacity)
)
```

### 1. Rate-Based Scaling (Steady State)

**Purpose:** Calculate workers needed for current load.

**Algorithm:**
```
steady_state_workers = processing_rate × avg_job_time
```

**When it dominates:**
- Stable workload with no trend
- Normal operating conditions
- Queue is near equilibrium

**Example:**
```
Processing rate: 5 jobs/sec
Avg job time: 2 sec
Workers: 5 × 2 = 10 workers
```

### 2. Trend-Based Scaling (Predictive)

**Purpose:** Scale proactively based on predicted demand.

**Algorithm:**
```
predicted_rate = current_rate × trend_adjustment

Where trend_adjustment:
- trend='up' with forecast: use forecast directly
- trend='up' without forecast: multiply by 1.2 (20% increase)
- trend='down': multiply by 0.8 (20% decrease)
- trend='stable' or null: use current rate (no adjustment)

predictive_workers = predicted_rate × avg_job_time
```

**When it dominates:**
- Upward trending workload
- Predictable traffic patterns (time of day, day of week)
- Before load spikes occur

**Example:**
```
Current rate: 10 jobs/sec
Trend: up, forecast: 15 jobs/sec
Avg job time: 2 sec
Workers: 15 × 2 = 30 workers (vs 20 for steady state)
```

**Benefit:** Scales up *before* queue depth increases, preventing SLA violations.

### 3. Backlog-Based Scaling (SLA Protection)

**Purpose:** Aggressively prevent SLA breaches when backlog exists.

**Algorithm:**
```
time_until_breach = sla_target - oldest_job_age
action_threshold = sla_target × breach_threshold (default 0.8)

if oldest_job_age < action_threshold:
    return 0  // No urgent action

if time_until_breach <= 0:
    // Already breached - aggressive scaling
    return ceil(backlog / max(avg_job_time, 0.1))

// Calculate workers to drain backlog before breach
jobs_per_worker = max(time_until_breach / avg_job_time, 1.0)
return backlog / jobs_per_worker
```

**When it dominates:**
- Backlog exists and oldest job approaching SLA
- Recovery from downtime or scaling lag
- Burst traffic exceeding predictions

**Example 1: Approaching Breach**
```
SLA target: 30 sec
Oldest job: 25 sec (exceeds 80% threshold of 24 sec)
Time until breach: 5 sec
Backlog: 100 jobs
Avg job time: 2 sec
Jobs per worker: 5 / 2 = 2.5 jobs
Workers: 100 / 2.5 = 40 workers (aggressively scales)
```

**Example 2: Already Breached**
```
SLA target: 30 sec
Oldest job: 35 sec (breached!)
Backlog: 100 jobs
Avg job time: 2 sec
Workers: ceil(100 / 2) = 50 workers (maximum aggression)
```

**Protection:** Prevents cascade failures where SLA breach → more backlog → worse breach.

### Why Maximum?

We take the **maximum** of all three approaches:

```php
$targetWorkers = max(
    $steadyStateWorkers,
    $predictiveWorkers,
    $backlogDrainWorkers,
);
```

**Reasoning:**
- **Conservative approach** - Better to slightly over-scale than violate SLA
- **Covers different scenarios** - Each calculator handles specific conditions
- **Graceful degradation** - If one approach fails/misses, others provide backup
- **SLA compliance prioritized** - Backlog drain ensures we never breach

**Trade-off:** May occasionally over-scale, but:
- Resource constraints prevent waste
- Cooldown periods prevent thrashing
- Extra capacity quickly absorbed by variability in job processing

## System Architecture

### Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    AutoscaleManager                         │
│  ┌───────────────────────────────────────────────────┐     │
│  │ Main Control Loop (every 5 seconds)               │     │
│  │ 1. Get all queues from laravel-queue-metrics      │     │
│  │ 2. For each queue: evaluate & scale               │     │
│  │ 3. Cleanup dead workers                           │     │
│  │ 4. Check for SIGTERM/SIGINT                      │     │
│  └───────────────────────────────────────────────────┘     │
└──────────────────┬──────────────────────────────────────────┘
                   │
         ┌─────────┴──────────┐
         │                    │
    ┌────▼─────┐         ┌────▼────────┐
    │ Scaling  │         │   Worker    │
    │  Engine  │         │ Management  │
    └────┬─────┘         └────┬────────┘
         │                    │
    ┌────▼──────────────┐     │
    │ ScalingStrategy   │     │
    │ (PredictiveStrat) │     │
    └────┬──────────────┘     │
         │                    │
    ┌────▼──────────────┐     │
    │ Calculators:      │     │
    │ • LittlesLaw      │     │
    │ • ArrivalRate     │     │
    │ • BacklogDrain    │     │
    │ • Capacity        │     │
    └───────────────────┘     │
                              │
                  ┌───────────▼────────────┐
                  │ Worker Components:     │
                  │ • WorkerSpawner        │
                  │ • WorkerTerminator     │
                  │ • WorkerPool           │
                  │ • WorkerProcess        │
                  └────────────────────────┘
```

### Class Responsibilities

#### AutoscaleManager
- Main daemon process
- Coordinates entire scaling lifecycle
- Manages control loop timing
- Handles signals (SIGTERM/SIGINT)
- Orchestrates worker pool

#### ScalingEngine
- Evaluates scaling decisions
- Applies constraints (capacity, config)
- Creates ScalingDecision DTOs
- Delegates to strategy

#### PredictiveStrategy
- Implements hybrid algorithm
- Calls all three calculators
- Takes maximum of results
- Provides human-readable reasons
- Estimates pickup time predictions

#### Calculators
- **LittlesLawCalculator:** Pure L = λW implementation
- **ArrivalRateEstimator:** Sliding window arrival rate estimation from backlog changes
- **BacklogDrainCalculator:** SLA breach prevention math
- **CapacityCalculator:** System resource limits

#### Worker Management
- **WorkerSpawner:** Creates queue:work processes
- **WorkerTerminator:** Graceful SIGTERM → SIGKILL shutdown
- **WorkerPool:** Tracks running workers
- **WorkerProcess:** Wraps Symfony Process with metadata

## Data Flow

### Package Boundary and Data Flow

```
┌────────────────────────────────────────────────────────────┐
│        laravel-queue-metrics (Dependency Package)          │
│                                                             │
│  • Scans all queue connections (redis, database, sqs)      │
│  • Discovers active queues automatically                   │
│  • Collects queue depth and age metrics                    │
│  • Calculates processing rates                             │
│  • Analyzes trends and creates forecasts                   │
│  • Aggregates all data into QueueMetricsData objects       │
│                                                             │
│  Public API:                                                │
│  QueueMetrics::getAllQueuesWithMetrics()                   │
│         ↓                                                   │
└─────────┼──────────────────────────────────────────────────┘
          │
          │ Returns: Collection<QueueMetricsData>
          │
          ↓
┌─────────┴──────────────────────────────────────────────────┐
│      laravel-queue-autoscale (This Package)                │
│                                                             │
│  • Receives pre-calculated metrics from facade             │
│  • Applies scaling algorithms (Little's Law, Trend,        │
│    Backlog Drain)                                          │
│  • Makes SLA-based scaling decisions                       │
│  • Manages worker pool lifecycle (spawn/terminate)         │
│  • Enforces resource constraints (CPU/memory limits)       │
│  • Executes scaling policies and broadcasts events         │
│                                                             │
│  DOES NOT:                                                  │
│  ✗ Scan queue connections                                  │
│  ✗ Discover queues                                         │
│  ✗ Collect queue metrics                                   │
│  ✗ Calculate processing rates or trends                    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Key Principle: Single Responsibility**
- **laravel-queue-metrics**: Queue discovery and metrics collection
- **laravel-queue-autoscale**: Scaling decisions and worker management

### 1. Metrics Collection (External Package)

```
laravel-queue-metrics (external package)
    ↓
QueueMetrics::getAllQueuesWithMetrics()
    ↓
Returns: Collection<QueueMetricsData>
    {
        connection: 'redis',
        queue: 'default',
        processingRate: 10.5,  // jobs/sec (pre-calculated)
        activeWorkerCount: 20,
        depth: {
            pending: 150,
            oldestJobAgeSeconds: 25,
        },
        trend: {
            direction: 'up',
            forecast: 15.0,  // (pre-calculated)
        },
    }
```

### 2. Scaling Evaluation

```
AutoscaleManager
    ↓
For each queue metrics:
    ↓
ScalingEngine::evaluate(metrics, config, currentWorkers)
    ↓
PredictiveStrategy::calculateTargetWorkers(metrics, config)
    ↓
┌──────────────────────────────┐
│ Run 3 Calculators:           │
│ 1. LittlesLaw(rate, time)    │
│ 2. TrendPolicy(rate, policy) │
│ 3. BacklogDrain(backlog,sla) │
└──────────────────────────────┘
    ↓
Take max(steady, predictive, backlog)
    ↓
Apply capacity constraints from system-metrics
    ↓
Apply config bounds (min/max workers)
    ↓
Return ScalingDecision
```

### 3. Scaling Execution

```
ScalingDecision
    ↓
If shouldScaleUp():
    workers_to_add = targetWorkers - currentWorkers
    ↓
    WorkerSpawner::spawn(connection, queue, workers_to_add)
    ↓
    WorkerPool::addMany(newWorkers)
    ↓
    Broadcast WorkersScaled event

If shouldScaleDown():
    workers_to_remove = currentWorkers - targetWorkers
    ↓
    WorkerPool::remove(connection, queue, workers_to_remove)
    ↓
    WorkerTerminator::terminate(each removed worker)
    ↓
    Broadcast WorkersScaled event
```

### 4. Worker Lifecycle

```
WorkerSpawner::spawn()
    ↓
Creates Symfony Process:
    [PHP_BINARY, artisan, queue:work, connection,
     --queue=name, --tries=3, --max-time=3600, --sleep=3]
    ↓
Process::start() → Background process
    ↓
Wrapped in WorkerProcess(process, connection, queue, spawnedAt)
    ↓
Added to WorkerPool
    ↓
... Worker processes jobs ...
    ↓
When scaling down:
    ↓
WorkerTerminator::terminate(worker)
    ↓
1. posix_kill(pid, SIGTERM)
2. Wait shutdown_timeout_seconds (default 30s)
3. If still running: posix_kill(pid, SIGKILL)
    ↓
Worker terminated
```

## Scaling Decision Process

### Decision Flow

```
1. Get Metrics
   ├─ processingRate
   ├─ activeWorkerCount
   ├─ backlog (pending jobs)
   ├─ oldestJobAge
   └─ trend

2. Estimate Avg Job Time
   If activeWorkers > 0 && processingRate > 0:
       avgJobTime = activeWorkers / processingRate
   Else:
       avgJobTime = 1.0 (fallback)

3. Calculate All Three Approaches
   ├─ steadyState = processingRate × avgJobTime
   ├─ predictive = predictedRate × avgJobTime
   └─ backlogDrain = calculate based on SLA proximity

4. Take Maximum
   targetWorkers = max(steadyState, predictive, backlogDrain)

5. Apply System Capacity
   maxPossible = CapacityCalculator::calculateMaxWorkers()
   targetWorkers = min(targetWorkers, maxPossible)

6. Apply Config Bounds
   targetWorkers = max(targetWorkers, config.minWorkers)
   targetWorkers = min(targetWorkers, config.maxWorkers)

7. Check Cooldown
   If lastScaledAt + cooldown > now:
       Skip scaling (wait for cooldown)

8. Execute Scaling
   If targetWorkers > currentWorkers:
       Scale Up
   Else if targetWorkers < currentWorkers:
       Scale Down
   Else:
       No Change
```

### Cooldown Logic

Prevents scaling thrash:

```php
if (now()->diffInSeconds($this->lastScaled[$key] ?? 0) < $config->scaleCooldownSeconds) {
    continue; // Skip this queue, still in cooldown
}
```

**Why cooldowns?**
- Workers need time to start and begin processing
- Metrics need time to reflect scaling changes
- Prevents oscillation (scale up → scale down → scale up...)

**Default:** 60 seconds between scaling operations per queue.

## Resource Management

### CPU Constraints

```php
$maxCpuPercent = config('queue-autoscale.resource_limits.max_cpu_percent'); // 90%
$cpuUsage = SystemMetrics::cpuUsage(1.0)->usagePercentage(); // e.g., 60%

$availableCpuPercent = max($maxCpuPercent - $cpuUsage, 0); // 30%

$reserveCores = config('queue-autoscale.resource_limits.reserve_cpu_cores'); // 0.5
$usableCores = max($limits->availableCpuCores() - $reserveCores, 1);

$maxWorkersByCpu = floor($usableCores * ($availableCpuPercent / 100));
```

### Memory Constraints

```php
$maxMemoryPercent = config('queue-autoscale.resource_limits.max_memory_percent'); // 85%
$memoryUsage = SystemMetrics::memory()->usedPercentage(); // e.g., 50%

$availableMemoryPercent = max($maxMemoryPercent - $memoryUsage, 0); // 35%

$workerMemoryMb = config('queue-autoscale.resource_limits.worker_memory_mb_estimate'); // 128 MB
$totalMemoryMb = $limits->availableMemoryBytes() / (1024 * 1024);

$maxWorkersByMemory = floor(
    ($totalMemoryMb * ($availableMemoryPercent / 100)) / $workerMemoryMb
);
```

### Most Restrictive Wins

```php
return max(min($maxWorkersByCpu, $maxWorkersByMemory), 0);
```

Ensures we never exceed either CPU or memory limits.

## Extension Points

### Custom Scaling Strategies

Implement `ScalingStrategyContract`:

```php
interface ScalingStrategyContract
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int;
    public function getLastReason(): string;
    public function getLastPrediction(): ?float;
}
```

**Examples:**
- **TimeOfDayStrategy:** Scale based on time patterns
- **BudgetAwareStrategy:** Cap workers based on cost constraints
- **MLPredictiveStrategy:** Use machine learning for forecasting
- **ConservativeStrategy:** Always maintain buffer capacity

### Scaling Policies

Implement `ScalingPolicy` for hooks:

```php
interface ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): void;
    public function afterScaling(ScalingDecision $decision, bool $success): void;
}
```

**Use Cases:**
- Pre-warming caches before scale-up
- Notifying monitoring systems
- Rate limiting scale operations
- Cost tracking and budgets
- Compliance logging

### Event Subscribers

React to scaling events:

```php
Event::listen(ScalingDecisionMade::class, function ($event) {
    // Log, metrics, external systems
});

Event::listen(WorkersScaled::class, function ($event) {
    // Track worker count metrics
});

Event::listen(SlaBreachPredicted::class, function ($event) {
    // Alert on-call engineers
});
```

## Performance Considerations

### Evaluation Frequency

Default: Every 5 seconds

**Trade-offs:**
- **Faster (1-2s):** More responsive, higher CPU usage, more scaling decisions
- **Slower (10-30s):** Lower overhead, may miss short spikes, delayed reactions

**Recommendation:** 5-10 seconds for most workloads.

### Metrics Overhead

Metrics collection happens in `laravel-queue-metrics` (external):
- Runs in background
- Minimal impact on queue processing
- Pre-aggregated before autoscaler sees them

**Our overhead:**
- Simple calculations (3 multiplications, 1 max)
- O(1) complexity for each queue
- Total: <10ms for dozens of queues

### Worker Spawn Time

```
Process creation: ~50-200ms
Laravel bootstrap: ~100-500ms
Queue worker ready: ~200-700ms total
```

**Implication:** Autoscaler compensates by:
- Predictive scaling (scale before demand)
- Minimum workers (always-ready capacity)
- Cooldown periods (wait for workers to start)

### Memory Footprint

Per worker process:
- Laravel app: ~50-100 MB
- Queue jobs: Variable (10-100+ MB)
- Default estimate: 128 MB

**Total system:**
```
Autoscaler daemon: ~50 MB
Workers: N × 128 MB
```

For 50 workers: ~6.4 GB + Laravel base

## Design Decisions

### Why Not Reactive-Only?

Reactive scaling (respond after queue depth grows) always lags:

```
Load spike → Queue grows → Detect → Scale → Workers start → Begin processing
Total lag: 30-60 seconds
```

Predictive scaling reduces lag by anticipating demand.

### Why Three Approaches?

Each handles different scenarios:

| Scenario | Dominant Approach |
|----------|------------------|
| Stable load | Steady state (Little's Law) |
| Predictable growth | Trend-based |
| Burst traffic | Backlog drain |
| Mixed patterns | Maximum of all three |

### Why SLA-Based?

**Business Alignment:**
- "Jobs picked up within 30s" is business requirement
- Worker counts are implementation detail
- Easier to communicate with stakeholders

**Natural Bounds:**
- SLA compliance = success
- SLA violation = failure
- Clear objective function

### Why Process-Based Workers?

**Isolation:**
- Each worker is separate process
- Memory leaks contained
- Crashes don't affect others

**Control:**
- Can SIGTERM/SIGKILL individual workers
- Easy monitoring (process table)
- Standard Unix tooling works

**Simplicity:**
- No threading complexity
- No shared state issues
- Matches Laravel queue:work model

## Future Enhancements

### Potential Improvements

1. **ML-Based Prediction**
   - Train on historical patterns
   - Better forecasting accuracy
   - Seasonal adjustment

2. **Cost Optimization**
   - Factor in compute costs
   - Balance SLA vs budget
   - Spot instance awareness

3. **Multi-Dimensional Scaling**
   - Scale by job type, not just queue
   - Priority-based worker allocation
   - Resource quotas per tenant

4. **Advanced Metrics**
   - Job failure rates
   - Retry patterns
   - Dependency graphs

5. **Auto-Tuning**
   - Learn optimal min/max workers
   - Adjust cooldown periods
   - Calibrate breach thresholds

### Extensibility by Design

The architecture supports these enhancements through:
- Strategy pattern for algorithms
- Policy hooks for behavior
- Event system for integration
- Dependency injection for swapping components

## Conclusion

Queue Autoscale for Laravel combines queueing theory, predictive analysis, and SLA-based optimization to provide intelligent, automatic worker scaling. The hybrid algorithm ensures SLA compliance while being resource-aware and extensible.

For usage examples, see [Quick Start](../quickstart.md).

For implementation details, review the source code with this architecture in mind.
