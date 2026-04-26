---
title: "Integrations & Developer Hooks"
description: "Facade APIs, cluster snapshots, JSON output, and Laravel events for monitor packages and custom integrations"
weight: 25
---

# Integrations & Developer Hooks

This page is for package authors and internal platform teams integrating Queue Autoscale with dashboards, monitor packages, alerting, and audit pipelines.

## Public Integration Surfaces

Queue Autoscale currently exposes three practical integration surfaces:

1. The Laravel facade / service API
2. The cluster JSON snapshot
3. The Laravel event stream

Use the facade when your integration runs inside the same Laravel app. Use the JSON snapshot when another process or package wants a stable current-state document. Use events when you need an append-only operational trace.

## Facade API

```php
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;

$cluster = LaravelQueueAutoscale::cluster();
$metrics = LaravelQueueAutoscale::clusterMetrics();
```

### `cluster()`

Returns the latest Redis-backed cluster summary as an array.

Important notes:

- This is the richest integration surface today.
- It is intentionally array-based, not a typed DTO contract.
- New fields may be added over time; integrations should read defensively with `??` defaults.

### `clusterMetrics()`

Returns flattened metrics arrays suitable for exporters and simple monitor pipelines.

Important notes:

- `clusterMetrics()` is narrower than `cluster()`.
- New manager/workload fields added to the summary do **not** automatically appear as flattened metrics.
- If you need full workload and lifecycle context, prefer `cluster()`.

## Cluster JSON Snapshot

The CLI exposes the same cluster snapshot in JSON form:

```bash
php artisan queue:autoscale:cluster --json
```

This is useful for:

- local debugging
- cron-based collectors
- sidecar agents
- monitor packages that want to shell out rather than bind directly to the service

### Summary Fields

The summary includes cluster-level fields such as:

- `cluster_id`
- `generated_at`
- `generated_at_unix_ms`
- `leader_id`
- `leader_renewed_at`
- `leader_renewed_at_unix_ms`
- `leader_lease_ttl_seconds`
- `leader_expires_at`
- `manager_count`
- `total_workers`
- `required_workers`
- `total_worker_capacity`
- `utilization_percent`
- `scale_signal`
- `managers`
- `workloads`

### Manager Fields

Each `managers[]` entry includes:

- `manager_id`
- `host`
- `is_leader`
- `last_seen_at`
- `last_seen_human`
- `total_workers`
- `max_workers`
- `available_worker_capacity`
- `capacity_limiter`
- `cpu_percent`
- `memory_percent`
- `memory_total_mb`
- `memory_used_mb`
- `memory_free_mb`
- `queue_count`
- `group_count`
- `package_version`
- `queue_workers`
- `group_workers`

### Workload Fields

Each `workloads[]` entry includes:

- `type`
- `connection`
- `name`
- `driver`
- `current_workers`
- `target_workers`
- `worker_min`
- `worker_max`
- `sla_target_seconds`
- `pending`
- `oldest_job_age`
- `oldest_job_age_status`
- `throughput_per_minute`
- `active_workers`
- `utilization_percent`
- `member_queues`
- `action`

## Laravel Events

Queue Autoscale now emits both workload events and cluster/lifecycle events.

### Workload / scaling events

- `ScalingDecisionMade`
- `SlaBreachPredicted`
- `SlaBreached`
- `SlaRecovered`
- `WorkersScaled`

### Cluster / lifecycle events

- `ClusterScalingSignalUpdated`
- `AutoscaleManagerStarted`
- `AutoscaleManagerStopped`
- `ClusterLeaderChanged`
- `ClusterManagerPresenceChanged`
- `ClusterSummaryPublished`

## Choosing Between Snapshot and Events

Use `cluster()` / `--json` when you need:

- current cluster topology
- current leader
- per-manager capacity and memory
- current workload targets
- monitor UI cards and tables

Use events when you need:

- a trace of what happened over time
- alerting on transitions
- audit logs
- asynchronous fan-out into Slack, logs, notifications, analytics, or a monitor package database

In practice, most monitor packages want both:

- snapshot for the current state
- events for history

## Example: Registering Event Listeners

```php
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Events\ClusterLeaderChanged;
use Cbox\LaravelQueueAutoscale\Events\ClusterManagerPresenceChanged;
use Cbox\LaravelQueueAutoscale\Events\ClusterSummaryPublished;
use Illuminate\Support\Facades\Event;

Event::listen(AutoscaleManagerStarted::class, fn (AutoscaleManagerStarted $event) => /* persist */);
Event::listen(AutoscaleManagerStopped::class, fn (AutoscaleManagerStopped $event) => /* persist */);
Event::listen(ClusterLeaderChanged::class, fn (ClusterLeaderChanged $event) => /* persist */);
Event::listen(ClusterManagerPresenceChanged::class, fn (ClusterManagerPresenceChanged $event) => /* persist */);
Event::listen(ClusterSummaryPublished::class, fn (ClusterSummaryPublished $event) => /* persist */);
```

## Design Guidance For Monitor Packages

If you are building a downstream package such as `cboxdk/laravel-queue-monitor`:

- Treat `cluster_id + generated_at_unix_ms` as a snapshot identity.
- Treat lifecycle events as append-only history rows.
- Store `manager_id`, `cluster_id`, `host`, and timestamps on every record.
- Read summary arrays defensively; prefer null-coalescing defaults over hard assumptions.
- Use `package_version` to detect mixed-version clusters during rollout.

## What Is Still Not A First-Class Hook

These are still not modeled as dedicated APIs/events:

- `cpu_load_1m`
- host-scale cooldown remaining time
- a typed summary DTO contract
- explicit heartbeat-stale / manager-expired events separate from presence changes

Those can be added later if the monitor package needs them, but the current surface is already enough for a practical cluster dashboard + event trace.
