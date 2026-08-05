---
title: "Troubleshooting"
description: "Symptom-driven diagnosis for Queue Autoscale for Laravel — find your symptom, get the fix"
weight: 13
---

# Troubleshooting

Find your symptom in the list below, then follow the diagnosis steps in order. Every command on this page is real and safe to run in production.

## Symptom index

- [Jobs are piling up but no workers are spawning](#jobs-are-piling-up-but-no-workers-are-spawning)
- [Workers spawn but die within seconds](#workers-spawn-but-die-within-seconds)
- [Workers keep spawning and terminating (flapping)](#workers-keep-spawning-and-terminating-flapping)
- [Logs show the same SLA breach line every few seconds](#logs-show-the-same-sla-breach-line-every-few-seconds)
- [Manager starts but produces no output](#manager-starts-but-produces-no-output)
- [Manager exits immediately on startup](#manager-exits-immediately-on-startup)
- [A queue is stuck at `workers.min` while its backlog grows](#a-queue-is-stuck-at-workersmin-while-its-backlog-grows)
- [An exclusive queue keeps respawning its worker](#an-exclusive-queue-keeps-respawning-its-worker)
- [A group never scales up even though its members have jobs](#a-group-never-scales-up-even-though-its-members-have-jobs)
- [Deploy finishes but new config is not applied](#deploy-finishes-but-new-config-is-not-applied)
- [Two autoscalers are fighting each other](#two-autoscalers-are-fighting-each-other)

## First diagnostic command

Before anything else:

```bash
php artisan queue:autoscale:debug --queue=<your-queue> --connection=<your-connection>
```

This dumps what both the metrics package and the autoscaler see for that queue. If the numbers are empty or wrong, the problem is upstream of this package — see [Manager starts but produces no output](#manager-starts-but-produces-no-output).

## Jobs are piling up but no workers are spawning

**Most common root causes, in order:**

1. **The manager isn't running.** Check with `ps aux | grep queue:autoscale`. If missing, start it (see your [platform deployment guide](../deployment/_index.md)).
2. **The queue is `excluded`.** Check `config/queue-autoscale.php` for a pattern matching your queue name in the `excluded` key, or check the log for `Queue excluded from autoscaling`.
3. **The metrics package isn't collecting data.** Run `php artisan queue:autoscale:debug --queue=default`. If `QueueMetrics::getQueueDepth()` returns zeros when you know there are pending jobs, `laravel-queue-metrics` isn't wired up — check its config and storage backend (Redis vs database).
4. **The manager is at the config-driven worker cap.** Check `workers.max` for the queue. `-vv` mode on the manager says `Constrained by workers.max config limit` when this happens.
5. **System capacity is maxed out.** `-vvv` mode shows the CPU/memory ceiling. If `limits.max_cpu_percent` or `max_memory_percent` is reached, spawning is blocked to protect the host.

**Quick check:**

```bash
# Run the manager in a one-shot very-verbose mode, look at one cycle:
php artisan queue:autoscale -vvv --interval=5
# Watch one full cycle, then Ctrl-C.
```

The output names the limiting factor for every queue.

## Workers spawn but die within seconds

**Symptoms:** Log shows `Worker spawned PID=X`, then `Worker did not stop gracefully` or `Removed dead worker` a moment later.

**Most common causes:**

1. **The `queue:work` invocation fails.** Run the exact command the manager uses, manually, as the same user:

   ```bash
   sudo -u forge php /home/forge/your-app/current/artisan queue:work redis --queue=default
   ```

   The error will be immediate — almost always `.env` permissions, missing Redis connection, or a wrong PHP path.
2. **PHP is running out of memory on each job.** Check `storage/logs/laravel.log` for `Allowed memory size` errors in the spawned workers. The autoscaler never passes `--memory` to `queue:work`, so raise `memory_limit` in the php.ini the manager's PHP binary uses.
3. **A worker is hitting `workers.max_time_seconds`.** That value is passed as `--max-time`, so the worker process exits after that many seconds of life and is respawned. Default is 3600s. Expected behaviour unless you see it every few seconds. (A job exceeding `workers.timeout_seconds` is a different event — that kills the job, not the worker.)

## Workers keep spawning and terminating (flapping)

**Symptoms:** Logs show continuous `scaled UP` followed by `scaled DOWN` every few evaluation cycles.

**Causes and fixes:**

1. **Arrival rate is genuinely oscillating.** The autoscaler is responding correctly, but it's noisy. Raise `scaling.cooldown_seconds` (default 60) to dampen. Anti-flapping already suppresses direction-reversals within the cooldown unless there's an active SLA breach.
2. **Your `workers.min` and target from strategy are close.** The strategy drops below min, gets clamped up, next cycle drops again. Lower `workers.min` to 0 or widen the gap between expected demand and min.
3. **A flaky metric source.** If `laravel-queue-metrics` is reporting wildly different throughput values from one cycle to the next, the strategy will over-react. `queue:autoscale:debug` run multiple times in a row should show stable numbers when there's no real traffic.

## Logs show the same SLA breach line every few seconds

`BreachNotificationPolicy` rate-limits its log output via `AlertRateLimiter` (default 300s cooldown), so a sustained breach risk should produce one line per queue per cooldown window.

If you're still seeing the spam:

1. Verify the policy is registered in `config/queue-autoscale.php → policies`.
2. Check `queue-autoscale.alerting.cooldown_seconds` — it may be set to a very low value.
3. If your app has custom listeners on `SlaBreachPredicted`, they need their own rate-limiting. Use `AlertRateLimiter` in them — pattern shown in the [cookbook](../cookbook/_index.md).

## Manager starts but produces no output

**The manager is quiet by design unless something changes.** Default output shows scaling events and per-cycle stats only when verbose.

```bash
# See what the manager is actually doing:
php artisan queue:autoscale -vv
```

If `-vv` still shows nothing after a full evaluation interval (5s unless you passed `--interval`), something is broken:

1. **Config-cached stale values.** Run `php artisan config:clear` on the host, then restart the manager.
2. **The metrics package is returning an empty queue list.** Run `queue:autoscale:debug` for a queue you know has jobs. If totals are zero, the metrics package isn't wired to your queue driver — its config is the problem.
3. **All queues are `excluded`.** Check the `excluded` array and any globs.

## Manager exits immediately on startup

Only two conditions stop `queue:autoscale` before it reaches its first cycle:

- **`Queue autoscale is disabled in config`** — set `'enabled' => true` or `QUEUE_AUTOSCALE_ENABLED=true`.
- **The host manager lock could not be acquired** — another manager for this app is already running on this host. Stop it, or start with `php artisan queue:autoscale --replace` to take over the lock.

Container-binding failures also abort the boot. These come from the service provider validating class-string config values:

- **`queue-autoscale.pickup_time.store must be a class that implements PickupTimeStoreContract, got: ...`**
- **`queue-autoscale.spawn_latency.tracker must be a class that implements SpawnLatencyTrackerContract, got: ...`**
- **`queue-autoscale.fuse.store must be a class that implements FailureWindowStoreContract, got: ...`**
- **`queue-autoscale.fuse.classifier must be a class that implements FailureClassifierContract, got: ...`**
- **`queue-autoscale.pickup_time.percentile_calculator must be a class that implements PercentileCalculatorContract, got: ...`**

Run `vendor:publish` again and merge manually if one of these appears.

### Bad config does NOT crash the manager

An invalid `workers.*` or `sla.*` block throws `InvalidConfigurationException` during the evaluation cycle, not at startup. The run loop catches it, logs `Autoscale evaluation failed` (with the message and a trace) to `manager.log_channel`, and continues — you get one error line per interval and no scaling for the affected queue. Watch the log; the manager will not tell you on stdout.

Invalid **group** config is handled separately: `Group configuration is invalid — groups disabled until manager restart` is logged at critical level once, all groups are skipped for the rest of the process's life, and per-queue autoscaling carries on.

## A queue is stuck at `workers.min` while its backlog grows

This is usually the [failure fuse](failure-fuse.md) doing its job: the queue's jobs are failing, and adding workers would only increase pressure on whatever is failing.

**Confirm it:**

```bash
php artisan queue:autoscale:debug --queue=<your-queue>
```

The `=== Failure Fuse ===` section reports the current state and the evidence behind it:

```text
=== Failure Fuse ===
+--------------+------------------------+
| Metric       | Value                  |
+--------------+------------------------+
| State        | open                   |
| Failure rate | 75.6% (93 of 123 jobs) |
| Trips at     | 50.0% over >= 20 jobs  |
| Window       | 60s                    |
| Cooldown     | 60s                    |
| Worker range | 1 - 10                 |
+--------------+------------------------+
TRIPPED: scaling is held at 1 worker(s). A probe runs automatically after 60s.
```

**Then:**

1. **If the failure rate is real** — the fix is upstream. Find out what your jobs are failing against. The fuse will probe for recovery on its own every `cooldown_seconds` and release scaling once the probe succeeds; no manual intervention is needed.
2. **If the failure rate looks wrong** — your threshold may be too close to the queue's baseline. Jobs hitting rate limits count as failures too. See [Tuning](failure-fuse.md#tuning).
3. **If you need to scale regardless** — disable the fuse for that queue with `'fuse' => ['enabled' => false]`, or globally with `QUEUE_AUTOSCALE_FUSE_ENABLED=false`. Understand that you are choosing to scale into a failure.

If the reason string does **not** mention the fuse, the cause is elsewhere — see [Jobs are piling up but no workers are spawning](#jobs-are-piling-up-but-no-workers-are-spawning).

## An exclusive queue keeps respawning its worker

**Symptoms:** Log shows `Supervisor respawned pinned workers` frequently for the same queue.

**Causes:**

1. **The worker job is crashing.** Run one of the queue's jobs synchronously to see the error: `php artisan queue:work redis --queue=legacy-sync --once`.
2. **A memory leak in the job code is hitting PHP's `memory_limit`.** Long-running workers accumulate memory. For jobs with known leaks, set `workers.max_time_seconds` to a low value (e.g. 300) on that queue so the process recycles regularly — the supervisor respawns them automatically.
3. **Something external is killing PHP processes.** OS-level OOM-killer, a misconfigured systemd service, or a deploy script that kills `queue:work` but leaves the autoscaler running. Check `dmesg` and systemd journals.

## A group never scales up even though its members have jobs

Two scenarios:

1. **Members are newly-active queues the metrics package hasn't discovered yet.** The manager force-fetches metrics for every declared group member on each cycle, so this should self-correct within one interval.
2. **Group validation failed and groups were disabled.** Check the log for `Group configuration is invalid — groups disabled until manager restart`. Fix the config (remove duplicate queue names across groups/queues) and restart the manager — the flag is cached for the life of the process.

If neither applies, run `queue:autoscale:debug` for each member queue. If the metrics are all zero but you know jobs are being processed, the metrics package is either not recording pickups or not persisting them.

## Deploy finishes but new config is not applied

The manager is a long-running process — it holds the config in memory. It does not re-read the file.

**Fix:** restart the manager. Your existing deploy script's `php artisan queue:restart` step is enough:

```bash
php artisan queue:restart
```

The manager honors the same signal on its next evaluation tick, gracefully terminates remaining workers, and exits. Your platform supervisor then starts it again from the current release. Alternatively, use `php artisan queue:autoscale:restart` if you run separately-supervised `queue:work` daemons. Use `supervisorctl restart` or `systemctl restart` only as manual fallbacks if the process is wedged.

## Two autoscalers are fighting each other

**Symptoms:** Worker counts bouncing wildly, `queue:autoscale:debug` shows more workers than you configured, logs on two different servers each claim to have spawned/killed the same PID.

**Cause:** Either:

- Two managers are running on the same host/app, which now fails fast unless you explicitly use `queue:autoscale --replace`.
- Multiple hosts are running autoscale without cluster mode enabled.
- Cluster mode is enabled, but two nodes collided onto the same configured `manager_id`.

- Running `queue:autoscale` on multiple web nodes instead of enabling cluster mode.
- A stale manager from a previous deploy that wasn't terminated.
- Docker Swarm/Kubernetes with `replicas: 2` while cluster mode is still disabled.

**Fix:** In single-host mode, run exactly one manager per app. In cluster mode, run exactly one manager per host and let Redis-backed coordination handle the rest. Only set `QUEUE_AUTOSCALE_MANAGER_ID` manually if you need to override the auto-generated node identity.

---

## Still stuck?

- Capture `php artisan queue:autoscale -vvv` for one minute and attach it to your issue.
- Also include `php artisan queue:autoscale:debug --queue=<affected-queue>` output.
- Open a GitHub issue: [cboxdk/laravel-queue-autoscale/issues](https://github.com/cboxdk/laravel-queue-autoscale/issues).
