---
title: "Algorithms"
description: "Mathematical foundations and algorithmic details of Laravel Queue Autoscale"
weight: 50
---

# Algorithms

This section provides deep dives into the mathematical foundations and algorithmic details behind Laravel Queue Autoscale.

## Overview

Laravel Queue Autoscale uses a **hybrid predictive algorithm** that combines three complementary approaches:

1. **[Little's Law](littles-law.md)** - Steady-state calculation for current workload
2. **[Trend Prediction](trend-prediction.md)** - Proactive scaling based on traffic forecasts
3. **[Backlog Drain](backlog-drain.md)** - Aggressive scaling to prevent SLA breaches

The autoscaler takes the **maximum** of these three calculations to ensure SLA compliance while being responsive to changing conditions.

## Core Algorithms

### Little's Law
Mathematical foundation using queueing theory to calculate baseline worker requirements based on arrival rate and processing time.

**Best for**: Steady-state workloads with predictable patterns.

### Trend Prediction
Forecasting algorithm that predicts future traffic based on historical patterns and current trends.

**Best for**: Proactive scaling ahead of demand increases.

### Backlog Drain
SLA-focused algorithm that aggressively scales when jobs approach their pickup time targets.

**Best for**: Preventing SLA breaches during traffic spikes.

## Supporting Systems

### Architecture
Complete system architecture showing how components interact:

- **[Architecture](architecture.md)** - System design and component interaction

### Resource Management
Ensuring autoscaling respects system limits:

- **[Resource Constraints](resource-constraints.md)** - CPU and memory management

## Mathematical Background

These algorithms are based on established queueing theory and operations research:

- **Little's Law**: L = λW (proven theorem from queueing theory)
- **Trend Analysis**: Linear regression and exponential smoothing
- **Constraint Optimization**: Multi-objective optimization with hard constraints

## Algorithm Selection Logic

The hybrid algorithm evaluates all three approaches and selects the maximum:

```
target_workers = max(
    little_law_workers,
    trend_predicted_workers,
    backlog_drain_workers
)
```

This ensures:
- ✅ Current workload is handled (Little's Law)
- ✅ Future demand is anticipated (Trend Prediction)
- ✅ SLA breaches are prevented (Backlog Drain)

## When Each Algorithm Dominates

**Little's Law dominates when**:
- Traffic is stable
- No significant trends detected
- Backlog is manageable

**Trend Prediction dominates when**:
- Traffic is increasing
- Strong upward trend detected
- Proactive scaling needed

**Backlog Drain dominates when**:
- Jobs are aging
- Approaching SLA target
- Immediate action required

## Further Reading

For implementation details, see:

- [How It Works](../basic-usage/how-it-works.md) - Practical application of algorithms
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Implementing your own algorithms
- [Architecture](architecture.md) - System design and decision flow
