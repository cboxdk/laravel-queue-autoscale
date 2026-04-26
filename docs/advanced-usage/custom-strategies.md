---
title: "Custom Strategies"
description: "Implement custom scaling strategies in Queue Autoscale for Laravel"
weight: 30
---

# Custom Strategies

Guide to implementing custom scaling strategies in Queue Autoscale for Laravel.

## Table of Contents
- [Overview](#overview)
- [Strategy Contract](#strategy-contract)
- [Implementation Steps](#implementation-steps)
- [Strategy Examples](#strategy-examples)
- [Testing Strategies](#testing-strategies)
- [Best Practices](#best-practices)
- [Common Patterns](#common-patterns)

## Overview

Scaling strategies determine **how many workers** are needed based on queue metrics. The package provides a default `HybridStrategy`, but you can implement custom strategies for specific needs.

### When to Create Custom Strategies

Create a custom strategy when:
- You have unique scaling requirements
- Default algorithm doesn't match your traffic patterns
- You need domain-specific optimizations
- You want to integrate external data sources
- You need custom cost optimization logic

### Strategy Responsibilities

A scaling strategy must:
1. **Calculate target workers** based on metrics
2. **Provide reasoning** for scaling decisions
3. **Return predictions** about queue performance

## Strategy Contract

All strategies must implement `ScalingStrategyContract`:

```php
<?php

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

interface ScalingStrategyContract
{
    /**
     * Calculate target number of workers needed
     *
     * @param object $metrics Queue metrics object
     * @param QueueConfiguration $config Queue configuration
     * @return int Target worker count
     */
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int;

    /**
     * Get human-readable reason for last calculation
     *
     * @return string Explanation of scaling decision
     */
    public function getLastReason(): string;

    /**
     * Get predicted pickup time for next job
     *
     * @return float|null Predicted seconds until pickup (null if unknown)
     */
    public function getLastPrediction(): ?float;
}
```

### Metrics Object Structure

The `$metrics` object contains:

```php
object {
    // Current processing rate (jobs/second)
    float processingRate;

    // Number of active workers
    int activeWorkerCount;

    // Queue depth information
    object depth {
        int pending;              // Jobs waiting in queue
        float oldestJobAgeSeconds; // Age of oldest pending job
    };

    // Trend data (may be null for new queues)
    ?object trend {
        string direction;         // 'up', 'down', 'stable'
        ?float forecast;          // Predicted future processing rate
        ?float confidence;        // Confidence in forecast (0-1)
    };

    // Resource information
    ?object resources {
        float cpuPercent;         // Current CPU usage
        float memoryPercent;      // Current memory usage
        int availableMemoryMb;    // Available system memory
    };
}
```

## Implementation Steps

### Step 1: Create Strategy Class

Create a new class implementing the contract:

```php
<?php

namespace App\Autoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class SimpleRateBasedStrategy implements ScalingStrategyContract
{
    private string $lastReason = '';
    private ?float $lastPrediction = null;

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Your calculation logic here
        $targetWorkers = $this->calculate($metrics, $config);

        // Store reason and prediction
        $this->lastReason = $this->buildReason($metrics, $targetWorkers);
        $this->lastPrediction = $this->predictPickupTime($metrics, $targetWorkers);

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

    private function calculate(object $metrics, QueueConfiguration $config): int
    {
        // Implementation here
    }

    private function buildReason(object $metrics, int $workers): string
    {
        // Build explanation
    }

    private function predictPickupTime(object $metrics, int $workers): ?float
    {
        // Predict performance
    }
}
```

### Step 2: Register Strategy

Configure your strategy in `config/queue-autoscale.php`:

```php
'strategy' => \App\Autoscale\Strategies\SimpleRateBasedStrategy::class,
```

### Step 3: Test Strategy

Create tests to verify behavior:

```php
use App\Autoscale\Strategies\SimpleRateBasedStrategy;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

it('calculates workers based on processing rate', function () {
    $strategy = new SimpleRateBasedStrategy();

    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 5,
        'depth' => (object) ['pending' => 100, 'oldestJobAgeSeconds' => 5],
    ];

    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 60,
        minWorkers: 1,
        maxWorkers: 20,
    );

    $workers = $strategy->calculateTargetWorkers($metrics, $config);

    expect($workers)->toBeInt()
        ->and($workers)->toBeGreaterThanOrEqual(1)
        ->and($workers)->toBeLessThanOrEqual(20);
});
```

## Strategy Examples

### Example 1: Simple Rate-Based Strategy

Calculate workers based purely on processing rate:

```php
<?php

namespace App\Autoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class SimpleRateBasedStrategy implements ScalingStrategyContract
{
    private string $lastReason = '';
    private ?float $lastPrediction = null;

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        $processingRate = $metrics->processingRate ?? 0.0;
        $pendingJobs = $metrics->depth->pending ?? 0;

        if ($pendingJobs === 0) {
            $this->lastReason = 'No pending jobs, maintaining minimum workers';
            $this->lastPrediction = 0.0;
            return $config->minWorkers;
        }

        if ($processingRate === 0.0) {
            $this->lastReason = 'No processing rate data, starting with minimum workers';
            $this->lastPrediction = null;
            return $config->minWorkers;
        }

        // Calculate how long to drain queue at current rate
        $drainTimeSeconds = $pendingJobs / $processingRate;

        // Calculate workers needed to meet SLA
        $targetWorkers = (int) ceil($drainTimeSeconds / $config->maxPickupTimeSeconds);

        // Apply limits
        $targetWorkers = max($config->minWorkers, min($config->maxWorkers, $targetWorkers));

        // Build reason
        $this->lastReason = sprintf(
            'Rate-based: %.1f jobs/sec, %d pending, drain time %.1fs, target %d workers',
            $processingRate,
            $pendingJobs,
            $drainTimeSeconds,
            $targetWorkers
        );

        // Predict pickup time
        if ($targetWorkers > 0) {
            $this->lastPrediction = $drainTimeSeconds / $targetWorkers;
        }

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

### Example 2: Time-Based Strategy

Scale based on time of day:

```php
<?php

namespace App\Autoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class TimeBasedStrategy implements ScalingStrategyContract
{
    private string $lastReason = '';
    private ?float $lastPrediction = null;

    public function __construct(
        private readonly array $schedule = [
            // Hour => minimum workers
            0 => 1,   // Midnight: minimal
            6 => 2,   // Early morning: light
            9 => 10,  // Business hours start: high
            12 => 15, // Lunch peak: very high
            17 => 8,  // Evening: moderate
            22 => 2,  // Late night: light
        ]
    ) {}

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        $currentHour = now()->hour;
        $pendingJobs = $metrics->depth->pending ?? 0;

        // Get base worker count for current hour
        $baseWorkers = $this->getWorkersForHour($currentHour);

        // Scale up if there's a backlog
        if ($pendingJobs > 100) {
            $backlogMultiplier = min(3, $pendingJobs / 100);
            $targetWorkers = (int) ceil($baseWorkers * $backlogMultiplier);

            $this->lastReason = sprintf(
                'Time-based (hour %d) with backlog: %d base workers × %.1fx backlog = %d workers',
                $currentHour,
                $baseWorkers,
                $backlogMultiplier,
                $targetWorkers
            );
        } else {
            $targetWorkers = $baseWorkers;

            $this->lastReason = sprintf(
                'Time-based: hour %d, %d base workers, %d pending jobs',
                $currentHour,
                $baseWorkers,
                $pendingJobs
            );
        }

        // Apply configuration limits
        $targetWorkers = max($config->minWorkers, min($config->maxWorkers, $targetWorkers));

        // Predict pickup time (simplified)
        $processingRate = $metrics->processingRate ?? 1.0;
        if ($targetWorkers > 0 && $processingRate > 0) {
            $this->lastPrediction = $pendingJobs / ($processingRate * $targetWorkers);
        }

        return $targetWorkers;
    }

    private function getWorkersForHour(int $hour): int
    {
        // Find the schedule entry for current or previous hour
        $scheduleHours = array_keys($this->schedule);
        sort($scheduleHours);

        foreach (array_reverse($scheduleHours) as $scheduleHour) {
            if ($hour >= $scheduleHour) {
                return $this->schedule[$scheduleHour];
            }
        }

        // Default to first entry
        return $this->schedule[$scheduleHours[0]];
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

### Example 3: Cost-Optimized Strategy

Minimize workers while meeting SLA:

```php
<?php

namespace App\Autoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class CostOptimizedStrategy implements ScalingStrategyContract
{
    private string $lastReason = '';
    private ?float $lastPrediction = null;

    public function __construct(
        private readonly float $workerCostPerHour = 0.50,
        private readonly float $slaBreachCost = 100.00
    ) {}

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        $pendingJobs = $metrics->depth->pending ?? 0;
        $processingRate = $metrics->processingRate ?? 0.0;
        $oldestJobAge = $metrics->depth->oldestJobAgeSeconds ?? 0.0;

        if ($pendingJobs === 0) {
            $this->lastReason = 'Cost optimization: No pending jobs, scale to zero';
            $this->lastPrediction = 0.0;
            return max(0, $config->minWorkers);
        }

        // Calculate minimum workers to meet SLA
        $slaMargin = $config->maxPickupTimeSeconds - $oldestJobAge;

        if ($slaMargin <= 0) {
            // SLA breach imminent, scale aggressively
            $targetWorkers = $config->maxWorkers;
            $this->lastReason = sprintf(
                'Cost optimization: SLA breach imminent (oldest job: %.1fs, SLA: %ds), scale to max',
                $oldestJobAge,
                $config->maxPickupTimeSeconds
            );
        } elseif ($processingRate > 0) {
            // Calculate minimum workers to clear queue within SLA margin
            $jobsPerWorker = $processingRate;
            $requiredThroughput = $pendingJobs / $slaMargin;
            $targetWorkers = (int) ceil($requiredThroughput / $jobsPerWorker);

            // Cost-benefit analysis
            $workerCost = $targetWorkers * $this->workerCostPerHour;
            $slaRisk = $this->calculateSlaRisk($pendingJobs, $targetWorkers, $processingRate, $config);
            $expectedSlaBreachCost = $slaRisk * $this->slaBreachCost;

            // If SLA breach cost > worker cost, add buffer workers
            if ($expectedSlaBreachCost > $workerCost * 0.5) {
                $targetWorkers = (int) ceil($targetWorkers * 1.2);
            }

            $this->lastReason = sprintf(
                'Cost optimization: %d pending, SLA margin %.1fs, worker cost $%.2f, SLA risk %.1f%%, target %d workers',
                $pendingJobs,
                $slaMargin,
                $workerCost,
                $slaRisk * 100,
                $targetWorkers
            );
        } else {
            // No processing rate data, use minimum
            $targetWorkers = $config->minWorkers;
            $this->lastReason = 'Cost optimization: No processing rate data, using minimum workers';
        }

        // Apply limits
        $targetWorkers = max($config->minWorkers, min($config->maxWorkers, $targetWorkers));

        // Predict pickup time
        if ($targetWorkers > 0 && $processingRate > 0) {
            $this->lastPrediction = $pendingJobs / ($processingRate * $targetWorkers);
        }

        return $targetWorkers;
    }

    private function calculateSlaRisk(int $pending, int $workers, float $rate, QueueConfiguration $config): float
    {
        if ($workers === 0 || $rate === 0) {
            return 1.0; // 100% risk
        }

        $expectedPickupTime = $pending / ($rate * $workers);
        $slaBuffer = $config->maxPickupTimeSeconds - $expectedPickupTime;

        // Risk increases as buffer decreases
        if ($slaBuffer <= 0) {
            return 1.0;
        }

        // Exponential decay: more buffer = less risk
        return max(0, 1 - ($slaBuffer / $config->maxPickupTimeSeconds));
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

### Example 4: Machine Learning Strategy

Use ML predictions for scaling:

```php
<?php

namespace App\Autoscale\Strategies;

use App\Services\MachineLearning\LoadPredictor;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

class MachineLearningStrategy implements ScalingStrategyContract
{
    private string $lastReason = '';
    private ?float $lastPrediction = null;

    public function __construct(
        private readonly LoadPredictor $predictor
    ) {}

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Get ML prediction for next 5 minutes
        $prediction = $this->predictor->predictLoad([
            'current_pending' => $metrics->depth->pending ?? 0,
            'processing_rate' => $metrics->processingRate ?? 0.0,
            'hour_of_day' => now()->hour,
            'day_of_week' => now()->dayOfWeek,
            'active_workers' => $metrics->activeWorkerCount ?? 0,
        ]);

        $predictedPending = $prediction['pending_jobs_5min'];
        $confidence = $prediction['confidence'];

        // Calculate workers needed for predicted load
        if ($predictedPending === 0) {
            $targetWorkers = $config->minWorkers;
            $this->lastReason = sprintf(
                'ML prediction: no load expected (confidence: %.1f%%)',
                $confidence * 100
            );
        } else {
            $processingRate = $metrics->processingRate ?? 1.0;
            $requiredThroughput = $predictedPending / $config->maxPickupTimeSeconds;
            $targetWorkers = (int) ceil($requiredThroughput / $processingRate);

            // Adjust for confidence
            if ($confidence < 0.7) {
                // Low confidence, add safety margin
                $targetWorkers = (int) ceil($targetWorkers * 1.3);
            }

            $this->lastReason = sprintf(
                'ML prediction: %d jobs expected, %.1f%% confidence, %d workers needed',
                $predictedPending,
                $confidence * 100,
                $targetWorkers
            );
        }

        // Apply limits
        $targetWorkers = max($config->minWorkers, min($config->maxWorkers, $targetWorkers));

        // Store prediction
        $this->lastPrediction = $predictedPending > 0 && $targetWorkers > 0
            ? $predictedPending / ($processingRate * $targetWorkers)
            : 0.0;

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

## Testing Strategies

### Unit Tests

Test calculation logic in isolation:

```php
use App\Autoscale\Strategies\SimpleRateBasedStrategy;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

describe('SimpleRateBasedStrategy', function () {
    beforeEach(function () {
        $this->strategy = new SimpleRateBasedStrategy();
        $this->config = new QueueConfiguration(
            connection: 'redis',
            queue: 'default',
            maxPickupTimeSeconds: 60,
            minWorkers: 1,
            maxWorkers: 20,
        );
    });

    it('returns minimum workers when queue is empty', function () {
        $metrics = (object) [
            'processingRate' => 5.0,
            'activeWorkerCount' => 5,
            'depth' => (object) ['pending' => 0, 'oldestJobAgeSeconds' => 0],
        ];

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($workers)->toBe(1)
            ->and($this->strategy->getLastReason())->toContain('No pending jobs');
    });

    it('scales up for backlog', function () {
        $metrics = (object) [
            'processingRate' => 10.0,
            'activeWorkerCount' => 5,
            'depth' => (object) ['pending' => 1000, 'oldestJobAgeSeconds' => 30],
        ];

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($workers)->toBeGreaterThan(5)
            ->and($this->strategy->getLastReason())->toContain('Rate-based');
    });

    it('respects max worker limit', function () {
        $metrics = (object) [
            'processingRate' => 1.0,
            'activeWorkerCount' => 15,
            'depth' => (object) ['pending' => 10000, 'oldestJobAgeSeconds' => 50],
        ];

        $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($workers)->toBe(20);  // Capped at maxWorkers
    });

    it('provides prediction', function () {
        $metrics = (object) [
            'processingRate' => 10.0,
            'activeWorkerCount' => 5,
            'depth' => (object) ['pending' => 100, 'oldestJobAgeSeconds' => 5],
        ];

        $this->strategy->calculateTargetWorkers($metrics, $this->config);

        expect($this->strategy->getLastPrediction())->toBeFloat()
            ->and($this->strategy->getLastPrediction())->toBeGreaterThan(0.0);
    });
});
```

### Integration Tests

Test with real scaling engine:

```php
use App\Autoscale\Strategies\SimpleRateBasedStrategy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;

it('integrates with scaling engine', function () {
    $strategy = new SimpleRateBasedStrategy();
    $capacity = app(\Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator::class);
    $engine = new ScalingEngine($strategy, $capacity);

    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 5,
        'depth' => (object) ['pending' => 100, 'oldestJobAgeSeconds' => 5],
        'trend' => (object) ['direction' => 'stable'],
    ];

    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 60,
        minWorkers: 1,
        maxWorkers: 20,
    );

    $decision = $engine->evaluate($metrics, $config, 5);

    expect($decision->targetWorkers)->toBeInt()
        ->and($decision->reason)->toContain('Rate-based')
        ->and($decision->predictedPickupTime)->toBeFloat();
});
```

## Best Practices

### 1. Always Validate Inputs

```php
public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
{
    // Validate metrics
    $processingRate = max(0.0, $metrics->processingRate ?? 0.0);
    $pendingJobs = max(0, $metrics->depth->pending ?? 0);

    // Validate configuration
    if ($config->sla->targetSeconds <= 0) {
        throw new \InvalidArgumentException('Invalid sla.target_seconds');
    }

    // Your logic here
}
```

### 2. Provide Detailed Reasons

```php
$this->lastReason = sprintf(
    'Strategy decision: %d pending jobs, %.1f jobs/sec rate, %d current workers → %d target workers (reason: %s)',
    $pendingJobs,
    $processingRate,
    $currentWorkers,
    $targetWorkers,
    $specificReason
);
```

### 3. Handle Edge Cases

```php
// No pending jobs
if ($pendingJobs === 0) {
    return $config->minWorkers;
}

// No processing rate data (new queue)
if ($processingRate === 0.0) {
    return $config->minWorkers;
}

// Infinite or NaN values
if (!is_finite($calculatedWorkers)) {
    return $config->minWorkers;
}
```

### 4. Apply Configuration Limits

```php
$targetWorkers = max(
    $config->minWorkers,
    min($config->maxWorkers, $calculatedWorkers)
);
```

### 5. Make Strategies Testable

```php
// Extract calculation to testable method
private function calculateBasedOnRate(float $rate, int $pending, int $sla): int
{
    return (int) ceil(($pending / $rate) / $sla);
}

// Inject dependencies for testing
public function __construct(
    private readonly ?TimeProvider $timeProvider = null
) {
    $this->timeProvider = $timeProvider ?? new SystemTimeProvider();
}
```

## Common Patterns

### Pattern: Hybrid Calculation

Combine multiple approaches:

```php
$steadyStateWorkers = $this->calculateSteadyState($metrics, $config);
$trendBasedWorkers = $this->calculateFromTrend($metrics, $config);
$backlogWorkers = $this->calculateFromBacklog($metrics, $config);

// Take the maximum (most conservative)
$targetWorkers = max($steadyStateWorkers, $trendBasedWorkers, $backlogWorkers);
```

### Pattern: Confidence-Based Adjustment

Add safety margins based on confidence:

```php
$baseWorkers = $this->calculateBase($metrics, $config);
$confidence = $metrics->trend->confidence ?? 0.5;

if ($confidence < 0.7) {
    // Low confidence, add 30% safety margin
    $targetWorkers = (int) ceil($baseWorkers * 1.3);
} else {
    $targetWorkers = $baseWorkers;
}
```

### Pattern: Gradual Changes

Prevent oscillation with gradual scaling:

```php
$targetWorkers = $this->calculateTarget($metrics, $config);
$currentWorkers = $metrics->activeWorkerCount ?? 0;

$maxChange = max(1, (int) ceil($currentWorkers * 0.2));  // Max 20% change

if ($targetWorkers > $currentWorkers) {
    $targetWorkers = min($targetWorkers, $currentWorkers + $maxChange);
} elseif ($targetWorkers < $currentWorkers) {
    $targetWorkers = max($targetWorkers, $currentWorkers - $maxChange);
}
```

## See Also

- [Scaling Policies](scaling-policies.md) - Implementing scaling policies
- [How It Works](../basic-usage/how-it-works.md) - Understanding the default strategy
- [Configuration](../basic-usage/configuration.md) - Configuring strategies
- [API Reference](../api-reference/_index.md) - Complete API documentation
