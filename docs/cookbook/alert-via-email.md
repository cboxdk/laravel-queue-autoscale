---
title: "Alert via Email"
description: "Email SLA breaches to an on-call address using Laravel Notifications"
weight: 12
---

# Alert via Email

Email SLA breaches to one or more addresses via Laravel's built-in notification system. Works with any mail driver you've already configured.

## 1. Configure the recipient

`.env`:

```
QUEUE_ALERTS_EMAIL=oncall@example.com
```

`config/services.php`:

```php
'queue_alerts' => [
    'email' => env('QUEUE_ALERTS_EMAIL'),
],
```

## 2. The notification

`app/Notifications/QueueSlaBreachedNotification.php`:

```php
<?php

namespace App\Notifications;

use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QueueSlaBreachedNotification extends Notification
{
    use Queueable;

    public function __construct(public SlaBreached $event) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $e = $this->event;

        return (new MailMessage)
            ->error()
            ->subject("[Queue SLA breach] {$e->connection}:{$e->queue}")
            ->line("Queue `{$e->connection}:{$e->queue}` is breaching its SLA.")
            ->line("Oldest pending job: **{$e->oldestJobAge}s** (target: {$e->slaTarget}s)")
            ->line("Pending jobs: **{$e->pending}**")
            ->line("Active workers: **{$e->activeWorkers}**")
            ->line('The autoscaler has been asked to scale up, but spawning new workers may take a few seconds. If the breach persists, check worker capacity and any upstream incidents.');
    }
}
```

## 3. The listener

`app/Listeners/EmailQueueAlert.php`:

```php
<?php

namespace App\Listeners;

use App\Notifications\QueueSlaBreachedNotification;
use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Illuminate\Support\Facades\Notification;

class EmailQueueAlert
{
    public function __construct(private AlertRateLimiter $limiter) {}

    public function handle(SlaBreached $event): void
    {
        $recipient = (string) config('services.queue_alerts.email');

        if ($recipient === '') {
            return;
        }

        // Email is more expensive than Slack — use a longer cooldown in practice.
        if (! $this->limiter->allow("email:breach:{$event->connection}:{$event->queue}")) {
            return;
        }

        Notification::route('mail', $recipient)
            ->notify(new QueueSlaBreachedNotification($event));
    }
}
```

## 4. Register it

`app/Providers/EventServiceProvider.php`:

```php
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use App\Listeners\EmailQueueAlert;

protected $listen = [
    SlaBreached::class => [EmailQueueAlert::class],
];
```

## Done

Test with Mailtrap or `MAIL_MAILER=log` locally. Force a breach by dispatching a burst of slow jobs — via tinker: `for ($i = 0; $i < 50; $i++) { dispatch(function () { sleep(1); }); }`. Then check the inbox (or `storage/logs/laravel.log`).

## Tuning

- **Cooldown.** The default 5-minute cooldown is reasonable for Slack but too chatty for email. For email alerts specifically, consider a longer value — set `QUEUE_AUTOSCALE_ALERT_COOLDOWN=1800` (30 min) or bind a different `AlertRateLimiter` instance just for this listener.
- **On-call rotation.** If you route email through a rotation service (PagerDuty, Opsgenie), point `QUEUE_ALERTS_EMAIL` at their ingest address — the rest of the recipe stays the same.
- **Recovery notifications.** The recipe above only fires on breach. Add a listener for `SlaRecovered` with a lighter email template if you want "all clear" notifications.
- **Multiple recipients.** `Notification::route('mail', ['a@example.com', 'b@example.com'])` — no other changes needed.
