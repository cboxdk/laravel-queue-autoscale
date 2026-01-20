---
title: "Resource Constraints"
description: "System resource management and constraint enforcement for safe autoscaling"
weight: 54
---

# Resource Constraints

System resource management and constraint enforcement for safe autoscaling.

## Overview

Resource constraints ensure autoscaling doesn't:
- Exhaust system memory
- Overload CPU
- Exceed budget limits
- Violate infrastructure capacity

**Goal:** Scale efficiently while respecting physical and economic limits.

## Constraint Types

### 1. System Resource Constraints

Prevent resource exhaustion:

```php
'resource_limits' => [
    'max_total_workers' => 100,          // Global worker limit
    'max_memory_percent' => 80,          // Max 80% memory usage
    'max_cpu_percent' => 90,             // Max 90% CPU usage
    'reserved_memory_mb' => 1024,        // Reserve 1GB for system
    'max_workers_per_queue' => 50,       // Per-queue limit
],
```

### 2. Configuration Constraints

User-defined limits:

```php
'queues' => [
    [
        'min_workers' => 1,              // Never scale below
        'max_workers' => 20,             // Never scale above
        'max_worker_memory' => 512,      // MB per worker
    ],
],
```

### 3. Economic Constraints

Cost-based limits:

```php
'cost_limits' => [
    'max_hourly_cost' => 100.00,        // Budget cap
    'worker_cost_per_hour' => 0.50,     // Cost per worker
    'alert_threshold' => 80.00,         // Alert at 80%
],
```

## Implementation

### System Resource Checker

```php
class ResourceConstraintChecker
{
    public function canAddWorkers(int $additionalWorkers, QueueConfiguration $config): bool
    {
        // Check 1: Total worker limit
        if (!$this->checkTotalWorkerLimit($additionalWorkers)) {
            return false;
        }

        // Check 2: Memory availability
        if (!$this->checkMemoryAvailability($additionalWorkers, $config)) {
            return false;
        }

        // Check 3: CPU capacity
        if (!$this->checkCpuCapacity($additionalWorkers)) {
            return false;
        }

        // Check 4: Budget limit
        if (!$this->checkBudgetLimit($additionalWorkers)) {
            return false;
        }

        return true;
    }

    private function checkTotalWorkerLimit(int $additional): bool
    {
        $current = $this->getCurrentTotalWorkers();
        $limit = config('queue-autoscale.resource_limits.max_total_workers');

        return ($current + $additional) <= $limit;
    }

    private function checkMemoryAvailability(int $additional, QueueConfiguration $config): bool
    {
        $systemMemoryMb = $this->getSystemMemoryMb();
        $usedMemoryMb = $this->getUsedMemoryMb();
        $reservedMemoryMb = config('queue-autoscale.resource_limits.reserved_memory_mb');
        $workerMemoryMb = $config->workerMemory ?? 256;

        $availableMemoryMb = $systemMemoryMb - $usedMemoryMb - $reservedMemoryMb;
        $requiredMemoryMb = $additional * $workerMemoryMb;

        return $requiredMemoryMb <= $availableMemoryMb;
    }

    private function checkCpuCapacity(int $additional): bool
    {
        $currentCpuPercent = $this->getCurrentCpuPercent();
        $maxCpuPercent = config('queue-autoscale.resource_limits.max_cpu_percent');
        $cpuPerWorker = $this->estimateCpuPerWorker();

        $projectedCpu = $currentCpuPercent + ($additional * $cpuPerWorker);

        return $projectedCpu <= $maxCpuPercent;
    }

    private function checkBudgetLimit(int $additional): bool
    {
        $currentCost = $this->getCurrentHourlyCost();
        $maxCost = config('queue-autoscale.cost_limits.max_hourly_cost');
        $workerCost = config('queue-autoscale.cost_limits.worker_cost_per_hour');

        $projectedCost = $currentCost + ($additional * $workerCost);

        return $projectedCost <= $maxCost;
    }
}
```

### Constraint Enforcement Policy

