---
title: "Trend Prediction"
description: "Predictive autoscaling using trend analysis and forecasting"
weight: 52
---

# Trend Prediction

Predictive autoscaling using trend analysis and forecasting.

## Overview

Trend prediction enables **proactive scaling** by:
- Analyzing historical metrics
- Detecting traffic patterns
- Forecasting future load
- Scaling ahead of demand

**Goal:** Start scaling **before** load increases, not after.

## Mathematical Foundation

### Simple Linear Regression

Predict future values using historical trend:

```
y = mx + b

Where:
y = predicted value
m = slope (rate of change)
x = time
b = y-intercept
```

For queue autoscaling:

```
Future Load = Current Load + (Trend × Time Horizon)
```

### Implementation

```php
public function predictFutureLoad(array $historicalDepth, int $secondsAhead): float
{
    $n = count($historicalDepth);

    if ($n < 3) {
        return end($historicalDepth);  // Not enough data
    }

    // Calculate slope using linear regression
    $sumX = 0;
    $sumY = 0;
    $sumXY = 0;
    $sumX2 = 0;

    foreach ($historicalDepth as $i => $depth) {
        $x = $i;  // time index
        $y = $depth;  // queue depth

        $sumX += $x;
        $sumY += $y;
        $sumXY += $x * $y;
        $sumX2 += $x * $x;
    }

    $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
    $intercept = ($sumY - $slope * $sumX) / $n;

    // Predict future
    $futureTime = $n + ($secondsAhead / $this->sampleInterval);
    $prediction = $slope * $futureTime + $intercept;

    return max(0, $prediction);  // Can't have negative queue depth
}
```

## Trend Detection

### Direction Classification

Classify trend direction:

```php
public function detectTrendDirection(array $recentMetrics): string
{
    if (count($recentMetrics) < 3) {
        return 'stable';
    }

    $depths = array_column($recentMetrics, 'pending');
    $slope = $this->calculateSlope($depths);

    // Define thresholds
    $upwardThreshold = 5.0;    // jobs/sample increasing
    $downwardThreshold = -5.0;  // jobs/sample decreasing

    if ($slope > $upwardThreshold) {
        return 'up';
    } elseif ($slope < $downwardThreshold) {
        return 'down';
    } else {
        return 'stable';
    }
}
```

### Trend Strength

Measure how strong the trend is:

```php
public function calculateTrendStrength(array $values): float
{
    // Calculate R² (coefficient of determination)
    $n = count($values);
    $meanY = array_sum($values) / $n;

    $ssTotal = 0;  // Total sum of squares
    $ssResidual = 0;  // Residual sum of squares

    $predictions = $this->getPredictions($values);

    foreach ($values as $i => $actual) {
        $ssTotal += pow($actual - $meanY, 2);
        $ssResidual += pow($actual - $predictions[$i], 2);
    }

    $rSquared = 1 - ($ssResidual / $ssTotal);

    return max(0, min(1, $rSquared));  // 0-1 confidence
}
```

## Forecasting Strategies

### Strategy 1: Linear Extrapolation

Simple projection of current trend:

```php
private function linearForecast(object $metrics, QueueConfiguration $config): int
{
    $currentDepth = $metrics->depth->pending ?? 0;
    $trendDirection = $metrics->trend->direction ?? 'stable';
    $trendForecast = $metrics->trend->forecast ?? null;

    if ($trendDirection === 'stable' || $trendForecast === null) {
        return $this->littlesLawCalculation($metrics, $config);
    }

    // Predict depth in next evaluation cycle
    $forecastHorizon = $config->evaluationIntervalSeconds ?? 30;
    $predictedDepth = $trendForecast;

    // Calculate workers needed for predicted load
    $processingRate = $metrics->processingRate ?? 1.0;
    $workers = (int) ceil(($predictedDepth / $config->maxPickupTimeSeconds) / $processingRate);

    return max($config->minWorkers, min($config->maxWorkers, $workers));
}
```

### Strategy 2: Weighted Forecast

Blend current state with prediction:

```php
private function weightedForecast(object $metrics, QueueConfiguration $config): int
{
    $currentWorkers = $this->calculateCurrent($metrics, $config);
    $forecastWorkers = $this->calculateForecast($metrics, $config);
    $confidence = $metrics->trend->confidence ?? 0.5;

    // Weight by confidence
    $blendedWorkers = ($confidence * $forecastWorkers) + ((1 - $confidence) * $currentWorkers);

    return (int) ceil($blendedWorkers);
}
```

### Strategy 3: Exponential Smoothing

Smooth forecasts to reduce noise:

```php
private function exponentialSmoothing(array $historical, float $alpha = 0.3): float
{
    $smoothed = $historical[0];

    foreach (array_slice($historical, 1) as $value) {
        $smoothed = $alpha * $value + (1 - $alpha) * $smoothed;
    }

    return $smoothed;
}
```

## Examples

### Example 1: Growing Load

**Scenario:**
- Historical depth: [50, 75, 100, 125, 150]
- Trend: Increasing by ~25 jobs/sample
- Current: 150 jobs
- Forecast 30s ahead: 175 jobs

