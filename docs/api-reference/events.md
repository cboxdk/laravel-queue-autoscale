---
title: "Events"
description: "Every event the manager dispatches and the payload each carries"
weight: 40
---

## Events

All live in `Cbox\LaravelQueueAutoscale\Events`. See [Event Handling](../basic-usage/event-handling.md) for listener patterns.

| Event | Fires | Payload |
|---|---|---|
| `ScalingDecisionMade` | Every cycle | `$decision` |
| `SlaBreachPredicted` | Every cycle during risk | `$decision` |
| `SlaBreached` | Once per state transition | `$connection`, `$queue`, `$oldestJobAge`, `$slaTarget`, `$pending`, `$activeWorkers` |
| `SlaRecovered` | Once per state transition | `$connection`, `$queue`, `$currentJobAge`, `$slaTarget`, `$pending`, `$activeWorkers` |
| `WorkersScaled` | Per spawn/terminate action | `$connection`, `$queue`, `$from`, `$to`, `$action`, `$reason` |
| `ClusterScalingSignalUpdated` | When the leader publishes a new host scaling signal | `$clusterId`, `$leaderId`, `$currentHosts`, `$recommendedHosts`, `$currentCapacity`, `$requiredWorkers`, `$action`, `$reason` |
| `AutoscaleManagerStarted` | When a manager process starts | `$managerId`, `$host`, `$clusterEnabled`, `$clusterId`, `$intervalSeconds`, `$startedAt`, `$packageVersion` |
| `AutoscaleManagerStopped` | When a manager process stops | `$managerId`, `$host`, `$clusterEnabled`, `$clusterId`, `$startedAt`, `$stoppedAt`, `$reason`, `$workerCount`, `$packageVersion` |
| `ClusterLeaderChanged` | When a manager observes the cluster leader change | `$clusterId`, `$previousLeaderId`, `$currentLeaderId`, `$observedByManagerId`, `$changedAt` |
| `ClusterManagerPresenceChanged` | When the leader observes managers join/leave the active set | `$clusterId`, `$managerIds`, `$addedManagerIds`, `$removedManagerIds`, `$leaderId`, `$observedByManagerId`, `$observedAt` |
| `ClusterSummaryPublished` | When the leader publishes a fresh cluster summary | `$clusterId`, `$leaderId`, `$summary`, `$publishedAt` |

## Alerting

### `AlertRateLimiter`

Cache-lock-based cooldown helper. Used internally by `BreachNotificationPolicy` and recommended for custom alert listeners.

```php
namespace Cbox\LaravelQueueAutoscale\Alerting;

readonly class AlertRateLimiter
{
    public function __construct(public int $cooldownSeconds = 300) {}

    public function allow(string $key): bool;  // true = proceed, false = still in cooldown
}
```

Resolved from the container with cooldown from `queue-autoscale.alerting.cooldown_seconds` (env var: `QUEUE_AUTOSCALE_ALERT_COOLDOWN`).
