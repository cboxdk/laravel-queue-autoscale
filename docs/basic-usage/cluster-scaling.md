---
title: Cluster Scaling
description: "Run Queue Autoscale on multiple replicas with Redis-backed leader election and host recommendations"
---

# Cluster Scaling

Cluster mode lets multiple `queue:autoscale` managers run against the same queues.

## Requirements

- Redis must be available to every replica.
- All replicas must share the same Laravel app config and queue backend.
- Enable cluster mode with `QUEUE_AUTOSCALE_CLUSTER_ENABLED=true`.

If you do not enable cluster mode, Queue Autoscale keeps its single-host behavior and does not require Redis for coordination. In that mode, the default `auto` signal backends resolve to null/no-op implementations so the manager can run cleanly without Redis.

Managers join the cluster automatically. There is no manual cluster ID, seed list, or host registration step.

Only one `queue:autoscale` process is allowed per app per host. Starting a second process on the same host will fail fast unless you use `queue:autoscale --replace` to hand over the local host lock cleanly.

If you must override the generated node identity, set `QUEUE_AUTOSCALE_MANAGER_ID`. Do that only when you are certain the value is unique per host/pod/node for that app.

If you explicitly force `QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis` or `QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=redis`, those signal backends also require Redis even outside cluster mode.

## How it works

Every manager:

- Publishes a Redis heartbeat with its host name, current worker counts, CPU, memory, and local worker capacity.
- Reads the latest per-host worker recommendation from Redis.
- Reconciles only its own local workers to that recommendation.

The elected leader:

- Evaluates queue and group metrics for the whole cluster.
- Computes total target workers per workload.
- Distributes those targets across active hosts.
- Publishes a cluster summary with leader, managers, workloads, capacity, and a host scaling signal.

## Inspecting the cluster

```bash
php artisan queue:autoscale:cluster
php artisan queue:autoscale:cluster --json
```

The JSON output is intended for monitoring integrations and web UIs.

## Monitoring API

The package exposes the latest cluster snapshot and flattened metrics through the main service / facade:

```php
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;

$cluster = LaravelQueueAutoscale::cluster();
$metrics = LaravelQueueAutoscale::clusterMetrics();
```

`cluster()` returns the latest Redis-backed cluster summary.

`clusterMetrics()` returns flattened metrics suitable for Prometheus exporters, dashboards, and downstream monitoring packages such as `cboxdk/laravel-queue-monitor`.

## Host scaling signal

The cluster summary includes:

- `scale_signal.action`
- `scale_signal.reason`
- `scale_signal.current_hosts`
- `scale_signal.recommended_hosts`

This signal is also emitted as the `ClusterScalingSignalUpdated` event whenever the leader publishes a new cluster summary.