```php
class ResourceConstraintPolicy implements ScalingPolicyContract
{
    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void
    {
        // Check current resource usage
        $memoryPercent = $this->getMemoryUsagePercent();
        $cpuPercent = $this->getCpuUsagePercent();

        if ($memoryPercent > 90 || $cpuPercent > 95) {
            logger()->warning('System resources critically high', [
                'memory_percent' => $memoryPercent,
                'cpu_percent' => $cpuPercent,
                'queue' => $config->queue,
            ]);
        }
    }

    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        $workerChange = $decision->targetWorkers - $currentWorkers;

        if ($workerChange <= 0) {
            return;  // Scaling down, no constraint check needed
        }

        // Enforce constraints on scale-up
        $checker = new ResourceConstraintChecker();

        if (!$checker->canAddWorkers($workerChange, $config)) {
            // Reduce target to maximum allowed
            $maxAllowed = $this->calculateMaxAllowedWorkers($currentWorkers, $config);

            // Modify decision (using reflection for readonly properties)
            $this->modifyDecision($decision, $maxAllowed);

            logger()->warning('Scaling limited by resource constraints', [
                'requested_workers' => $decision->targetWorkers,
                'allowed_workers' => $maxAllowed,
                'queue' => $config->queue,
            ]);
        }
    }

    private function calculateMaxAllowedWorkers(int $current, QueueConfiguration $config): int
    {
        $limits = [
            $this->maxByTotalLimit($current),
            $this->maxByMemoryLimit($current, $config),
            $this->maxByCpuLimit($current),
            $this->maxByBudgetLimit($current),
            $config->maxWorkers,  // Configuration limit
        ];

        return min($limits);
    }
}
```

## Examples

### Example 1: Memory Constraint

**Scenario:**
- System memory: 16 GB
- Used memory: 12 GB
- Reserved memory: 2 GB
- Available: 2 GB
- Worker memory: 512 MB
- Target workers: 10

**Calculation:**
```php
$availableMb = 16384 - 12288 - 2048 = 2048 MB
$requiredMb = 10 workers × 512 MB = 5120 MB

2048 < 5120  // NOT ENOUGH MEMORY

$maxWorkers = floor(2048 / 512) = 4 workers
```

Scale to 4 workers instead of 10.

### Example 2: CPU Constraint

**Scenario:**
- Current CPU: 70%
- Max CPU: 90%
- CPU per worker: 5%
- Target workers: 8 additional

**Calculation:**
```php
$projectedCpu = 70% + (8 × 5%) = 110%

110% > 90%  // EXCEEDS LIMIT

$availableCpu = 90% - 70% = 20%
$maxAdditionalWorkers = floor(20% / 5%) = 4 workers
```

Add 4 workers instead of 8.

### Example 3: Budget Constraint

**Scenario:**
- Hourly budget: $100
- Current cost: $80
- Cost per worker: $0.50
- Target: 50 additional workers

**Calculation:**
```php
$projectedCost = $80 + (50 × $0.50) = $105

$105 > $100  // EXCEEDS BUDGET

$availableBudget = $100 - $80 = $20
$maxWorkers = floor($20 / $0.50) = 40 workers
```

Add 40 workers instead of 50.

### Example 4: Multiple Constraints

**Scenario:**
- Memory allows: 10 workers
- CPU allows: 8 workers
- Budget allows: 12 workers
- Config max: 20 workers

**Calculation:**
```php
$maxWorkers = min(10, 8, 12, 20) = 8 workers
```

Most restrictive constraint wins (CPU).

## Advanced Constraint Handling

### Dynamic Reserve Calculation

Adjust reserved resources based on load:

```php
public function calculateDynamicReserve(): int
{
    $baseReserve = 1024;  // 1 GB base
    $currentLoad = $this->getCurrentSystemLoad();

    // Increase reserve during high load
    if ($currentLoad > 0.8) {
        return (int) ($baseReserve * 1.5);  // 1.5 GB
    }

    return $baseReserve;
}
```

### Predictive Resource Planning

Forecast resource needs:

```php
public function predictResourceNeeds(object $metrics, int $targetWorkers, QueueConfiguration $config): array
{
    $workerMemory = $config->workerMemory ?? 256;
    $estimatedCpuPerWorker = 5;  // percentage

    return [
        'memory_mb' => $targetWorkers * $workerMemory,
        'cpu_percent' => $targetWorkers * $estimatedCpuPerWorker,
        'cost' => $targetWorkers * $this->workerCostPerHour,
    ];
}
```

### Constraint Violation Handling

Handle resource exhaustion gracefully:

