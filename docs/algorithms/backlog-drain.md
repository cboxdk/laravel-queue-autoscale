---
title: "Backlog Drain"
description: "SLA-focused algorithm for preventing service level agreement breaches"
weight: 53
---

# Backlog Drain

SLA-focused algorithm for preventing service level agreement breaches.

## Overview

The backlog drain algorithm provides **SLA protection** by:
- Monitoring oldest job age
- Detecting imminent SLA breaches
- Aggressively scaling to meet deadlines
- Prioritizing SLA compliance over cost

**Goal:** Ensure no job waits longer than the configured `max_pickup_time_seconds`.

## Mathematical Foundation

### Time-to-Breach Calculation

Calculate how much time remains before SLA breach:

```
Time to Breach = SLA Target - Oldest Job Age

If Time to Breach ≤ 0: Already breaching!
If Time to Breach ≤ Buffer: Imminent breach!
```

### Required Throughput

Calculate throughput needed to clear backlog within time:

```
Required Throughput = Pending Jobs / Time Remaining

Workers Needed = Required Throughput / Processing Rate
```

## Implementation

### Basic Backlog Drain

```php
public function calculateBacklogDrainWorkers(object $metrics, QueueConfiguration $config): int
{
    $pendingJobs = $metrics->depth->pending ?? 0;
    $oldestJobAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
    $slaTarget = $config->maxPickupTimeSeconds;
    $processingRate = $metrics->processingRate ?? 0.0;

    if ($pendingJobs === 0) {
        return $config->minWorkers;
    }

    // Calculate time remaining before SLA breach
    $timeRemaining = $slaTarget - $oldestJobAge;

    // If already breaching, scale to maximum immediately
    if ($timeRemaining <= 0) {
        return $config->maxWorkers;
    }

    // If processing rate unknown, scale conservatively
    if ($processingRate === 0) {
        return (int) ceil($config->maxWorkers * 0.8);
    }

    // Calculate workers needed to clear backlog in remaining time
    $requiredThroughput = $pendingJobs / $timeRemaining;  // jobs/second
    $workers = (int) ceil($requiredThroughput / $processingRate);

    // Apply limits
    return max($config->minWorkers, min($config->maxWorkers, $workers));
}
```

### With Safety Buffer

Add warning threshold before actual breach:

```php
public function calculateWithBuffer(object $metrics, QueueConfiguration $config): int
{
    $oldestJobAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
    $slaTarget = $config->maxPickupTimeSeconds;

    // Define warning threshold at 80% of SLA
    $warningThreshold = $slaTarget * 0.8;

    if ($oldestJobAge < $warningThreshold) {
        // Normal operation - use Little's Law
        return $this->littlesLawCalculation($metrics, $config);
    }

    // Approaching SLA limit - use backlog drain
    $baseWorkers = $this->calculateBacklogDrainWorkers($metrics, $config);

    // Add safety margin based on proximity to breach
    $slaUsage = $oldestJobAge / $slaTarget;
    $safetyMargin = 1.0 + (($slaUsage - 0.8) * 2);  // 1.0x at 80%, 1.4x at 90%, 1.8x at 95%

    $workers = (int) ceil($baseWorkers * $safetyMargin);

    return max($config->minWorkers, min($config->maxWorkers, $workers));
}
```

## Examples

### Example 1: Normal Operation

**Scenario:**
- Pending: 100 jobs
- Oldest age: 10 seconds
- SLA target: 60 seconds
- Processing rate: 5 jobs/sec per worker

**Calculation:**
```
Time remaining = 60 - 10 = 50 seconds
SLA usage = 10/60 = 16.7%

Status: Normal (< 80% threshold)
Action: Use Little's Law instead of backlog drain
```

### Example 2: Approaching SLA

**Scenario:**
- Pending: 200 jobs
- Oldest age: 48 seconds
- SLA target: 60 seconds
- Processing rate: 10 jobs/sec per worker

**Calculation:**
```
Time remaining = 60 - 48 = 12 seconds
SLA usage = 48/60 = 80%

Required throughput = 200 / 12 = 16.67 jobs/sec
Workers needed = 16.67 / 10 = 1.67 ≈ 2 workers

Safety margin = 1.0 + ((0.8 - 0.8) × 2) = 1.0x
Final workers = 2 × 1.0 = 2 workers
```

### Example 3: Imminent Breach

**Scenario:**
- Pending: 500 jobs
- Oldest age: 55 seconds
- SLA target: 60 seconds
- Processing rate: 8 jobs/sec per worker

