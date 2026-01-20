---
title: "Introduction"
description: "Intelligent, predictive autoscaling for Laravel queues with SLA/SLO-based optimization"
weight: 1
---

# Introduction

Laravel Queue Autoscale is a smart queue worker manager that automatically scales your queue workers based on workload, predicted demand, and service level objectives. Unlike traditional reactive solutions, it uses a **hybrid predictive algorithm** combining queueing theory (Little's Law), trend analysis, and backlog-based scaling to maintain your SLA targets.

## What is Laravel Queue Autoscale?

Instead of manually managing worker counts or using simple threshold-based scaling, Laravel Queue Autoscale lets you define **service level objectives** (SLOs) and automatically maintains the optimal number of workers to meet those targets.

**Traditional approach:**
```php
// Manual: "I want 10 workers"
supervisord config: numprocs=10
```

**Laravel Queue Autoscale approach:**
```php
// SLA-based: "Jobs should start processing within 30 seconds"
'max_pickup_time_seconds' => 30
```

The package calculates and maintains the worker count needed to meet your SLA target automatically.

## Key Features

### 🎯 SLA/SLO-Based Scaling
Define max pickup time instead of arbitrary worker counts. The autoscaler maintains enough workers to meet your service level targets.

### 📈 Predictive Algorithm
Proactive scaling using trend analysis and forecasting. Scale up **before** demand increases, not after jobs start piling up.

### 🔬 Queueing Theory Foundation
Built on Little's Law (L = λW) for mathematically sound steady-state calculations.

### ⚡ SLA Breach Prevention
Aggressive backlog drain algorithm activates when approaching SLA violations, preventing breaches before they occur.

### 🖥️ Resource-Aware
Respects CPU and memory limits from `cboxdk/system-metrics` package. Won't spawn more workers than your system can handle.

### 🔄 Metrics-Driven
Uses `cboxdk/laravel-queue-metrics` for automatic queue discovery and comprehensive metrics collection.

### 🎛️ Extensible
Custom scaling strategies and policies via clean interfaces. Implement your own business logic easily.

### 📊 Event Broadcasting
React to scaling decisions, SLA predictions, and worker changes through Laravel events.

### 🛡️ Graceful Shutdown
Proper worker termination with SIGTERM → SIGKILL sequence. Workers finish current jobs before terminating.

### 💎 Developer Experience
Clean API following Spatie package conventions. Easy to configure, understand, and extend.

## How It Works

The autoscaler uses a three-phase hybrid algorithm:

### 1. Little's Law (Steady State)
Calculates baseline worker count for current workload:
```
Workers = Arrival_Rate × Average_Job_Time
```

### 2. Trend Prediction (Proactive)
Forecasts future demand and scales ahead:
```
Workers = Forecasted_Rate × Average_Job_Time
```

### 3. Backlog Drain (SLA Protection)
Aggressively scales when jobs approach SLA breach:
```
Workers = Backlog / (Time_Until_Breach / Avg_Job_Time)
```

The autoscaler takes the **maximum** of these three calculations to ensure SLA compliance.

## Use Cases

### High-Volume Applications
Applications processing thousands of jobs per minute with strict SLA requirements.

### Variable Traffic Patterns
E-commerce sites with peak hours, marketing campaigns, or seasonal traffic.

### Multi-Tenant Systems
SaaS platforms with varying workloads across different customers.

### Cost Optimization
Minimize worker count during off-peak hours while meeting SLA targets.

### Mission-Critical Queues
Systems requiring guaranteed processing times for critical operations.

## Requirements

- **PHP**: 8.3 or 8.4
- **Laravel**: 11.0+
- **Dependencies**:
  - `cboxdk/laravel-queue-metrics` ^1.0.0
  - `cboxdk/system-metrics` ^1.2

## Package Architecture

Laravel Queue Autoscale is designed as a **metrics consumer** rather than a metrics collector:

- **laravel-queue-metrics**: Discovers queues, scans connections, collects all metrics
- **laravel-queue-autoscale**: Consumes metrics, applies algorithms, manages workers

This separation of concerns keeps each package focused and maintainable.

## Next Steps

Ready to get started? Follow the [Installation](installation.md) guide to set up the package in your Laravel application.

Want to understand the scaling algorithm? See [How It Works](basic-usage/how-it-works.md).

Looking for configuration options? Check the [Configuration Guide](basic-usage/configuration.md).
