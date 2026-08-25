---
title: "Integrations & Developer Hooks"
description: "Facade APIs, the cluster JSON snapshot, Laravel events and the telemetry provider for monitor packages and custom tooling"
weight: 25
---

# Integrations & Developer Hooks

This page is for package authors and platform teams wiring Queue Autoscale into dashboards, monitor
packages, alerting and audit pipelines.

There are four integration surfaces:

1. The facade / service API
2. The cluster JSON snapshot
3. The Laravel event stream
4. The optional `cboxdk/laravel-telemetry` provider

Use the facade when your integration runs inside the same Laravel app. Use the JSON snapshot when
another process wants a current-state document. Use events when you need an append-only operational
trace. Use telemetry when you already run an OpenTelemetry pipeline.

## Facade API

`Cbox\LaravelQueueAutoscale\LaravelQueueAutoscale` has exactly two public methods, both exposed
through the facade:

```php
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;

$cluster = LaravelQueueAutoscale::cluster();        // array<string, mixed>
$metrics = LaravelQueueAutoscale::clusterMetrics(); // list<array{name, value, labels}>
```

There is no per-queue accessor, no decision history reader and no worker-pool API on the facade.
Anything else you need comes from events or from resolving the internal classes yourself.

### `cluster()`

Returns the cluster summary published by the current leader, read from `ClusterStore`.

**It returns an empty array when cluster mode is disabled** (`QUEUE_AUTOSCALE_CLUSTER_ENABLED`
defaults to `false`), and also before any leader has published a summary. Always guard:

```php
$cluster = LaravelQueueAutoscale::cluster();

if ($cluster === []) {
    // Cluster mode off, or no summary published yet.
}
```

The payload is intentionally an array rather than a typed DTO, and fields are added over time. Read
defensively with `??` defaults.

### `clusterMetrics()`

Flattens the same summary into exporter-friendly rows:

```php
$rows = [
    ['name' => 'queue_autoscale_cluster_managers', 'value' => 3, 'labels' => ['cluster' => 'my-app']],
    ['name' => 'queue_autoscale_cluster_host_workers', 'value' => 8, 'labels' => [
        'cluster' => 'my-app',
        'manager_id' => 'manager-1',
        'host' => 'web-01',
        'leader' => 'true',
    ]],
];
```

Every row is `['name' => string, 'value' => int|float, 'labels' => array<string, scalar|null>]`.
The series produced are:

| Scope | Metric names |
|---|---|
| Cluster | `queue_autoscale_cluster_managers`, `queue_autoscale_cluster_workers`, `queue_autoscale_cluster_required_workers`, `queue_autoscale_cluster_worker_capacity`, `queue_autoscale_cluster_recommended_hosts` |
| Per manager | `queue_autoscale_cluster_host_workers`, `queue_autoscale_cluster_host_capacity`, `queue_autoscale_cluster_host_cpu_percent`, `queue_autoscale_cluster_host_memory_percent` |
| Per workload | `queue_autoscale_workload_workers_current`, `queue_autoscale_workload_workers_target`, `queue_autoscale_workload_pending_jobs`, … |

`clusterMetrics()` is deliberately narrower than `cluster()`. New summary fields do **not**
automatically appear here — if you need full workload and lifecycle context, read `cluster()`.

## Cluster JSON Snapshot

The CLI exposes the same summary:

```bash
php artisan queue:autoscale:cluster --json
```

Without cluster mode enabled it prints a warning and exits `0` without JSON, and it does the same
when no summary has been published yet — so a collector should treat "no JSON on stdout" as "not
ready", not as an error.

Useful for local debugging, cron collectors, sidecar agents, and monitor packages that prefer to
shell out rather than bind to the service.

### Summary fields

- `cluster_id`
- `generated_at`, `generated_at_unix_ms`
- `leader_id`, `leader_renewed_at`, `leader_renewed_at_unix_ms`, `leader_lease_ttl_seconds`,
  `leader_expires_at`
- `manager_count`
- `total_workers`
- `required_workers`
- `total_worker_capacity`
- `utilization_percent`
- `scale_signal`
- `managers`
- `workloads`
- `scaling_decisions`

### Manager fields

Each `managers[]` entry:

- `manager_id`, `host`, `is_leader`
- `last_seen_at` (unix milliseconds), `last_seen_human`
- `total_workers`, `max_workers`, `available_worker_capacity`, `capacity_limiter`
- `cpu_percent`, `cpu_cores`, `cpu_usable_cores`, `cpu_reserved_cores`
- `memory_percent`, `memory_total_mb`, `memory_used_mb`, `memory_free_mb`
- `queue_count`, `group_count`
- `package_version`
- `queue_workers`, `group_workers`