```php
public function handleConstraintViolation(string $constraint, int $requested, int $allowed): void
{
    logger()->error('Resource constraint violated', [
        'constraint' => $constraint,
        'requested_workers' => $requested,
        'allowed_workers' => $allowed,
        'system_memory_mb' => $this->getSystemMemoryMb(),
        'used_memory_mb' => $this->getUsedMemoryMb(),
        'cpu_percent' => $this->getCurrentCpuPercent(),
    ]);

    // Alert operations team
    $this->alert->send([
        'severity' => 'warning',
        'title' => "Autoscaling limited by {$constraint}",
        'message' => "Requested {$requested} workers, limited to {$allowed}",
    ]);

    // Optionally trigger infrastructure scaling
    if ($constraint === 'memory' || $constraint === 'cpu') {
        event(new InfrastructureScalingNeeded($constraint, $requested));
    }
}
```

## Constraint Priorities

When multiple constraints conflict:

```php
public function applyConstraintPriorities(ScalingDecision $decision, QueueConfiguration $config): int
{
    $limits = [
        // Priority 1: Safety limits (prevent system crash)
        'memory' => $this->maxByMemoryLimit($decision->targetWorkers, $config),
        'cpu' => $this->maxByCpuLimit($decision->targetWorkers),

        // Priority 2: Hard configuration limits
        'config_max' => $config->maxWorkers,
        'total_workers' => $this->maxByTotalWorkerLimit(),

        // Priority 3: Soft economic limits
        'budget' => $this->maxByBudgetLimit($decision->targetWorkers),
    ];

    // Safety limits are mandatory
    $safeLimits = min($limits['memory'], $limits['cpu']);

    // Configuration limits
    $configLimits = min($limits['config_max'], $limits['total_workers']);

    // Take most restrictive of safety and config
    $hardLimit = min($safeLimits, $configLimits);

    // Budget is advisory - can exceed with warning
    if ($limits['budget'] < $hardLimit) {
        logger()->warning('Exceeding budget limit for SLA compliance', [
            'budget_allows' => $limits['budget'],
            'scaling_to' => $hardLimit,
        ]);
    }

    return $hardLimit;
}
```

## Performance Characteristics

### Time Complexity
- **O(1)**: Constant time constraint checks
- Very fast, negligible overhead

### Space Complexity
- **O(1)**: No additional storage required
- May cache system metrics briefly

### Overhead
- **Minimal**: <1ms for all constraint checks
- Can be performed on every evaluation

## Best Practices

1. **Set appropriate reserves**: 10-20% system resources reserved
2. **Monitor constraint hits**: Track which constraints are activated
3. **Alert on violations**: Notify when scaling is limited
4. **Plan capacity**: Provision infrastructure ahead of known peaks
5. **Use soft limits**: Warning thresholds before hard limits
6. **Test constraints**: Validate limits work as expected

## Integration with Hybrid Strategy

```php
class HybridPredictiveStrategy
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Calculate ideal workers (unconstrained)
        $idealWorkers = $this->calculateIdeal($metrics, $config);

        // Apply resource constraints
        $constrainedWorkers = $this->applyConstraints($idealWorkers, $config);

        // Log if constrained
        if ($constrainedWorkers < $idealWorkers) {
            logger()->info('Scaling limited by constraints', [
                'ideal_workers' => $idealWorkers,
                'constrained_workers' => $constrainedWorkers,
                'queue' => $config->queue,
            ]);
        }

        return $constrainedWorkers;
    }

    private function applyConstraints(int $ideal, QueueConfiguration $config): int
    {
        $checker = app(ResourceConstraintChecker::class);
        $current = $this->getCurrentWorkers($config);

        $additional = $ideal - $current;

        if ($additional <= 0) {
            return $ideal;  // Scaling down, no constraints
        }

        if (!$checker->canAddWorkers($additional, $config)) {
            return $checker->calculateMaxAllowed($current, $config);
        }

        return $ideal;
    }
}
```

## Monitoring Constraint Impact

Track how often constraints limit scaling:

```php
DB::table('constraint_events')->insert([
    'queue' => $config->queue,
    'constraint_type' => 'memory',
    'requested_workers' => $requested,
    'allowed_workers' => $allowed,
    'memory_available_mb' => $availableMemory,
    'cpu_percent' => $currentCpu,
    'timestamp' => now(),
]);

// Query for analysis
$constraintFrequency = DB::table('constraint_events')
    ->where('timestamp', '>=', now()->subHours(24))
    ->groupBy('constraint_type')
    ->selectRaw('constraint_type, COUNT(*) as occurrences')
    ->get();
```

## See Also

- [Little's Law](littles-law.md) - Base calculation
- [Trend Prediction](trend-prediction.md) - Predictive scaling
- [Backlog Drain](backlog-drain.md) - SLA protection
- [Performance Tuning](../basic-usage/performance.md) - Performance tuning