**Calculation:**
```
Time remaining = 60 - 55 = 5 seconds
SLA usage = 55/60 = 91.7%

Required throughput = 500 / 5 = 100 jobs/sec
Workers needed = 100 / 8 = 12.5 ≈ 13 workers

Safety margin = 1.0 + ((0.917 - 0.8) × 2) = 1.23x
Final workers = 13 × 1.23 = 16 workers
```

### Example 4: Active Breach

**Scenario:**
- Pending: 300 jobs
- Oldest age: 65 seconds
- SLA target: 60 seconds
- Max workers: 20

**Calculation:**
```
Time remaining = 60 - 65 = -5 seconds

Status: BREACH!
Action: Scale to maximum immediately
Workers = 20 (max_workers)
```

## SLA Urgency Levels

Classify urgency and response:

```php
public function determineSlaUrgency(float $oldestAge, int $slaTarget): string
{
    $usage = $oldestAge / $slaTarget;

    if ($usage >= 1.0) {
        return 'BREACH';       // Already violating SLA
    } elseif ($usage >= 0.9) {
        return 'CRITICAL';     // <10% time remaining
    } elseif ($usage >= 0.8) {
        return 'WARNING';      // <20% time remaining
    } elseif ($usage >= 0.6) {
        return 'ELEVATED';     // <40% time remaining
    } else {
        return 'NORMAL';       // >40% time remaining
    }
}

public function getScalingResponse(string $urgency, object $metrics, QueueConfiguration $config): int
{
    return match($urgency) {
        'BREACH' => $config->maxWorkers,  // Maximum immediately
        'CRITICAL' => $this->aggressiveScale($metrics, $config),  // Very aggressive
        'WARNING' => $this->backlogDrain($metrics, $config),  // Backlog drain
        'ELEVATED' => $this->cautious Scale($metrics, $config),  // Slightly elevated
        'NORMAL' => $this->littlesLaw($metrics, $config),  // Standard calculation
    };
}
```

## Advanced Features

### Drain Rate Tracking

Monitor how fast the backlog is being cleared:

```php
public function calculateDrainRate(array $historicalDepth): float
{
    if (count($historicalDepth) < 2) {
        return 0.0;
    }

    $recent = array_slice($historicalDepth, -5);  // Last 5 samples
    $firstDepth = reset($recent);
    $lastDepth = end($recent);
    $timeSpan = count($recent) * $this->sampleInterval;  // seconds

    $drainRate = ($firstDepth - $lastDepth) / $timeSpan;  // jobs/second

    return max(0, $drainRate);  // Can't have negative drain
}
```

### Predicted Breach Time

Forecast when SLA breach will occur:

```php
public function predictBreachTime(object $metrics, QueueConfiguration $config): ?int
{
    $pendingJobs = $metrics->depth->pending ?? 0;
    $oldestAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
    $drainRate = $this->calculateDrainRate($this->historicalDepth);

    if ($drainRate <= 0 || $pendingJobs === 0) {
        return null;  // Can't predict
    }

    // Time until queue is empty at current drain rate
    $timeToEmpty = $pendingJobs / $drainRate;

    // Time until SLA breach
    $timeToBreach = $config->maxPickupTimeSeconds - $oldestAge;

    // If queue will empty before breach, no breach predicted
    if ($timeToEmpty < $timeToBreach) {
        return null;
    }

    // Breach predicted in X seconds
    return (int) ceil($timeToBreach);
}
```

### Cascading Breach Prevention

Prevent single breach from causing cascade:

```php
public function preventCascade(object $metrics, QueueConfiguration $config): int
{
    $breachTime = $this->predictBreachTime($metrics, $config);

    if ($breachTime === null || $breachTime > 120) {
        // No imminent breach, normal operation
        return $this->littlesLaw($metrics, $config);
    }

    // Breach predicted soon - scale aggressively
    $baseWorkers = $this->backlogDrain($metrics, $config);

    // Add extra capacity to prevent cascade
    $cascadeBuffer = 1.5;  // 50% extra capacity

    return (int) ceil($baseWorkers * $cascadeBuffer);
}
```

## Integration with Hybrid Strategy