**Calculation:**
```php
// Without trend prediction
$currentWorkers = 150 / (10 jobs/sec × 60 sec) = 0.25 ≈ 1 worker

// With trend prediction
$forecastWorkers = 175 / (10 jobs/sec × 60 sec) = 0.29 ≈ 1 worker

// But trend is strong, add safety margin
$trendWorkers = ceil(1 * 1.3) = 2 workers
```

Scale to 2 workers **before** the load actually increases.

### Example 2: Spike Detection

**Scenario:**
- Historical: [100, 100, 100, 150, 250]
- Rapid increase detected
- Predict continued growth

**Response:**
```php
$recentGrowth = [150, 250];  // Last 2 samples
$growthRate = 100 jobs/sample;

$predictedNext = 250 + 100 = 350 jobs;
$aggressiveScaling = true;  // Spike detected

$workers = ceil(350 / (processingRate × sla)) * 1.5;  // 50% buffer
```

### Example 3: Declining Load

**Scenario:**
- Historical: [200, 180, 160, 140, 120]
- Decreasing by ~20 jobs/sample
- Predict: 100 jobs

**Response:**
```php
$currentWorkers = 5;
$predictedWorkers = 100 / (rate × sla) = 2;

// Gradual scale-down to avoid oscillation
$scaleDownStep = max(1, ceil($currentWorkers * 0.2));  // 20% at a time
$targetWorkers = max($predictedWorkers, $currentWorkers - $scaleDownStep);
```

## Confidence Calculation

Measure prediction reliability:

```php
public function calculateConfidence(array $historical, float $prediction): float
{
    // Factor 1: Trend strength (R²)
    $trendStrength = $this->calculateTrendStrength($historical);

    // Factor 2: Data recency
    $dataRecency = min(1.0, count($historical) / 10);  // Max confidence at 10 samples

    // Factor 3: Prediction variance
    $variance = $this->calculateVariance($historical);
    $predictionStability = 1 / (1 + $variance);

    // Weighted combination
    $confidence = (
        $trendStrength * 0.5 +
        $dataRecency * 0.3 +
        $predictionStability * 0.2
    );

    return max(0, min(1, $confidence));
}
```

## Integration with Hybrid Strategy

```php
class HybridStrategy
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        $steadyState = $this->littlesLaw($metrics, $config);

        // Check if trend is significant
        $trend = $metrics->trend ?? null;
        if (!$trend || $trend->direction === 'stable') {
            return $steadyState;
        }

        // Calculate trend-based workers
        $trendWorkers = $this->trendBased($metrics, $config);
        $confidence = $trend->confidence ?? 0.5;

        // Use trend if confidence is high
        if ($confidence > 0.7 && $trendWorkers > $steadyState) {
            return $trendWorkers;  // Proactive scaling
        }

        return $steadyState;
    }

    private function trendBased(object $metrics, QueueConfiguration $config): int
    {
        $forecast = $metrics->trend->forecast ?? $metrics->depth->pending;
        $processingRate = $metrics->processingRate ?? 1.0;
        $sla = $config->maxPickupTimeSeconds;

        $workers = (int) ceil(($forecast / $sla) / $processingRate);

        // Add buffer for upward trends
        if ($metrics->trend->direction === 'up') {
            $workers = (int) ceil($workers * 1.2);  // 20% safety margin
        }

        return $workers;
    }
}
```

## Performance Characteristics

### Time Complexity
- **O(n)**: Linear in number of historical samples
- Typically n = 5-10 samples, very fast

### Space Complexity
- **O(n)**: Store historical metrics
- Can limit to recent window for bounded memory

### Accuracy
- **High** for consistent trends
- **Medium** for variable trends
- **Low** for random/chaotic traffic

## Best Practices

1. **Require minimum samples**: At least 3-5 data points
2. **Use confidence thresholds**: Only act on high-confidence predictions
3. **Add safety margins**: Buffer for prediction uncertainty
4. **Smooth data**: Reduce noise with moving averages
5. **Limit forecast horizon**: Don't predict too far ahead
6. **Validate predictions**: Track forecast accuracy over time

## Common Pitfalls

### Overfitting

Don't overreact to short-term noise:

```php
// ❌ Bad: Too sensitive
if ($slope > 0) {
    $workers = $predictedWorkers * 2;
}

// ✅ Good: Require significance
if ($slope > $threshold && $confidence > 0.7) {
    $workers = $predictedWorkers * 1.2;
}
```

### Ignoring Seasonality

Account for recurring patterns:

```php
// Check for daily/weekly patterns
$hourOfDay = now()->hour;
$historicalAtThisHour = $this->getHistoricalLoadAtHour($hourOfDay);

$seasonalAdjustment = $historicalAtThisHour / $currentLoad;
$adjustedPrediction = $rawPrediction * $seasonalAdjustment;
```

### Prediction Drift

Validate and recalibrate:

```php
// Compare prediction to actual
$error = abs($predicted - $actual) / $actual;

if ($error > 0.3) {  // 30% error
    $this->recalibrateModel();
}
```

## See Also

- [Little's Law](littles-law.md) - Steady-state calculation
- [Backlog Drain](backlog-drain.md) - SLA protection
- [Resource Constraints](resource-constraints.md) - Constraint handling
