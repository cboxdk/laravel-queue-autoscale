---
title: "Alert via Slack"
description: "Post SLA breaches to a Slack channel using an incoming webhook URL"
weight: 11
---

# Alert via Slack

Send SLA breach and recovery notifications to a Slack channel with one incoming-webhook URL. No packages needed beyond Laravel's HTTP client.

## 1. Create a Slack incoming webhook

1. In Slack: **Apps → Incoming Webhooks → Add to Slack**
2. Pick a channel (e.g. `#queue-alerts`)
3. Copy the webhook URL

## 2. Store the URL

`.env`:

```
QUEUE_ALERTS_SLACK_WEBHOOK=https://hooks.slack.com/services/T00/B00/xxx
```

`config/services.php`:

```php
'queue_alerts' => [
    'slack_webhook' => env('QUEUE_ALERTS_SLACK_WEBHOOK'),
],
```

## 3. The listener

`app/Listeners/NotifyQueueSlack.php`:

```php
<?php

namespace App\Listeners;

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotifyQueueSlack
{
    public function __construct(private AlertRateLimiter $limiter) {}

    public function onBreach(SlaBreached $event): void
    {
        if (! $this->limiter->allow("slack:breach:{$event->connection}:{$event->queue}")) {
            return;
        }

        $this->post(sprintf(
            ":rotating_light: *SLA breach on `%s:%s`* — oldest job %ds (target %ds), %d pending, %d workers",
            $event->connection,
            $event->queue,
            $event->oldestJobAge,
            $event->slaTarget,
            $event->pending,
            $event->activeWorkers,
        ));
    }

    public function onRecovery(SlaRecovered $event): void
    {
        // No rate-limit needed: SlaRecovered only fires on state transition.
        $this->post(sprintf(
            ":white_check_mark: *SLA recovered on `%s:%s`* — pickup back under %ds",
            $event->connection,
            $event->queue,
            $event->slaTarget,
        ));
    }

    private function post(string $text): void
    {
        $url = (string) config('services.queue_alerts.slack_webhook');

        if ($url === '') {
            return;
        }

        try {
            Http::timeout(5)->post($url, ['text' => $text]);
        } catch (\Throwable $e) {
            // Never let alerting crash the manager — log and move on.
            Log::warning('Slack alert failed', ['error' => $e->getMessage()]);
        }
    }
}
```

## 4. Register it

`app/Providers/EventServiceProvider.php`:

```php
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use App\Listeners\NotifyQueueSlack;

protected $listen = [
    SlaBreached::class => [NotifyQueueSlack::class.'@onBreach'],
    SlaRecovered::class => [NotifyQueueSlack::class.'@onRecovery'],
];
```

## Done

Force a breach in a local test to verify:

```bash
# Shovel 50 slow jobs (1s each) into the default queue, then watch Slack.
php artisan queue:autoscale:test 50 --duration=1000
```

## Tuning

- Default rate-limit is 5 minutes per `connection:queue`. Change via `QUEUE_AUTOSCALE_ALERT_COOLDOWN` or `queue-autoscale.alerting.cooldown_seconds`.
- For multi-channel routing (e.g. `#critical-alerts` for payments, `#queue-noise` for everything else), add a match on `$event->queue` and use different webhook URLs.
- Swap the raw webhook for [`laravel/slack-notification-channel`](https://laravel.com/docs/notifications#slack-notifications) if you prefer the Notification abstraction — same rate-limiter pattern, different sender.
- Running into Slack rate limits from the webhook itself? That's an upstream concern — our rate limiter dedupes at the event source, but bursts across many queues can still hit Slack's ingest. Consider batching or a dedicated `#` per severity tier.