The three `cpu_*_cores` fields are floats and can be fractional in cgroup-constrained environments.

### Workload fields

Each `workloads[]` entry:

- `type` (`queue` or `group`), `connection`, `name`, `driver`
- `current_workers`, `demand`, `target_workers`
- `worker_min`, `worker_max`, `sla_target_seconds`
- `pending`, `oldest_job_age`, `oldest_job_age_status`
- `throughput_per_minute`, `active_workers`, `utilization_percent`
- `member_queues`
- `action` — `'scale_up' | 'scale_down' | 'hold'`

`demand` is the raw per-workload requirement before fair-share allocation; `target_workers` is what
the leader finally published, after both fair-share allocation and anti-flapping damping — so it can
sit above the allocation while a scale-down is being damped, and below the demand when the cluster is
capacity-bound. A persistent gap between `demand` and `target_workers` means the cluster is
capacity-bound.

## Laravel Events

All events live in `Cbox\LaravelQueueAutoscale\Events`.

### Workload / scaling

| Event | Constructor properties |
|---|---|
| `ScalingDecisionMade` | `decision` |
| `SlaBreachPredicted` | `decision` |
| `WorkersScaled` | `connection, queue, from, to, action, reason` |
| `SlaBreached` | `connection, queue, oldestJobAge, slaTarget, pending, activeWorkers` |
| `SlaRecovered` | `connection, queue, currentJobAge, slaTarget, pending, activeWorkers` |

`ScalingDecisionMade` and `SlaBreachPredicted` carry the `ScalingDecision` **only** — no metrics
object and no configuration — and they carry the decision *after* policies have modified it.

### Failure fuse

| Event | Constructor properties |
|---|---|
| `FuseTripped` | `connection, queue, failureRate, samples, failures, thresholdPercent, heldAtWorkers` |
| `FuseProbing` | `connection, queue, probeWorkers, cooldownSeconds` |
| `FuseRecovered` | `connection, queue, failureRate, samples` |

### Cluster / lifecycle

| Event | Constructor properties |
|---|---|
| `AutoscaleManagerStarted` | `managerId, host, clusterEnabled, clusterId, intervalSeconds, startedAt, packageVersion` |
| `AutoscaleManagerStopped` | `managerId, host, clusterEnabled, clusterId, startedAt, stoppedAt, reason, workerCount, packageVersion` |
| `ClusterLeaderChanged` | `clusterId, previousLeaderId, currentLeaderId, observedByManagerId, changedAt` |
| `ClusterManagerPresenceChanged` | `clusterId, managerIds, addedManagerIds, removedManagerIds, leaderId, observedByManagerId, observedAt` |
| `ClusterSummaryPublished` | `clusterId, leaderId, summary, publishedAt` |
| `ClusterScalingSignalUpdated` | see `src/Events/ClusterScalingSignalUpdated.php` |

There is no worker-level health event. `src/Workers/the manager's inline liveness check.php` exists but dispatches
nothing — worker deaths are visible only through the log channel and the next cycle's decision.

### Registering listeners

```php
use Cbox\LaravelQueueAutoscale\Events\ClusterLeaderChanged;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Illuminate\Support\Facades\Event;

Event::listen(ScalingDecisionMade::class, function (ScalingDecisionMade $event): void {
    // $event->decision->targetWorkers, ->reason, ->capacity?->limitingFactor
});

Event::listen(WorkersScaled::class, function (WorkersScaled $event): void {
    // $event->from, $event->to, $event->action
});

Event::listen(ClusterLeaderChanged::class, function (ClusterLeaderChanged $event): void {
    // $event->previousLeaderId, $event->currentLeaderId
});
```

Events are dispatched from the manager's evaluation loop, which is a long-running CLI process.
Listeners run synchronously inside the tick — keep them fast, or queue the work.

## Telemetry (`cboxdk/laravel-telemetry`)

The integration lives in `src/Telemetry/` and is wired by the service provider only when **all** of
these hold: `Cbox\Telemetry\TelemetryManager` exists, `queue-autoscale.telemetry.enabled` is true
(env `QUEUE_AUTOSCALE_TELEMETRY_ENABLED`, default `true`), and `TelemetryManager` is bound in the
container. Otherwise every part of it is a no-op, so the config can stay on by default.

