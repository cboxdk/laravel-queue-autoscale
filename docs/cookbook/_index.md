---
title: "Cookbook"
description: "Paste-and-go recipes for alerting, deployment, and common operations with Queue Autoscale for Laravel"
weight: 15
---

# Cookbook

Short, concrete recipes for the things you'll wire up on day one. Code first, explanation second.

## Alerting

The autoscaler emits a handful of events your app can listen to. These recipes hook them up to the three places most Laravel teams actually read:

- [Alert via Laravel Log](alert-via-log.md) — zero setup, uses your existing log stack
- [Alert via Slack](alert-via-slack.md) — one webhook URL, done
- [Alert via Email](alert-via-email.md) — via Laravel Notifications

All three use the built-in `AlertRateLimiter` so you don't get 50 Slack pings for one breach.

## Events the autoscaler emits

| Event | When | Fires |
|---|---|---|
| `SlaBreached` | Oldest job age ≥ SLA target | Once on state entry |
| `SlaRecovered` | Oldest job age drops below SLA target | Once on state exit |
| `SlaBreachPredicted` | Forecaster predicts pickup time will exceed SLA | Per evaluation cycle during risk |
| `WorkersScaled` | Workers spawn or terminate | Per scaling action |
| `ScalingDecisionMade` | Any scaling decision (including HOLD) | Per evaluation cycle |

`SlaBreached`/`SlaRecovered` already fire at most once per state change, so they don't need additional rate-limiting. `SlaBreachPredicted` and `WorkersScaled` can fire rapidly during flapping — those are the events where rate-limiting matters.

## The rate limiter in one line

```php
use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;

public function __construct(private AlertRateLimiter $limiter) {}

public function handle(SlaBreached $event): void
{
    if (! $this->limiter->allow("slack:{$event->connection}:{$event->queue}")) {
        return;
    }
    // send the alert
}
```

Default cooldown is 300 seconds (5 min), configurable via `queue-autoscale.alerting.cooldown_seconds` or the `QUEUE_AUTOSCALE_ALERT_COOLDOWN` env var.
