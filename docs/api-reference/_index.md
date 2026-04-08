---
title: "API Reference"
description: "Queue Autoscale for Laravel API documentation for contracts and classes"
weight: 70
---

# API Reference

Complete Queue Autoscale for Laravel API documentation.

## Core Components

### Configuration
Configuration objects and data structures

### Scaling
Scaling strategies and policies interfaces

### Events
Laravel events for autoscaling lifecycle

### Workers
Worker process and pool management

## Quick Reference

### Contracts

```php
// Strategy Contract
interface ScalingStrategyContract
{
    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int;
    public function getLastReason(): string;
    public function getLastPrediction(): ?float;
}

// Policy Contract
interface ScalingPolicyContract
{
    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void;
    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void;
}
```

### Key Classes

- `AutoscaleManager` - Main orchestrator
- `ScalingEngine` - Decision calculation
- `QueueConfiguration` - Per-queue settings
- `ScalingDecision` - Scaling decision result
- `WorkerPool` - Worker collection management
- `WorkerProcess` - Individual worker process

## See Also

- [Basic Usage](../basic-usage/_index.md) - Implementation guides
- [Advanced Usage](../advanced-usage/_index.md) - Custom strategies and policies
- [Algorithms](../algorithms/_index.md) - Mathematical foundations
