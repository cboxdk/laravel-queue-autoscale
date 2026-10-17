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
- [Scale-up stopped in a container while memory looks free](#scale-up-stopped-in-a-container-while-memory-looks-free)
- [The container is OOM-killed, or capacity is held after an OOM kill](#the-container-is-oom-killed-or-capacity-is-held-after-an-oom-kill)
- [On SQS, `SlaBreached` never fires](#on-sqs-slabreached-never-fires)
- [`SlaRecovered` fires minutes after the backlog cleared](#slarecovered-fires-minutes-after-the-backlog-cleared)
- [`SlaBreached` fires while the oldest job is young](#slabreached-fires-while-the-oldest-job-is-young)
- [`queue:autoscale:doctor` warns about the pickup sample cap](#queueautoscaledoctor-warns-about-the-pickup-sample-cap)
- [A queue on a `sync` connection is not supervised](#a-queue-on-a-sync-connection-is-not-supervised)

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

`Removed dead worker` carries how the worker ended: `exit_code`, `term_signal`, `termination_requested` and `uptime_seconds`, plus `pid`, `connection`, `queue` and `group`. It is logged at `info` when `queue:work` stopped itself cleanly (exit `0`, or `12` at its `--memory` limit, with no signal) and at `warning` otherwise. The same details are dispatched as the [`WorkerExited` event](event-handling.md#workerexited).

**Reading the exit:**

| `exit_code` | `term_signal` | `termination_requested` | Meaning |
|---|---|---|---|
| `0` | `null` | `true` | Scale-down or shutdown: the manager sent SIGTERM and the worker finished its job. Normal |
| `0` | `null` | `false` | The worker stopped itself: `--max-time` reached, `queue:restart`, or a lost connection. Normal unless `uptime_seconds` is tiny every time |
| `12` | `null` | any | `queue:work` passed its `--memory` limit and stopped after its job. The autoscaler passes no `--memory`, so this is Laravel's default of 128 MB of PHP memory. Normal for memory-hungry jobs; frequent recycling is a sign of a leak |
| `137` | `9` | `false` | SIGKILL the manager did not send: a job ran past `workers.timeout_seconds`, or the kernel's OOM killer |
| `137` | `9` | `true` | The worker ignored SIGTERM past its `workers.shutdown_timeout_seconds` and the manager force-killed it |
| `143` | `15` | any | The process died on SIGTERM instead of handling it, or something else sent SIGTERM |
| `1` or `255` | `null` | `false` | The process crashed: a fatal error, an uncaught exception outside a job, or a failed boot |

A signal-killed process has no exit code of its own; Symfony Process reports `128 + signal`, which is why SIGKILL reads `137`.

**Telling a timeout from an OOM kill** (both are `137` / `9` / not requested):

- A timeout leaves a trace in Laravel before `queue:work` kills itself: the job is marked failed with a `TimeoutExceededException` (`… has timed out.`) once its attempts run out, so it shows in `failed_jobs`, and recent Laravel releases dispatch a `JobTimedOut` event you can log. The worker's `uptime_seconds` is also at least `workers.timeout_seconds`.
- An OOM kill is logged by the kernel: `dmesg | grep -i 'killed process'` on the host, `OOMKilled` in `kubectl describe pod`, or the container's OOM kill counter. In a container the manager also logs `Kernel OOM-killed processes on this host; holding worker capacity at its current count` — see [below](#the-container-is-oom-killed-or-capacity-is-held-after-an-oom-kill).

**Most common causes:**

1. **The `queue:work` invocation fails.** Run the exact command the manager uses, manually, as the same user:

   ```bash
   sudo -u forge php /home/forge/your-app/current/artisan queue:work redis --queue=default
   ```

   The error will be immediate — almost always `.env` permissions, missing Redis connection, or a wrong PHP path.
2. **PHP is running out of memory on each job.** Check `storage/logs/laravel.log` for `Allowed memory size` errors in the spawned workers (exit code `255`). That is PHP's hard `memory_limit`, separate from `queue:work`'s `--memory` (which the autoscaler leaves at Laravel's default of 128 MB, and which only recycles the worker after a job). Raise `memory_limit` in the php.ini the manager's PHP binary uses.
3. **A worker is hitting `workers.max_time_seconds`.** That value is passed as `--max-time`, so the worker process exits after that many seconds of life and is respawned. Default is 3600s. Expected behaviour unless you see it every few seconds. (A job exceeding `workers.timeout_seconds` is a different event — that kills the job, not the worker.)

## Workers keep spawning and terminating (flapping)

**Symptoms:** Logs show continuous `scaled UP` followed by `scaled DOWN` every few evaluation cycles.

**Causes and fixes:**

1. **Arrival rate is genuinely oscillating.** The autoscaler is responding correctly, but it's noisy. Raise `scaling.cooldown_seconds` (default 60) to dampen. Anti-flapping already suppresses a scale-down that reverses a recent scale-up; scale-ups are never held, so raising the window slows how fast the fleet comes back down, not how fast it responds.
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

## Scale-up stopped in a container while memory looks free

**Symptoms:** `-vvv` shows `limitingFactor: memory` and a memory line near zero available, while `kubectl top` or `docker stats` shows the container well under its limit.

1. **Check the scope.** The `-vvv` capacity breakdown prints `Memory Limit: N workers (X.XGB available of Y.YGB (scope), NMB/worker)`. If the scope is `host` inside a container, the manager cannot see your limit and is sizing against the node; set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB`. See [Kubernetes and Amazon ECS](../deployment/kubernetes-ecs.md).
2. **Check `capped by … free memory`.** The node itself (or another scope) has less free memory than your container's headroom. That is the node being crowded, not your container.
3. **Upgrade to 4.4.1 or later.** Earlier releases measured the container by raw cgroup usage, which counts reclaimable page cache. A container writing log files drifted toward its limit and stopped scaling up. 4.4.1 uses the working set, as `kubectl top` does.
4. **Workers are still booting.** For `limits.worker_settle_seconds` (default 30) after a spawn, new workers are charged the memory they have not grown into yet, so headroom right after a scale-up is lower than usage suggests. `memory_details.settling_workers` shows how many.
5. **The per-worker estimate is higher than you think.** On Linux it is floored at what the queue's live workers actually peak at (`VmHWM`, boot included). `memory_details.worker_memory_mb` and `memory_estimate_source` show the value used.

## The container is OOM-killed, or capacity is held after an OOM kill

**Symptoms:** `Kernel OOM-killed processes on this host; holding worker capacity at its current count` in the log, decisions limited by `memory` with `oom_backoff_ceiling` set, or workers exiting with `137` / signal `9`.

The kernel's OOM kill counter for the manager's cgroup rose, so worker capacity is held at the count at that moment for `limits.oom_backoff_seconds` (default 300) instead of refilling the memory the kill freed. That is deliberate. It ends on its own; a further kill restarts it.

To stop the kills rather than the hold:

1. **Make the limit visible.** A pod- or task-level limit the manager cannot see is the most common cause: it sizes against the node. Set `QUEUE_AUTOSCALE_MEMORY_LIMIT_MB`.
2. **Raise `worker_memory_mb_estimate`** towards your workers' real peak, or lower `limits.max_memory_percent` to leave more slack.
3. **Raise `worker_settle_seconds`** if workers take longer than 30 seconds to reach their footprint.
4. **Set PHP's `memory_limit`** so a runaway job dies as a PHP fatal error in one worker rather than an OOM kill.

`QUEUE_AUTOSCALE_OOM_BACKOFF_SECONDS=0` disables the hold. On cgroup v1 the hold needs Linux 4.13 or later and `cboxdk/system-metrics` 3.1 or later; outside Linux cgroups it never triggers.

## On SQS, `SlaBreached` never fires

SQS cannot report the age of its oldest message — it always reads 0 — so on SQS a queue's SLA is judged on its measured pickup time alone. If no pickup samples reach the manager, a queue there is judged on that 0 and never breaches. Samples need both:

- `QUEUE_AUTOSCALE_STAMP_PUSHED_AT=true` — only Horizon writes `pushedAt`, and Horizon does not run on SQS.
- A shared store: `QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis` on a single host (cluster mode already uses Redis).

`php artisan queue:autoscale:doctor` warns `SQS queues have no SLA signal` when either is missing. Check with `php artisan queue:autoscale -vv` while the queue has a backlog: the decision reason should show `p95=…`, not `oldest_age=0.0s`. If it shows `p95` but the queue is under `sla.min_samples` for part of the time, lower `sla.min_samples` for that queue so the window fills at its throughput.

On 4.4 the samples arrived but breach was still decided from the oldest job's age, so `SlaBreached` could not fire on SQS even then; upgrade to 4.5. On 4.3 and earlier, samples on SQS were also recorded under the queue URL, where the manager never looked. Do not include the connection's `suffix` in a queue's name. See [Running on Amazon SQS](../deployment/amazon-sqs.md).

## `SlaRecovered` fires minutes after the backlog cleared

Expected when the queue is judged on its pickup percentile. The percentile covers every pickup in the last `sla.window_seconds`, so the slow pickups from the breach keep it high until they age out of the window or are outnumbered by fast ones. Recovery can trail the backlog by up to one window — 300 seconds by default, 900 on the background profile. Shorten `sla.window_seconds` for faster recovery at the cost of a noisier signal. See [How breach is decided](monitoring.md#how-breach-is-decided).

A breach is also held, not recovered, while the window has fewer than `sla.min_samples` pickups and the oldest-age fallback reads 0 with jobs still pending, on a driver that cannot report that age — on SQS that is what a stalled queue looks like. It recovers once the queue is empty or the percentile is measurable again and under the target. On Redis and the database a 0 is a real age, so it recovers as usual.

## `SlaBreached` fires while the oldest job is young

When a queue has at least `sla.min_samples` pickups in its window, breach is judged on the pickup percentile — the number the strategy scales on. A p95 over the target is a breach however young the head of the queue is. An old head job alone is not: on Redis a delayed job or a retry is aged from dispatch. The exception is a stall: with no pickup recorded for `sla.target_seconds` while jobs wait, the head job's wait counts, so a stalled queue breaches about one target after its last pickup. On a quiet queue a delayed job that has just become due can read as a breach for one cycle, until a worker picks it up. Read `$event->slaSignal` (or `signalSeconds()`) to see the number a breach was decided on; `oldestJobAge` is only the driver's raw reading.

## `queue:autoscale:doctor` warns about the pickup sample cap

`The pickup sample cap hides SLA breaches` means `pickup_time.max_sample_seconds` is at or below some queue's `sla.target_seconds`. Samples above the cap are dropped, so a breach is never measured and the queue always reads as healthy. Raise `QUEUE_AUTOSCALE_PICKUP_MAX_SAMPLE_SECONDS` to several times your largest SLA target, or set it to empty to keep every sample. See [Check Your Configuration](configuration-check.md) for every warning the doctor reports.

## A queue on a `sync` connection is not supervised

Queues on `sync`, `null`, `deferred` and `background` connections are skipped by discovery, in the manager and in `queue:autoscale:doctor`. Those drivers run jobs in the dispatching process or discard them, so no `queue:work` process could ever pop from them, but they still fire queue events and so appear in the metrics package. A custom driver registered with `Queue::extend()` is judged by the built-in queue its queue class extends, so a subclass of `BackgroundQueue` is skipped and a subclass of `SqsQueue` is treated as SQS. If a queue you expect to see is missing, check that its connection uses a real queue driver.

---

## Still stuck?

- Capture `php artisan queue:autoscale -vvv` for one minute and attach it to your issue.
- Also include `php artisan queue:autoscale:debug --queue=<affected-queue>` output.
- Open a GitHub issue: [cboxdk/laravel-queue-autoscale/issues](https://github.com/cboxdk/laravel-queue-autoscale/issues).
