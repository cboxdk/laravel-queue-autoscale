---
title: "Upgrading to v4"
description: "Every breaking change from v3, what it means, and what to do about it"
weight: 91
---

# Upgrading to v4

Most applications upgrade by raising PHP to 8.4, renaming one configuration key and deleting another.
The list below is complete rather than short — everything that can break is here, including the parts
that will not affect you.

Start with the check command, which reads your configuration against the queues you actually have:

```bash
composer require cboxdk/laravel-queue-autoscale:^4.0
php artisan queue:autoscale:doctor
```

## Requirements

**PHP 8.4 is now the minimum**, and Laravel 11 is no longer supported. If you are on PHP 8.3 or
Laravel 11, upgrade those first — v3 runs on both and there is no rush to move.

| | v3 | v4 |
|---|---|---|
| PHP | 8.3, 8.4, 8.5 | **8.4, 8.5** |
| Laravel | 11, 12, 13 | **12, 13** |

## The worker timeout is now two settings

This is the change most likely to affect you, and the one most likely to have been quietly wrong
before.

In v3, `workers.timeout_seconds` was passed to `queue:work` as `--max-time` — the worker *process's*
lifetime. It was never a job timeout, despite reading like one. An operator setting `3600` believed
they had allowed hour-long jobs; what they had actually done was recycle the worker hourly while jobs
still died at Laravel's default timeout.

The two are now separate:

```php
'workers' => [
    'max_time_seconds' => 3600,  // --max-time: recycle the worker process this often
    'timeout_seconds' => 900,    // --timeout: how long a single job may run
],
```

**What to do:** rename `timeout_seconds` to `max_time_seconds` everywhere it appears in your config
and in any custom profile. Then decide what your job timeout should be — the new `timeout_seconds`
defaults to 900 seconds, and configuration will refuse a job timeout that is not shorter than the
process lifetime, because a job allowed to outlive its own worker can never finish.

## The global `workers` block is gone

v3 had two places to configure workers: a global `queue-autoscale.workers` block and a per-queue
`workers` key. Only the global one reached a spawned worker, so `tries`, `sleep_seconds` and
`shutdown_timeout_seconds` set on a profile were parsed, validated, and ignored.

The profile is now the only surface, and what a queue declares is what its workers run with.

**What to do:** delete the top-level `'workers' => [...]` block from `config/queue-autoscale.php`. If
it held values you relied on, move them into `sla_defaults` or into the profiles that need them —
they will now actually take effect, which may itself be a change in behaviour worth watching.

One key moved rather than being deleted: `workers.shutdown_timeout_seconds` is now
`manager.shutdown_grace_seconds`, and it is a *fallback* for workers whose queue configuration can no
longer be resolved — not a floor. A pool of fast queues no longer waits for the slowest global
setting.

## Metric names

The package publishes cluster metrics on two surfaces — the `clusterMetrics()` facade method and the
telemetry gauges — and they disagreed about five names. They now agree. If you scrape either, update
your queries:

| v3 | v4 |
|---|---|
| `queue_autoscale_cluster_hosts_recommended` | `queue_autoscale_cluster_recommended_hosts` |
| `queue_autoscale_cluster_workers_current` | `queue_autoscale_cluster_workers` |
| `queue_autoscale_cluster_workers_required` | `queue_autoscale_cluster_required_workers` |
| `queue_autoscale_cluster_capacity` | `queue_autoscale_cluster_worker_capacity` |
| `queue_autoscale_manager_workers` | `queue_autoscale_cluster_host_workers` |
| `queue_autoscale_manager_capacity` | `queue_autoscale_cluster_host_capacity` |
| `queue_autoscale_manager_cpu_percent` | `queue_autoscale_cluster_host_cpu_percent` |
| `queue_autoscale_manager_memory_percent` | `queue_autoscale_cluster_host_memory_percent` |

A dashboard querying an old name returns no data rather than an error, which looks identical to the
metric reading zero — so this is worth doing before you deploy, not after.

## The cluster store is behind a contract

`ClusterStore` now implements `ClusterStoreContract`, and everything in the package depends on the
contract. If you resolved `ClusterStore::class` from the container directly it still works, but
prefer the contract — and if you decorated or swapped the concrete class, bind
`ClusterStoreContract::class` instead.

## Deleted classes

- `Cbox\LaravelQueueAutoscale\Workers\ProcessHealthCheck` — worker liveness is checked inline in the
  evaluation cycle. Nothing referenced it.
- `Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\JobActivity` — unused output DTO.

## New in v4, nothing to do

These are additive. Listed so you know they exist:

- **Queue patterns.** `queue-autoscale.queues` accepts glob keys, so `scrape-tenant-*` can govern
  every tenant queue at once. Exact names still win over patterns. See
  [Respect a Per-Tenant Connection Limit](../cookbook/per-tenant-connection-limits.md).
- **Queue names with dots now reach their config.** `config('queue-autoscale.queues.tenant.42')` split
  on the dots, so a queue literally named `tenant.42` — or any `.fifo` queue — silently ran on
  defaults. If you have such a queue, its configuration will start applying on upgrade. That is the
  fix working, but it is a behaviour change: check the doctor output.
- **`profile` alongside overrides.** A queue entry can name a profile and refine it, instead of
  restating every field.
- **`ConnectionLimitedProfile`** for queues whose parallelism is dictated by something downstream.
- **`queue:autoscale:doctor`** — see [Check Your Configuration](../basic-usage/configuration-check.md).
- **`src/Testing`** — fakes and assertions for testing your own configuration. See
  [Testing Your Configuration](../basic-usage/testing.md).
- **Pickup sampling.** Above 100 recorded pickups per second per worker process, a uniformly random
  subset is stored instead of every one. Enabled by default; it costs nothing in accuracy because the
  store only ever retained a bounded number of samples anyway. Disable with
  `QUEUE_AUTOSCALE_PICKUP_SAMPLING=false`.

## Checklist

- [ ] PHP 8.4+ and Laravel 12+
- [ ] `workers.timeout_seconds` renamed to `max_time_seconds`, and a job timeout chosen
- [ ] Top-level `'workers'` block deleted from the config
- [ ] `workers.shutdown_timeout_seconds` moved to `manager.shutdown_grace_seconds` if you set it globally
- [ ] Dashboards and alerts updated for the renamed metrics
- [ ] `php artisan queue:autoscale:doctor` run and its output read
