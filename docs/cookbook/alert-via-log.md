---
title: "Alert via Laravel Log"
description: "Log SLA breaches and scaling events to a dedicated Laravel log channel"
weight: 10
---

# Alert via Laravel Log

The autoscaler already logs its own activity. This recipe adds **structured, filterable** alert lines to a dedicated channel so your existing log infrastructure (Papertrail, Loggly, CloudWatch, whatever) can trigger on them.

## 1. Add a dedicated log channel

`config/logging.php`:

```php
'channels' => [
    // ... your existing channels

    'queue-alerts' => [
        'driver' => 'daily',
        'path' => storage_path('logs/queue-alerts.log'),
        'level' => 'warning',
        'days' => 14,
    ],
],
```

Use whatever driver fits your stack — `single`, `syslog`, `papertrail`, `stderr` for containers, etc.

## 2. Register the listener

`app/Providers/EventServiceProvider.php`:

```php
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use App\Listeners\LogQueueAlerts;

protected $listen = [
    SlaBreached::class => [LogQueueAlerts::class.'@onBreach'],
    SlaRecovered::class => [LogQueueAlerts::class.'@onRecovery'],
    WorkersScaled::class => [LogQueueAlerts::class.'@onScaled'],
];
```

## 3. The listener

`app/Listeners/LogQueueAlerts.php`:

```php
<?php

namespace App\Listeners;

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Illuminate\Support\Facades\Log;

class LogQueueAlerts
{
    public function __construct(private AlertRateLimiter $limiter) {}

    public function onBreach(SlaBreached $event): void
    {
        Log::channel('queue-alerts')->error('Queue SLA breach', [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'oldest_job_age_seconds' => $event->oldestJobAge,
            'sla_target_seconds' => $event->slaTarget,
            'pending_jobs' => $event->pending,
            'active_workers' => $event->activeWorkers,
        ]);
    }

    public function onRecovery(SlaRecovered $event): void
    {
        Log::channel('queue-alerts')->info('Queue SLA recovered', [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'current_job_age_seconds' => $event->currentJobAge,
            'sla_target_seconds' => $event->slaTarget,
        ]);
    }

    public function onScaled(WorkersScaled $event): void
    {
        // WorkersScaled fires per action — rate-limit so flapping doesn't spam the log.
        $key = "scaled:{$event->connection}:{$event->queue}";

        if (! $this->limiter->allow($key)) {
            return;
        }

        Log::channel('queue-alerts')->notice('Workers scaled', [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'from' => $event->from,
            'to' => $event->to,
            'action' => $event->action,
            'reason' => $event->reason,
        ]);
    }
}
```

## Done

Tail the log to verify:

```bash
tail -f storage/logs/queue-alerts-*.log
```

Point your log aggregator's alert rules at the `Queue SLA breach` message or the `queue-alerts` channel directly.

## Tuning

- `SlaBreached` and `SlaRecovered` fire once per state transition, so they don't need rate-limiting. Only `WorkersScaled`, `ScalingDecisionMade`, and `SlaBreachPredicted` can be chatty.
- Change the cooldown in `config/queue-autoscale.php → alerting.cooldown_seconds` or via `QUEUE_AUTOSCALE_ALERT_COOLDOWN`.
- If your aggregator needs JSON, swap the `daily` driver for one that wraps logs in JSON formatters (e.g. `papertrail` or a custom Monolog processor).