```php
class HybridPredictiveStrategy
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        $oldestAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
        $slaTarget = $config->maxPickupTimeSeconds;
        $slaUsage = $oldestAge / $slaTarget;

        // Priority 1: SLA breach protection
        if ($slaUsage >= 0.8) {
            return $this->backlogDrain($metrics, $config);
        }

        // Priority 2: Trend-based proactive scaling
        if (($metrics->trend->direction ?? 'stable') === 'up') {
            return $this->trendBased($metrics, $config);
        }

        // Priority 3: Steady-state Little's Law
        return $this->littlesLaw($metrics, $config);
    }

    private function backlogDrain(object $metrics, QueueConfiguration $config): int
    {
        $pending = $metrics->depth->pending ?? 0;
        $oldestAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
        $slaTarget = $config->maxPickupTimeSeconds;
        $rate = $metrics->processingRate ?? 1.0;

        $timeRemaining = max(1, $slaTarget - $oldestAge);  // At least 1 second
        $requiredThroughput = $pending / $timeRemaining;
        $workers = (int) ceil($requiredThroughput / $rate);

        // Safety margin based on proximity to breach
        $slaUsage = $oldestAge / $slaTarget;
        $safetyMargin = 1.0 + max(0, ($slaUsage - 0.8) * 2);

        $workers = (int) ceil($workers * $safetyMargin);

        return max($config->minWorkers, min($config->maxWorkers, $workers));
    }
}
```

## Performance Characteristics

### Time Complexity
- **O(1)**: Constant time calculation
- Very fast, suitable for high-frequency evaluation

### Space Complexity
- **O(1)**: No historical data required
- Can enhance with history (O(n) for n samples)

### Accuracy
- **Very High** for SLA protection
- **May overprovision** slightly for safety
- **Excellent** at preventing breaches

## Best Practices

1. **Set appropriate thresholds**: Typically 80% SLA usage for activation
2. **Use safety margins**: Buffer for uncertainty and delays
3. **Monitor drain rate**: Track how fast backlog is clearing
4. **Combine with other algorithms**: Use as override, not primary
5. **Alert on activation**: Log when backlog drain is triggered
6. **Track breach rate**: Measure actual SLA compliance

## Common Patterns

### Pattern 1: Tiered Response

Different responses at different urgency levels:

```php
$slaUsage = $oldestAge / $slaTarget;

$workers = match(true) {
    $slaUsage >= 1.0 => $config->maxWorkers,  // Breach: max immediately
    $slaUsage >= 0.95 => (int) ceil($baseWorkers * 1.5),  // Critical: 50% buffer
    $slaUsage >= 0.9 => (int) ceil($baseWorkers * 1.3),  // Warning: 30% buffer
    $slaUsage >= 0.8 => (int) ceil($baseWorkers * 1.2),  // Elevated: 20% buffer
    default => $this->littlesLaw($metrics, $config),  // Normal: standard calc
};
```

### Pattern 2: Gradual Escalation

Increase urgency over time:

```php
private int $consecutiveHighUsage = 0;

public function escalate(float $slaUsage): float
{
    if ($slaUsage >= 0.8) {
        $this->consecutiveHighUsage++;
    } else {
        $this->consecutiveHighUsage = 0;
    }

    // Escalate safety margin with consecutive high usage
    $escalationFactor = min(2.0, 1.0 + ($this->consecutiveHighUsage * 0.1));

    return $escalationFactor;
}
```

### Pattern 3: Post-Breach Recovery

Aggressive scaling after breach to prevent recurrence:

```php
public function postBreachRecovery(object $metrics, QueueConfiguration $config): int
{
    if (!$this->wasRecentlyBreached()) {
        return $this->normalCalculation($metrics, $config);
    }

    // Stay at elevated capacity for recovery period
    $recoveryWorkers = (int) ceil($config->maxWorkers * 0.8);
    $normalWorkers = $this->normalCalculation($metrics, $config);

    return max($recoveryWorkers, $normalWorkers);
}
```

## Monitoring and Alerts

Track SLA performance:

```php
// Log SLA usage
logger()->info('SLA usage', [
    'queue' => $config->queue,
    'oldest_age' => $oldestAge,
    'sla_target' => $slaTarget,
    'sla_usage_percent' => ($oldestAge / $slaTarget) * 100,
    'urgency' => $this->determineSlaUrgency($oldestAge, $slaTarget),
]);

// Alert on high usage
if ($oldestAge / $slaTarget >= 0.9) {
    $this->alert->send([
        'severity' => 'critical',
        'message' => "SLA breach imminent for queue {$config->queue}",
        'details' => [
            'oldest_job_age' => $oldestAge,
            'sla_target' => $slaTarget,
            'time_remaining' => $slaTarget - $oldestAge,
        ],
    ]);
}
```

## See Also

- [Little's Law](littles-law.md) - Steady-state calculation
- [Trend Prediction](trend-prediction.md) - Predictive scaling
- [Resource Constraints](resource-constraints.md) - Resource management
