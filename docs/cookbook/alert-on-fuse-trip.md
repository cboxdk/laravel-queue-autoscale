---
title: "Alert on a Fuse Trip"
description: "Page your on-call when the failure fuse holds a queue back from scaling into a downstream outage"
weight: 40
---

# Alert on a Fuse Trip

A tripped [failure fuse](../basic-usage/failure-fuse.md) is a stronger signal than a slow queue: it means the queue's jobs are *failing*, not merely waiting. This recipe wires the three fuse events to alerts.

Fuse events fire on state transitions only, never per evaluation cycle, so a sustained outage produces one alert rather than one every five seconds. You do not need to rate-limit these listeners the way you do for [SLA breach events](../basic-usage/event-handling.md).

## 1. Write the listener

`app/Listeners/AlertOnFuseTrip.php`:

```php
<?php

namespace App\Listeners;

use Cbox\LaravelQueueAutoscale\Events\FuseProbing;
use Cbox\LaravelQueueAutoscale\Events\FuseRecovered;
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;
use Illuminate\Support\Facades\Log;

class AlertOnFuseTrip
{
    public function handleTripped(FuseTripped $event): void
    {
        Log::channel('queue-alerts')->critical('Queue autoscaling held back by failure fuse', [
            'queue' => $event->queue,
            'connection' => $event->connection,
            'failure_rate' => round($event->failureRate, 1),
            'threshold' => $event->thresholdPercent,
            'samples' => $event->samples,
            'held_at_workers' => $event->heldAtWorkers,
        ]);
    }

    public function handleProbing(FuseProbing $event): void
    {
        Log::channel('queue-alerts')->info('Probing for recovery', [
            'queue' => $event->queue,
            'probe_workers' => $event->probeWorkers,
        ]);
    }

    public function handleRecovered(FuseRecovered $event): void
    {
        Log::channel('queue-alerts')->notice('Failure fuse closed, scaling resumed', [
            'queue' => $event->queue,
            'failure_rate' => round($event->failureRate, 1),
        ]);
    }
}
```

See [Alert via Laravel Log](alert-via-log.md) for setting up the `queue-alerts` channel, or swap the `Log` calls for the Slack or email approaches in [Alert via Slack](alert-via-slack.md) and [Alert via Email](alert-via-email.md).

## 2. Register it

`app/Providers/EventServiceProvider.php`:

```php
use App\Listeners\AlertOnFuseTrip;
use Cbox\LaravelQueueAutoscale\Events\FuseProbing;
use Cbox\LaravelQueueAutoscale\Events\FuseRecovered;
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;

protected $listen = [
    FuseTripped::class => [[AlertOnFuseTrip::class, 'handleTripped']],
    FuseProbing::class => [[AlertOnFuseTrip::class, 'handleProbing']],
    FuseRecovered::class => [[AlertOnFuseTrip::class, 'handleRecovered']],
];
```

## 3. Write a message worth waking up to

The default payload tells you which queue is failing and how badly. What it cannot tell you is *what* is failing — that lives in your job exceptions. A useful alert points at both:

```php
public function handleTripped(FuseTripped $event): void
{
    $recentFailure = \DB::table('failed_jobs')
        ->where('queue', $event->queue)
        ->latest('failed_at')
        ->value('exception');

    Log::channel('queue-alerts')->critical(sprintf(
        '%s: %.0f%% of jobs failing (%d samples). Autoscaling held at %d workers. Latest: %s',
        $event->queue,
        $event->failureRate,
        $event->samples,
        $event->heldAtWorkers,
        str($recentFailure ?? 'unknown')->limit(200),
    ));
}
```

Note that `failed_jobs` only fills up once jobs exhaust their retries, so on a fresh outage it may lag the fuse by a few minutes. The fuse counts thrown exceptions immediately — that gap is intentional, and explained in [What counts as a failure](../basic-usage/failure-fuse.md#what-counts-as-a-failure).

## Measuring incident duration

Pair the trip and recovery events to record how long each incident lasted:

```php
use Illuminate\Support\Facades\Cache;

public function handleTripped(FuseTripped $event): void
{
    Cache::put("fuse-trip:{$event->connection}:{$event->queue}", now()->timestamp, 86400);
    // ...alert
}

public function handleRecovered(FuseRecovered $event): void
{
    $key = "fuse-trip:{$event->connection}:{$event->queue}";
    $startedAt = Cache::pull($key);

    if ($startedAt !== null) {
        Log::channel('queue-alerts')->notice('Incident resolved', [
            'queue' => $event->queue,
            'duration_seconds' => now()->timestamp - $startedAt,
        ]);
    }
}
```

If you run [`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry), you get this for free — `queue_autoscale.fuse.state` is a gauge you can measure directly, no bookkeeping required. See [Telemetry](../basic-usage/failure-fuse.md#telemetry).
