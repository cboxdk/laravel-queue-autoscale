---
title: "Drive Host Autoscaling"
description: "Feed the cluster's host recommendation to KEDA, AWS Auto Scaling or any external scaler"
weight: 42
---

# Drive Host Autoscaling

This package decides how many **workers** should run and starts them. It never creates or destroys
**hosts** — it has no cloud credentials and no opinion about your infrastructure. What it does have is
the one number an infrastructure autoscaler cannot work out for itself: how many hosts the current
workload actually needs.

Wiring the two together gives you a full loop. The autoscaler sizes workers against a pickup-time SLA
and the capacity it measures; your infrastructure scaler reads the resulting host recommendation and
provides the machines.

## The signal

When [cluster mode](../basic-usage/cluster-scaling.md) is enabled, the elected leader publishes a
scale signal on every evaluation cycle:

```json
{
  "scale_signal": {
    "action": "scale_up",
    "reason": "required workers exceed observed cluster capacity",
    "current_hosts": 3,
    "recommended_hosts": 5
  }
}
```

`recommended_hosts` is the current host count plus however many additional hosts of average observed
capacity would be needed to fit the workers that do not currently fit. It is a count of machines, not
a utilization ratio, so an external scaler can target it directly.

Two properties make it safer to scale on than raw queue depth:

- **It already knows what the machines can carry.** The worker demand behind it was bounded by measured
  CPU and memory headroom before it ever became a host count. A queue that cannot be drained faster
  does not inflate the recommendation.
- **It refuses to recommend shrinking under pressure.** If cluster utilization is at or above 80%, or
  any workload still has pending jobs or a target above its current worker count, the action becomes
  `hold` and `recommended_hosts` stays at the current count. You do not need to add your own
  stabilization window to stop it flapping a machine away mid-burst.

## Exposing it

### Through telemetry

If the application already uses [`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry),
the gauges are registered for you by `QueueAutoscaleTelemetryProvider` and appear wherever telemetry
renders. The relevant ones:

| Gauge | Meaning |
|---|---|
| `queue_autoscale_cluster_recommended_hosts` | Hosts the leader recommends |
| `queue_autoscale_cluster_managers` | Hosts currently reporting |
| `queue_autoscale_cluster_utilization_percent` | Cluster worker capacity in use |
| `queue_autoscale_cluster_required_workers` | Cluster-wide worker demand |
| `queue_autoscale_cluster_host_capacity` | Worker capacity per host, labelled by `host` |

These are the rendered Prometheus names. Telemetry declares them with dots and appends a unit suffix
where the unit has a Prometheus base word, which is why utilization gains `_percent` and the worker
counts do not gain anything.

Gauges are evaluated at scrape time from the Redis-backed cluster summary, so scraping does not
perturb the manager.

### Without telemetry

The facade exposes the same numbers as a flat list, ready to render in whatever format your scraper
expects. See [Export Cluster Metrics](cluster-metrics-export.md) for a complete endpoint.

Note that the facade names the recommendation `queue_autoscale_cluster_hosts_recommended`, while the
telemetry gauge renders as `queue_autoscale_cluster_recommended_hosts`. They carry the same value.
Pick one surface and query it consistently rather than assuming a name from the other.

```php
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;

$signal = LaravelQueueAutoscale::cluster()['scale_signal'] ?? [];

$signal['recommended_hosts'];  // int
$signal['action'];             // 'scale_up' | 'scale_down' | 'hold'
```

## KEDA

KEDA's Prometheus scaler reads the recommendation and sets the replica count of the deployment that
runs your managers. With `AverageValue` and a threshold of `1`, the desired replica count comes out
equal to the recommended host count.

```yaml
apiVersion: keda.sh/v1alpha1
kind: ScaledObject
metadata:
  name: queue-workers
spec:
  scaleTargetRef:
    name: queue-worker-deployment
  # Never zero. The recommendation is computed FROM the hosts that report in,
  # so a cluster with no managers publishes no summary and no metric.
  minReplicaCount: 1
  maxReplicaCount: 20
  pollingInterval: 15
  triggers:
    - type: prometheus
      metricType: AverageValue
      metadata:
        serverAddress: http://prometheus.monitoring.svc:9090
        query: max(queue_autoscale_cluster_recommended_hosts)
        threshold: "1"
```

`max()` matters: every host scrapes the same leader-published summary, so without an aggregator the
query returns one identical series per host and the sum would multiply your fleet by itself.

Leave KEDA's own cooldown at its default. The recommendation already holds steady under pressure, and
stacking a second stabilization window on top only makes the fleet slower to respond without making it
calmer.

## AWS EC2 Auto Scaling

Two shapes work, and they suit different appetites.

**Target tracking** wants a ratio it can hold at a set point, so publish
`queue_autoscale_cluster_utilization` to CloudWatch and track it at around 70%. This is the
lower-effort option and behaves well, but it reacts to utilization rather than to the recommendation
itself, so it will not anticipate a burst the way the worker calculation does.

**Direct desired capacity** uses the recommendation as-is. Have a listener on
`ClusterScalingSignalUpdated` call `SetDesiredCapacity` when the action is not `hold`:

```php
use Cbox\LaravelQueueAutoscale\Events\ClusterScalingSignalUpdated;

class ScaleAutoScalingGroup
{
    public function handle(ClusterScalingSignalUpdated $event): void
    {
        if ($event->action === 'hold') {
            return;
        }

        $this->autoScaling->setDesiredCapacity([
            'AutoScalingGroupName' => 'queue-workers',
            'DesiredCapacity' => max($event->recommendedHosts, 1),
        ]);
    }
}
```

Set the group's minimum size to at least one for the same reason KEDA needs `minReplicaCount: 1`, and
set its cooldown longer than an instance takes to boot and register — otherwise the recommendation,
which counts only hosts that have reported in, will ask for the same machine twice.

## What to check before trusting it

Confirm the loop is closed rather than assuming it:

```bash
php artisan queue:autoscale:cluster
```

The output includes the host signal and the recommendation. Compare it against what your scaler
actually did. Three failures are worth looking for specifically:

- **The metric is missing.** Cluster mode is off, or no manager has completed an evaluation cycle yet.
  The gauges return nothing rather than zero, which is deliberate — a zero would read as "no hosts
  needed" and scale the fleet away.
- **The recommendation never falls.** Something is holding the cluster under pressure. Run
  `php artisan queue:autoscale:debug` on a host to see which queue, and whether the
  [failure fuse](../basic-usage/failure-fuse.md) has tripped.
- **The fleet oscillates.** Your scaler's cooldown is shorter than instance start-up, so hosts are
  requested faster than they can report in.
