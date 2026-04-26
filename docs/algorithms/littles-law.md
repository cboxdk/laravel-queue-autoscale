---
title: "Little's Law"
description: "Mathematical foundation for queue-based autoscaling using Little's Law"
weight: 51
---

# Little's Law

Mathematical foundation for queue-based autoscaling using Little's Law.

## Overview

Little's Law is a fundamental theorem in queue theory that relates:
- **L** (Average number of items in system)
- **λ** (Average arrival rate)
- **W** (Average time in system)

**Formula:**
```
L = λ × W
```

Or rearranged for worker calculation:
```
Workers = (Pending Jobs × Target Pickup Time) / Processing Rate
```

## Mathematical Foundation

### Original Little's Law

For a stable system in equilibrium:

```
L = λ × W

Where:
L = Average number of customers in system
λ = Average arrival rate (customers/time)
W = Average time spent in system
```

**Example:**
- If 10 customers/minute arrive (λ = 10)
- And each spends 5 minutes in the system (W = 5)
- Then average occupancy is 50 customers (L = 10 × 5)

### Application to Queue Workers

Translate to queue worker calculation:

```
Required Workers = (Jobs in Queue × Processing Time) / SLA Target

Or more precisely:
Workers = (L × W_target) / W_processing

Where:
L = Current queue depth (pending jobs)
W_target = Target pickup time (SLA)
W_processing = Average job processing time
```

## Implementation

### Basic Little's Law Strategy

```php
public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
{
    $pendingJobs = $metrics->depth->pending ?? 0;
    $processingRate = $metrics->processingRate ?? 0.0;  // jobs/second
    $targetPickupTime = $config->maxPickupTimeSeconds;

    if ($pendingJobs === 0 || $processingRate === 0) {
        return $config->minWorkers;
    }

    // Little's Law: L = λ × W
    // Rearranged: Workers needed = L / (λ × W)
    //
    // Where:
    // L = pending jobs
    // λ = processing rate per worker
    // W = target pickup time

    $requiredThroughput = $pendingJobs / $targetPickupTime;  // jobs/second needed
    $workers = (int) ceil($requiredThroughput / $processingRate);

    return max($config->minWorkers, min($config->maxWorkers, $workers));
}
```

### With Processing Rate Calculation

```php
private function calculateProcessingRate(object $metrics): float
{
    // Option 1: Use measured rate from metrics
    if (isset($metrics->processingRate) && $metrics->processingRate > 0) {
        return $metrics->processingRate;
    }

    // Option 2: Calculate from historical data
    $completedJobs = $this->getRecentlyCompletedJobs();
    $timeWindow = 60;  // seconds

    return $completedJobs / $timeWindow;
}
```

## Examples

### Example 1: Steady State

**Scenario:**
- 100 jobs in queue
- Processing rate: 10 jobs/second per worker
- Target SLA: 60 seconds

**Calculation:**
```
Required throughput = 100 jobs / 60 seconds = 1.67 jobs/second
Workers needed = 1.67 / 10 = 0.167 ≈ 1 worker
```

The queue can be drained within SLA with 1 worker.

### Example 2: High Load

**Scenario:**
- 1000 jobs in queue
- Processing rate: 5 jobs/second per worker
- Target SLA: 30 seconds

**Calculation:**
```
Required throughput = 1000 / 30 = 33.33 jobs/second
Workers needed = 33.33 / 5 = 6.67 ≈ 7 workers
```

Need 7 workers to meet SLA.

### Example 3: SLA Breach Risk

**Scenario:**
- 500 jobs in queue
- Current workers: 3
- Processing rate: 2 jobs/second per worker
- Oldest job age: 25 seconds
- Target SLA: 30 seconds

**Calculation:**
```
Time remaining = 30 - 25 = 5 seconds
Required throughput = 500 / 5 = 100 jobs/second
Current throughput = 3 workers × 2 jobs/sec = 6 jobs/second

Workers needed = 100 / 2 = 50 workers
```

SLA breach imminent! Need aggressive scaling.

## Strengths and Limitations

### Strengths

1. **Mathematical rigor**: Based on proven queue theory
2. **Predictable**: Deterministic calculations
3. **Simple**: Easy to understand and implement
4. **Stable**: Works well for steady-state traffic

### Limitations

1. **Assumes steady state**: Doesn't account for trends
2. **No prediction**: Reactive, not proactive
3. **Ignores variance**: Assumes consistent processing rates
4. **No learning**: Doesn't improve over time

## Enhancements

### Enhancement 1: Safety Margin

Add buffer for uncertainty:

```php
$baseWorkers = $this->calculateWithLittlesLaw($metrics, $config);
$safetyMargin = 1.2;  // 20% buffer
$workers = (int) ceil($baseWorkers * $safetyMargin);
```

### Enhancement 2: Processing Rate Smoothing

Use moving average for stability:

```php
$currentRate = $metrics->processingRate;
$historicalRates = $this->getRecentRates();

// Exponential moving average
$alpha = 0.3;
$smoothedRate = $alpha * $currentRate + (1 - $alpha) * $historicalRates->average();
```

### Enhancement 3: Minimum Viable Workers

Ensure baseline capacity:

```php
$littlesLawWorkers = $this->calculateWithLittlesLaw($metrics, $config);
$baselineWorkers = max(
    $config->minWorkers,
    (int) ceil($metrics->processingRate * 0.1)  // 10% of max throughput
);

$workers = max($littlesLawWorkers, $baselineWorkers);
```

## Integration with Hybrid Strategy

Little's Law forms the foundation of the hybrid strategy:

```php
class HybridStrategy
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // 1. Little's Law for steady state
        $steadyStateWorkers = $this->littlesLaw($metrics, $config);

        // 2. Trend prediction for growing load
        $trendWorkers = $this->trendBased($metrics, $config);

        // 3. Backlog drain for SLA protection
        $backlogWorkers = $this->backlogDrain($metrics, $config);

        // Use maximum (most conservative)
        return max($steadyStateWorkers, $trendWorkers, $backlogWorkers);
    }

    private function littlesLaw(object $metrics, QueueConfiguration $config): int
    {
        $pending = $metrics->depth->pending ?? 0;
        $rate = $metrics->processingRate ?? 0.0;
        $sla = $config->maxPickupTimeSeconds;

        if ($pending === 0 || $rate === 0) {
            return $config->minWorkers;
        }

        return (int) ceil(($pending / $sla) / $rate);
    }
}
```

## Performance Characteristics

### Time Complexity
- **O(1)**: Constant time calculation
- Extremely fast, suitable for high-frequency evaluation

### Space Complexity
- **O(1)**: No additional storage required
- Can enhance with historical data (O(n) for n samples)

### Accuracy
- **High** for steady-state traffic
- **Medium** for gradually changing traffic
- **Low** for rapidly changing or bursty traffic

## Best Practices

1. **Use as foundation**: Combine with trend and backlog algorithms
2. **Smooth processing rates**: Use moving averages to reduce noise
3. **Add safety margins**: Buffer for uncertainty
4. **Validate assumptions**: Ensure processing rate is accurate
5. **Monitor performance**: Track prediction accuracy

## See Also

- [Trend Prediction](trend-prediction.md) - Predictive scaling
- [Backlog Drain](backlog-drain.md) - SLA protection
- [How It Works](../basic-usage/how-it-works.md) - Complete algorithm explanation
