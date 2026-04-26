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

Forge's default zero-downtime deploy script already restarts Daemons via `sudo -S supervisorctl restart`, which sends SIGTERM. The autoscaler catches it, gracefully stops spawned workers, and Forge relaunches the Daemon with new code. No extra steps needed.

If you want to restart manually:

```bash
# Forge → Daemons → Restart button
# or via SSH:
sudo supervisorctl restart daemon-<daemon-id>
# or inside a deploy hook without sudo:
php artisan queue:autoscale:restart
```

## 4. Verify

Forge **→ Daemons** shows the process status. Inspect logs via Forge's log viewer, or SSH:

```bash
tail -f /home/forge/.forge/daemon-<id>.log
```

You should see `Autoscale manager started` shortly after deploy, then periodic worker-spawn/terminate activity once jobs arrive.

## Gotchas

- **`.env` changes don't propagate** without a Daemon restart. Forge does this automatically after "Update Secrets" in the UI.
- **PHP version mismatch.** Forge servers usually run multiple PHP versions. Make sure the Daemon command uses the same `php` binary your web layer uses (`php8.3` or similar if you pin a specific version).
- **Long-running manager holds old code until restart.** A fresh deploy that changes scaling thresholds won't take effect until the Daemon restarts — which happens automatically on zero-downtime deploy, but not if you skipped that step.
