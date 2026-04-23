---
title: "Quick Start"
description: "Get one queue autoscaled in 5 minutes with concrete, verifiable steps"
weight: 3
---

# Quick Start

Zero to a working autoscaled queue in about 5 minutes. Every command and file path on this page is real — nothing is a placeholder.

## Prerequisites

- PHP 8.3+ and Laravel 11+
- Redis configured in `config/database.php` only if you plan to use the Redis or cluster presets
- `cboxdk/laravel-queue-metrics` already set up — see [Installation](installation.md)

## Step 1 — Install and publish config

```bash
composer require cboxdk/laravel-queue-autoscale
php artisan queue:autoscale:install
```

You now have `config/queue-autoscale.php`, `config/queue-metrics.php`, and a matching env setup for the preset you chose. The defaults work out of the box: one queue (`default`) on your default connection, using the `BalancedProfile` (30s SLA, 1–10 workers).

## Step 2 — Start the daemon

In one terminal:

```bash
php artisan queue:autoscale -v
```

You should see something like:

```
Starting Queue Autoscale Manager
   Manager ID: your-host
   Evaluation interval: 5s
```

The manager evaluates every 5 seconds. Leave it running.

> Use `-vv` for debug-level output (per-queue metrics and decisions) and `-vvv` to also see the capacity breakdown used for each scaling decision.

## Step 3 — Dispatch test jobs

In a second terminal, push some work onto the queue. One quick way via tinker:

```bash
php artisan tinker
>>> for ($i = 0; $i < 50; $i++) { dispatch(function () { sleep(1); }); }
```

(If your app already has job classes, dispatch those instead.) Switch back to the manager terminal and watch.

Within a few evaluation cycles you'll see output like:

```
Evaluating queue: redis:default
  Metrics: pending=42, oldest_age=14s, active_workers=1, throughput=6/min
  📊 Decision: 1 → 6 workers
     Reason: Little's Law (λ=0.80, W=1.00s) + backlog drain to target
  ⬆️  Scaling UP: spawning 5 worker(s)
```

When the backlog drains, the manager scales back down (respecting `cooldown_seconds`, default 60s).

## Step 4 — Tune for your workload

The defaults cover ~80% of cases. When they don't, pick a shipped profile or override specific keys.

### Pick a profile

Five profiles ship with the package. Each is a pre-tuned bundle of SLA, worker limits, and forecast settings.

```php
// config/queue-autoscale.php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;

'sla_defaults' => \Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile::class,

'queues' => [
    'payments' => CriticalProfile::class,      // 10s SLA, 5-50 workers
    'analytics' => BackgroundProfile::class,   // 300s SLA, 0-5 workers
],
```

See [Workload Profiles](basic-usage/workload-profiles.md) for the full comparison.

### Override specific keys

Need almost a profile but with tighter limits? Pass an array that deep-merges on top of `sla_defaults`:

```php
'queues' => [
    'exports' => [
        'sla' => ['target_seconds' => 45],
        'workers' => ['min' => 0, 'max' => 3],
    ],
],
```

### Lock a queue to sequential processing

Some queues must not run in parallel (customer integrations that need strict ordering, APIs with single-connection rate limits). Use `ExclusiveProfile`:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;

'queues' => [
    'legacy-sync' => ExclusiveProfile::class,
],
```

The manager pins the queue to exactly one worker and respawns it if it dies. It never scales. See [Queue Topology](basic-usage/queue-topology.md#exclusive-sequential-queues).

## Step 5 — Hook up alerts

The manager emits events (`SlaBreached`, `SlaRecovered`, `WorkersScaled`, etc.) that any Laravel listener can consume.

Three paste-and-go recipes cover the common cases:

- [Alert via Log](cookbook/alert-via-log.md) — dedicated log channel, zero external deps
- [Alert via Slack](cookbook/alert-via-slack.md) — one webhook URL
- [Alert via Email](cookbook/alert-via-email.md) — via Laravel Notifications

All three use the built-in `AlertRateLimiter` so a sustained breach doesn't become a pager storm.

## Step 6 — Deploy

Run the manager as a long-running daemon. Pick your platform:

- [Self-Hosted VPS (systemd / Supervisor)](deployment/self-hosted-vps.md)
- [Laravel Forge](deployment/forge.md)
- [Ploi](deployment/ploi.md)
- [Docker / Compose](deployment/docker.md)

**Important:** if your platform has a separate "queue workers" UI (Forge, Ploi), **don't** configure workers there for queues the autoscaler manages. They would fight. See each platform page for details.

## Verify it worked

```bash
# Inspect what the manager and metrics package see for a queue.
php artisan queue:autoscale:debug --queue=default --connection=redis
```

If workers are not spawning even though jobs are piling up, head to [Troubleshooting](basic-usage/troubleshooting.md) — it's organized by symptom.

## What to read next

- **[Queue Topology](basic-usage/queue-topology.md)** — when to use per-queue, groups, exclusive, or excluded. Start here before adding more queues.
- **[Configuration](basic-usage/configuration.md)** — the full config reference, including advanced keys.
- **[How It Works](basic-usage/how-it-works.md)** — Little's Law, backlog drain, and forecasting, explained.
- **[Cookbook](cookbook/_index.md)** — more recipes beyond alerting.