```php
'telemetry' => [
    'enabled' => env('QUEUE_AUTOSCALE_TELEMETRY_ENABLED', true),
    'cache_ttl' => env('QUEUE_AUTOSCALE_TELEMETRY_CACHE_TTL', 10),
    'gauges' => [
        'cluster' => true,
    ],
    'events' => true,
],
```

`cboxdk/laravel-telemetry` is a `suggest`/dev dependency, not a requirement, and it needs Laravel 12
or newer.

### Observable cluster gauges

`QueueAutoscaleTelemetryProvider` registers itself under the provider name `cbox.queue-autoscale`
and, when `telemetry.gauges.cluster` is true, exposes gauges evaluated at scrape time from the
cluster summary:

| Gauge | Source |
|---|---|
| `queue_autoscale.cluster.managers` | `manager_count` |
| `queue_autoscale.cluster.workers` | `total_workers` |
| `queue_autoscale.cluster.required_workers` | `required_workers` |
| `queue_autoscale.cluster.worker_capacity` | `total_worker_capacity` |
| `queue_autoscale.cluster.utilization` | `utilization_percent` |
| `queue_autoscale.cluster.recommended_hosts` | `scale_signal.recommended_hosts` |
| `queue_autoscale.cluster.host_workers` | per-manager `total_workers` |
| `queue_autoscale.cluster.host_capacity` | per-manager `max_workers` |

Queue depth, job durations and worker counts are deliberately **not** re-exported here — those are
owned by `laravel-queue-metrics` and telemetry's own queue instrumentation.

### Pushed event metrics

`TelemetryEventSubscriber` is registered as a container singleton and subscribed to the event
dispatcher. It pushes gauges and counters from inside the manager daemon (push rather than
observable, because nothing else could evaluate a scrape callback for the daemon's in-memory state),
flushing at most once per second for per-tick decisions and immediately for rare signals.

It subscribes to `ScalingDecisionMade`, `WorkersScaled`, `SlaBreached`, `SlaRecovered`,
`AutoscaleManagerStarted`, `AutoscaleManagerStopped`, `ClusterLeaderChanged`, `FuseTripped`,
`FuseProbing` and `FuseRecovered`, and emits series including `queue_autoscale.workers.target`,
`queue_autoscale.sla.target`, `queue_autoscale.sla.predicted_pickup`,
`queue_autoscale.capacity.max_workers`, `queue_autoscale.scaling.actions`,
`queue_autoscale.sla.breaches`, `queue_autoscale.cluster.leader_changes`,
`queue_autoscale.fuse.trips` and `queue_autoscale.fuse.state`.

`queue_autoscale.fuse.state` encodes the fuse as a single series — `0` closed, `1` half-open
(probing), `2` open (holding at `workers.min`) — so a dashboard reads one series instead of
reconciling several booleans that can disagree mid-transition.

Set `telemetry.events` to `false` to keep the cluster gauges but stop the pushed event metrics.

## Choosing between snapshot and events

Use `cluster()` / `--json` for current state: topology, leader, per-manager capacity and memory,
current workload targets, dashboard cards and tables.

Use events for history: transitions, alerting, audit logs, and asynchronous fan-out into Slack,
notifications, analytics or a monitor package's database.

Most monitor packages want both — snapshot for now, events for what happened.

## Design guidance for monitor packages

- Treat `cluster_id` + `generated_at_unix_ms` as the snapshot identity.
- Treat lifecycle events as append-only history rows.
- Store `manager_id`, `cluster_id`, `host` and timestamps on every record.
- Read summary arrays defensively; prefer `??` defaults over hard assumptions.
- Use `package_version` on each manager entry to detect mixed-version clusters during a rollout.
- Handle `cluster() === []` as a first-class state, not an error.

## Not currently exposed

- A typed DTO contract for the summary — it is an array, and that is deliberate.
- Host load average.
- Remaining cooldown time per queue.
- Distinct heartbeat-stale / manager-expired events; presence changes are reported only through
  `ClusterManagerPresenceChanged`.
- Any worker process health event.

## See Also

- [Event Handling](../basic-usage/event-handling.md) - Listener patterns for the event stream
- [Cluster Scaling](../basic-usage/cluster-scaling.md) - How the summary is produced
- [Monitoring](../basic-usage/monitoring.md) - Operational monitoring
- [Export Cluster Metrics](../cookbook/cluster-metrics-export.md) - A worked exporter recipe
