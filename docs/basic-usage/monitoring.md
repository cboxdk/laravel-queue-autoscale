---
title: "Monitoring"
description: "What the autoscaler emits — events, telemetry gauges and logs — and how to turn them into dashboards and alerts"
weight: 12
---

# Monitoring

This page covers what Queue Autoscale actually emits, and how to turn that into dashboards and alerts.

There are exactly three observability surfaces:

1. **Laravel events** — dispatched by the manager process. See [Event Handling](event-handling.md) for the full list.
2. **Telemetry** — gauges, counters and events published to `cboxdk/laravel-telemetry` when that package is installed.
3. **Logs** — written to the channel in `queue-autoscale.manager.log_channel` (default `stack`).

Queue depth, throughput and job durations are **not** owned by this package. They come from `cboxdk/laravel-queue-metrics`; query them through its `QueueMetrics` facade.

## What the events carry

The autoscaler's events do not carry a metrics snapshot or a config object. `ScalingDecisionMade` and `SlaBreachPredicted` carry exactly one property — `$decision`, a `ScalingDecision`:

```php
$event->decision->connection            // string
$event->decision->queue                 // string (group name for group workers)
$event->decision->currentWorkers        // int
$event->decision->targetWorkers         // int
$event->decision->reason                // string
$event->decision->predictedPickupTime   // ?float — null when no prediction was possible
$event->decision->slaTarget             // int seconds
$event->decision->capacity              // ?CapacityCalculationResult
$event->decision->spawnCompensation     // ?SpawnCompensationConfiguration
```

Plus the helpers `shouldScaleUp()`, `shouldScaleDown()`, `shouldHold()`, `workersToAdd()`, `workersToRemove()`, `action()` (`'scale_up' | 'scale_down' | 'hold'`) and `isSlaBreachRisk()`.

There is no confidence score on a decision — the strategy either produced a prediction or it did not.

## Key signals

### Worker count

`WorkersScaled` fires whenever workers are actually spawned or terminated.

```php
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Illuminate\Support\Facades\Event;

Event::listen(function (WorkersScaled $event) {
    $from = $event->from;          // int, before
    $to = $event->to;              // int, after
    $direction = $event->action;   // 'up' | 'down'
    $reason = $event->reason;      // string
});
```

**Watch for:**

