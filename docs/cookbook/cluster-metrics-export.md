---
title: "Export Cluster Metrics"
description: "Expose Queue Autoscale cluster metrics and host scaling signals to your monitoring stack"
weight: 40
---

# Export Cluster Metrics

When cluster mode is enabled, Queue Autoscale publishes a Redis-backed cluster summary and exposes flattened metrics through the main facade/service.

## Expose a JSON endpoint

```php
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;
use Illuminate\Support\Facades\Route;

Route::get('/internal/queue-autoscale/cluster', function () {
    return response()->json(LaravelQueueAutoscale::cluster());
});
```

## Expose Prometheus-style metrics

```php
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;
use Illuminate\Support\Facades\Route;

Route::get('/internal/queue-autoscale/metrics', function () {
    $lines = [];

    foreach (LaravelQueueAutoscale::clusterMetrics() as $metric) {
        $labels = [];

        foreach ($metric['labels'] as $key => $value) {
            if ($value === null) {
                continue;
            }

            $labels[] = sprintf('%s="%s"', $key, addslashes((string) $value));
        }

        $suffix = $labels === [] ? '' : '{'.implode(',', $labels).'}';
        $lines[] = sprintf('%s%s %s', $metric['name'], $suffix, (string) $metric['value']);
    }

    return response(implode("\n", $lines)."\n", 200, [
        'Content-Type' => 'text/plain; version=0.0.4',
    ]);
});
```

## React to host scaling signals

```php
use Cbox\LaravelQueueAutoscale\Events\ClusterScalingSignalUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(ClusterScalingSignalUpdated::class, function (ClusterScalingSignalUpdated $event): void {
    Log::info('queue-autoscale cluster host signal', [
        'cluster_id' => $event->clusterId,
        'leader_id' => $event->leaderId,
        'action' => $event->action,
        'reason' => $event->reason,
        'current_hosts' => $event->currentHosts,
        'recommended_hosts' => $event->recommendedHosts,
        'current_capacity' => $event->currentCapacity,
        'required_workers' => $event->requiredWorkers,
    ]);
});
```

This is the easiest way to feed host-scale advisories into `cboxdk/laravel-queue-monitor`, your own dashboard, or an external autoscaling controller.
