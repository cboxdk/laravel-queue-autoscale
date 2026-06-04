---
title: "Laravel Forge"
description: "Run Queue Autoscale as a Forge Daemon"
weight: 2
---

# Laravel Forge

On Forge, the autoscaler is just another Daemon. It runs like any other Laravel long-running process.

## 1. Add the Daemon

In your Forge site: **Daemons → Add New Daemon**

| Field | Value |
|---|---|
| Command | `php /home/forge/your-app.com/current/artisan queue:autoscale` |
| User | `forge` |
| Directory | `/home/forge/your-app.com/current` |
| Processes | `1` |
| Stop Wait Seconds | `60` |
| Stop Signal | `SIGTERM` |

Click **Create**. Forge starts it immediately.

## 2. Remove any competing Queue Workers

Forge has a separate **Queue Workers** section. **Delete any entry whose queues the autoscaler will manage.** Two worker supervisors on one queue will fight:

- The autoscaler spawns `queue:work --queue=payments`
- Forge's queue worker UI also spawns `queue:work --queue=payments`
- Both claim the same jobs; unpredictable behaviour follows

You can keep Forge queue workers for queues you list in `queue-autoscale.excluded`. That's exactly what the `excluded` config is for — see [Queue Topology](../basic-usage/queue-topology.md#excluded-queues).

## 3. Deploys

Add this after the new release is active and migrations/config-cache steps are done:

```bash
php artisan queue:autoscale:restart
```

The command works like Laravel's `queue:restart`: it writes a cache signal, the running autoscale manager notices it on the next evaluation tick, gracefully stops spawned workers, and exits. Forge's Daemon supervisor then relaunches `php artisan queue:autoscale` from the current release.

For a manual graceful restart, run:

```bash
php artisan queue:autoscale:restart
```

If the manager is wedged and does not exit, use Forge's **Daemons → Restart** button or `sudo supervisorctl restart daemon-<daemon-id>` as an operational fallback.

## 4. Verify

Forge **→ Daemons** shows the process status. Inspect logs via Forge's log viewer, or SSH:

```bash
tail -f /home/forge/.forge/daemon-<id>.log
```

You should see `Autoscale manager started` shortly after deploy, then periodic worker-spawn/terminate activity once jobs arrive.

## Gotchas

- **`.env` changes don't propagate** without a Daemon restart. Forge does this automatically after "Update Secrets" in the UI.
- **PHP version mismatch.** Forge servers usually run multiple PHP versions. Make sure the Daemon command uses the same `php` binary your web layer uses (`php8.3` or similar if you pin a specific version).
- **Long-running manager holds old code until restart.** A fresh deploy that changes scaling thresholds won't take effect until the Daemon restarts. Keep `php artisan queue:autoscale:restart` in the deploy script.