- Frequently pinned at `workers.max` → raise the ceiling, or accept it as a deliberate budget.
- Rapid direction reversals → see [Performance → Worker Oscillation](performance.md#issue-worker-oscillation).

### Target vs. current workers

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

Event::listen(function (ScalingDecisionMade $event) {
    $gap = $event->decision->targetWorkers - $event->decision->currentWorkers;
});
```

`ScalingDecisionMade` fires every evaluation cycle, including when the decision is a hold. A sustained positive gap means something downstream of the strategy is clamping the target — check `$event->decision->capacity->limitingFactor`.

### Limiting factor

```php
$capacity = $event->decision->capacity;

if ($capacity !== null) {
    $capacity->maxWorkersByCpu;      // int
    $capacity->maxWorkersByMemory;   // int
    $capacity->maxWorkersByConfig;   // int — workers.max
    $capacity->finalMaxWorkers;      // int
    $capacity->limitingFactor;       // see below
}
```

`limitingFactor` is a `LimitingFactor` enum, not a string — compare against the case
(`LimitingFactor::Cpu`) or against `->value` if you need the raw token. The cases are `Cpu`,
`Memory`, `Balanced`, `Config`, `Strategy`, `Fuse` and `SystemMetricsUnavailable`, whose values are
the lowercase forms.

**Watch for:**

- `config` → the configured `workers.max` is the bottleneck, not the host.
- `cpu` / `memory` → the host is the bottleneck. Add capacity or lower per-worker estimates.
- `fuse` → the [failure fuse](failure-fuse.md) is holding the queue. Treat as an incident.
- `system_metrics_unavailable` → the system-metrics read failed and a conservative fallback was used.

### Predicted pickup time vs. SLA

```php
$predicted = $event->decision->predictedPickupTime;   // ?float seconds
$target = $event->decision->slaTarget;                // int seconds

if ($event->decision->isSlaBreachRisk()) {
    // predictedPickupTime > slaTarget
}
```

`predictedPickupTime` is `null` when the strategy could not produce one (no backlog, or no usable job-time estimate). Guard for null before dividing.

### Actual SLA state

`SlaBreached` and `SlaRecovered` fire **once per state transition**, not per cycle:

```php
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;

Event::listen(function (SlaBreached $event) {
    $event->connection;
    $event->queue;
    $event->oldestJobAge;    // int seconds
    $event->slaTarget;       // int seconds
    $event->pending;         // int
    $event->activeWorkers;   // int
    $event->breachSeconds();
    $event->breachPercentage();
});
```

`SlaRecovered` has the same shape but with `currentJobAge` instead of `oldestJobAge`, and offers `marginSeconds()` / `marginPercentage()`.

### Failure fuse state

Whether the [failure fuse](failure-fuse.md) is currently holding a queue back from scaling.

```php
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;

Event::listen(function (FuseTripped $event) {
    $rate = $event->failureRate;        // percent, over $event->samples outcomes
    $failures = $event->failures;       // int
    $threshold = $event->thresholdPercent;
    $held = $event->heldAtWorkers;      // workers.min
});
```

With `cboxdk/laravel-telemetry` installed this is also a gauge — `queue_autoscale.fuse.state`, where `0` is closed, `1` half-open and `2` open. Alert on `queue_autoscale_fuse_state > 0`.

Without any wiring at all, the manager logs `Autoscaling held back by failure fuse` at warning level to its configured channel for as long as a queue is held, rate-limited by `alerting.cooldown_seconds`.

**Watch for:**

- Any trip → treat as an incident. This is a stronger signal than an SLA breach: the queue's work is failing, not just late.
- Repeated trip/recover cycles on one queue → a flapping dependency, or a threshold set too close to the queue's baseline failure rate.
- A queue that never trips during a known outage → `min_samples` may be unreachable at that queue's throughput. See [Tuning](failure-fuse.md#tuning).

### Queue depth, throughput and job duration

These belong to the metrics package, not to the autoscaler. Read them directly:

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

$metrics = QueueMetrics::getQueueMetrics('redis', 'default');

$metrics->pending;              // int
$metrics->oldestJobAge;         // int seconds
$metrics->throughputPerMinute;  // float
$metrics->avgDuration;          // float — milliseconds
$metrics->failureRate;          // float percent
$metrics->utilizationRate;      // float percent
$metrics->activeWorkers;        // int
```

There is no worker-health event in this package. `ProcessHealthCheck` only answers whether a tracked PID is still alive; dead workers are dropped from the pool and, if the target still calls for them, respawned on the next cycle. The respawn shows up as a `WorkersScaled` event.

## Collecting metrics

### Push: an event listener

The manager is a long-running daemon, so listeners run in-process and must be fast.

```php
<?php

namespace App\Listeners;

use App\Services\MetricsCollector;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class CollectScalingMetrics
{
    public function __construct(
        private readonly MetricsCollector $metrics,
    ) {}

    public function handle(ScalingDecisionMade $event): void
    {
        $decision = $event->decision;

        $tags = [
            'queue' => $decision->queue,
            'connection' => $decision->connection,
        ];

        $this->metrics->gauge('autoscale.current_workers', $decision->currentWorkers, $tags);
        $this->metrics->gauge('autoscale.target_workers', $decision->targetWorkers, $tags);
        $this->metrics->gauge('autoscale.sla_target', $decision->slaTarget, $tags);

        if ($decision->predictedPickupTime !== null) {
            $this->metrics->gauge('autoscale.predicted_pickup_time', $decision->predictedPickupTime, $tags);
            $this->metrics->gauge(
                'autoscale.sla_usage_percent',
                ($decision->predictedPickupTime / $decision->slaTarget) * 100,
                $tags,
            );
        }

        if ($decision->capacity !== null) {
            $this->metrics->gauge('autoscale.max_workers', $decision->capacity->finalMaxWorkers, [
                ...$tags,
                'limiter' => $decision->capacity->limitingFactor->value,
            ]);
        }
    }
}
```

Register it in a service provider:

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Illuminate\Support\Facades\Event;

Event::listen(ScalingDecisionMade::class, \App\Listeners\CollectScalingMetrics::class);
```

### Pull: a scrape endpoint

A web request cannot see the manager daemon's in-memory worker pool — they are separate processes. A pull endpoint must therefore read from a shared store. Two sources are available:

- `LaravelQueueAutoscale::clusterMetrics()` — flattened cluster metrics, available in [cluster mode](cluster-scaling.md).
- The `QueueMetrics` facade — queue depth, throughput and worker heartbeats, available always.

```php
<?php

namespace App\Http\Controllers;

use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;
use Illuminate\Http\Response;

class MetricsController
{
    public function __invoke(): Response
    {
        $lines = [];

        foreach (LaravelQueueAutoscale::clusterMetrics() as $metric) {
            $labels = [];

            foreach ($metric['labels'] as $key => $value) {
                $labels[] = sprintf('%s="%s"', $key, $value);
            }

            $lines[] = sprintf('%s{%s} %s', $metric['name'], implode(',', $labels), $metric['value']);
        }

        return response(implode("\n", $lines))->header('Content-Type', 'text/plain');
    }
}
```

`clusterMetrics()` returns an empty array when cluster mode is disabled. See [Cluster Metrics Export](../cookbook/cluster-metrics-export.md) for a complete recipe.

## Telemetry integration

When `cboxdk/laravel-telemetry` is installed and its `TelemetryManager` is bound, the autoscaler registers itself automatically. It is a no-op otherwise — no configuration is required to get the safe default.

Two config keys control it:

```php
'telemetry' => [
    'enabled' => env('QUEUE_AUTOSCALE_TELEMETRY_ENABLED', true),  // master switch
    'cache_ttl' => env('QUEUE_AUTOSCALE_TELEMETRY_CACHE_TTL', 10), // cluster snapshot cache, seconds
    'gauges' => ['cluster' => true],                               // observable cluster gauges
    'events' => true,                                              // push gauges/counters from events
],
```

Setting `telemetry.enabled` to `false` skips registration entirely. `telemetry.events` gates the event-driven push path only; `telemetry.gauges.cluster` gates the scrape-time cluster gauges only.

### Pushed from events

| Instrument | Type | Source event |
|---|---|---|
| `queue_autoscale.workers.target` | gauge | `ScalingDecisionMade` |
| `queue_autoscale.sla.target` | gauge | `ScalingDecisionMade` |
| `queue_autoscale.sla.predicted_pickup` | gauge | `ScalingDecisionMade` (when a prediction exists) |
| `queue_autoscale.capacity.max_workers` | gauge | `ScalingDecisionMade` (labelled `limiter`) |
| `queue_autoscale.scaling.actions` | counter | `WorkersScaled` (labelled `direction`) |
| `queue_autoscale.sla.breach` | gauge | `SlaBreached` (1) / `SlaRecovered` (0) |
| `queue_autoscale.sla.breaches` | counter | `SlaBreached` |
| `queue_autoscale.fuse.state` | gauge | `FuseTripped` (2) / `FuseProbing` (1) / `FuseRecovered` (0) |
| `queue_autoscale.fuse.trips` | counter | `FuseTripped` |
| `queue_autoscale.cluster.leader_changes` | counter | `ClusterLeaderChanged` |

Gauges and counters are labelled with `connection` and `queue` where the source event carries them. OTLP events are emitted alongside: `queue_autoscale.scaling.action`, `.sla.breached`, `.sla.recovered`, `.manager.started`, `.manager.stopped`, `.cluster.leader_changed`, `.fuse.tripped`, `.fuse.probing`, `.fuse.recovered`.

Every handler flushes immediately except the per-cycle `ScalingDecisionMade` one, which flushes at most once per second.

### Observable cluster gauges

Evaluated at scrape time from the Redis-backed cluster summary, so they report nothing in single-host mode:

| Gauge | Meaning |
|---|---|
| `queue_autoscale.cluster.managers` | Active autoscale managers in the cluster |
| `queue_autoscale.cluster.workers` | Autoscaler-spawned workers across the cluster |
| `queue_autoscale.cluster.required_workers` | Cluster-wide worker demand |
| `queue_autoscale.cluster.worker_capacity` | Cluster-wide worker capacity |
| `queue_autoscale.cluster.utilization` | Cluster worker capacity utilization, percent |
| `queue_autoscale.cluster.recommended_hosts` | Host count recommended by the leader |
| `queue_autoscale.cluster.host_workers` | Workers per host, labelled `host` |
| `queue_autoscale.cluster.host_capacity` | Worker capacity per host, labelled `host` |

The snapshot behind these is cached for `telemetry.cache_ttl` seconds so concurrent scrapes do not all hit the cluster store.

## Alerting

### SLA breach

`SlaBreached` fires once per transition, so no rate limiting is needed for the transition itself:

```php
<?php

namespace App\Listeners;

use App\Services\AlertingService;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;

class AlertOnSlaBreach
{
    public function __construct(
        private readonly AlertingService $alerting,
    ) {}

    public function handle(SlaBreached $event): void
    {
        $this->alerting->send([
            'severity' => 'critical',
            'title' => "SLA breached: {$event->connection}:{$event->queue}",
            'message' => sprintf(
                'Oldest job is %ds old against a %ds target (%.1f%% over)',
                $event->oldestJobAge,
                $event->slaTarget,
                $event->breachPercentage(),
            ),
            'details' => [
                'pending' => $event->pending,
                'active_workers' => $event->activeWorkers,
            ],
        ]);
    }
}
```

### Predicted breach

`SlaBreachPredicted` fires **every cycle** while risk is present. Gate it through the package's own rate limiter:

```php
<?php

namespace App\Listeners;

use App\Services\AlertingService;
use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Events\SlaBreachPredicted;

class AlertOnPredictedBreach
{
    public function __construct(
        private readonly AlertingService $alerting,
        private readonly AlertRateLimiter $limiter,
    ) {}

    public function handle(SlaBreachPredicted $event): void
    {
        $decision = $event->decision;

        if (! $this->limiter->allow("predicted:{$decision->connection}:{$decision->queue}")) {
            return;
        }

        $this->alerting->send([
            'severity' => 'warning',
            'title' => "SLA breach predicted: {$decision->queue}",
            'message' => sprintf(
                'Predicted pickup %.1fs against a %ds target',
                $decision->predictedPickupTime ?? 0.0,
                $decision->slaTarget,
            ),
        ]);
    }
}
```

`AlertRateLimiter` resolves from the container with the cooldown from `queue-autoscale.alerting.cooldown_seconds` (300s by default).

### At the configured ceiling

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

public function handle(ScalingDecisionMade $event): void
{
    $capacity = $event->decision->capacity;

    if ($capacity === null || $capacity->limitingFactor !== LimitingFactor::Config) {
        return;
    }

    // workers.max is the bottleneck for this queue.
    $this->alerting->send([
        'severity' => 'warning',
        'title' => "Queue at configured maximum: {$event->decision->queue}",
        'message' => "Consider raising workers.max (currently {$capacity->maxWorkersByConfig})",
    ]);
}
```

Rate-limit this one too — `ScalingDecisionMade` fires every cycle.

### Fuse trip

See [Alert on a Fuse Trip](../cookbook/alert-on-fuse-trip.md) for a paste-and-go listener.

## Dashboards

**Overview panel:**

- Current vs. target workers (`queue_autoscale.workers.target` against your own current-worker series)
- Queue depth and oldest job age (from the metrics package)
- Predicted pickup time against `queue_autoscale.sla.target`
- Scaling actions per minute (`queue_autoscale.scaling.actions`, split by `direction`)

**Health panel:**

- `queue_autoscale.sla.breach` per queue (a step function: 1 while breaching)
- `queue_autoscale.fuse.state` per queue (0/1/2)
- `queue_autoscale.capacity.max_workers` broken down by the `limiter` label

**Cluster panel** (cluster mode only):

- `queue_autoscale.cluster.managers`, `.workers`, `.capacity`, `.utilization`
- `queue_autoscale.cluster.recommended_hosts` against `.managers`
- `queue_autoscale.cluster.leader_changes` — a rising rate means unstable leadership

### Grafana example

```json
{
  "dashboard": {
    "title": "Queue Autoscale",
    "panels": [
      {
        "title": "Target workers",
        "targets": [{"expr": "queue_autoscale_workers_target{queue=\"default\"}"}]
      },
      {
        "title": "SLA breaching",
        "targets": [{"expr": "queue_autoscale_sla_breach"}]
      },
      {
        "title": "Fuse state",
        "targets": [{"expr": "queue_autoscale_fuse_state"}],
        "thresholds": [
          {"value": 1, "color": "yellow"},
          {"value": 2, "color": "red"}
        ]
      }
    ]
  }
}
```

## Troubleshooting from the signals

### Workers not scaling up

```bash
php artisan queue:autoscale:debug --queue=<your-queue> --connection=<your-connection>
```

Then read `limitingFactor` on the next `ScalingDecisionMade`. `config` means raise `workers.max`; `cpu`/`memory` means the host is full; `fuse` means the queue's jobs are failing.

### Oscillating worker count

Count direction reversals on `WorkersScaled` per queue per minute. If reversals are frequent, see [Performance → Worker Oscillation](performance.md#issue-worker-oscillation).

### SLA breaches with headroom left

If `SlaBreached` fires while `limitingFactor` is `strategy`, the strategy is not asking for enough workers — check the job-time estimate in `queue:autoscale:debug`, and whether `sla.min_samples` is reachable at your throughput.

## See Also

- [Event Handling](event-handling.md) — the full event catalogue and listener patterns
- [Failure Fuse](failure-fuse.md) — the circuit breaker and its telemetry
- [Cluster Scaling](cluster-scaling.md) — cluster summary and `clusterMetrics()`
- [Troubleshooting](troubleshooting.md) — symptom-indexed diagnosis
- [API Reference](../api-reference/_index.md) — exact types and signatures
