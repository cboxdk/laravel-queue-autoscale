---
title: "Ploi"
description: "Run Queue Autoscale as a Ploi Daemon"
weight: 3
---

# Ploi

On Ploi, the autoscaler runs as a Daemon. The setup mirrors Forge — Ploi uses Supervisor under the hood.

## 1. Add the Daemon

In your Ploi site: **Daemons → Add Daemon**

| Field | Value |
|---|---|
| Command | `php /home/ploi/your-app.com/current/artisan queue:autoscale` |
| User | `ploi` |
| Directory | `/home/ploi/your-app.com/current` |
| Processes | `1` |

Click **Create**.

If Ploi exposes advanced Supervisor options in your panel version, also set:

- `stopwaitsecs` = `60`
- `stopsignal` = `TERM`

## 2. Remove competing Queue Workers

Ploi's **Queue Workers** panel configures separate `queue:work` daemons. **Delete any worker whose queue the autoscaler manages**, or declare those queues in `queue-autoscale.excluded` so the autoscaler ignores them.

See [Queue Topology → Excluded Queues](../basic-usage/queue-topology.md#excluded-queues).

## 3. Deploys

Ploi's deploy script restarts daemons automatically when the deploy hook runs `sudo -S supervisorctl restart`. If your custom deploy script doesn't do this, add:

```bash
sudo -S supervisorctl restart daemon-<id>
# or, if your deploy hook only has Artisan access:
php artisan queue:autoscale:restart
```

Find the daemon ID in the Ploi UI or via `supervisorctl status`.

## 4. Verify

In Ploi: **Daemons → View Logs**. First line should be something like:

```
[2026-04-17 12:34:56] local.INFO: Autoscale manager started
```

If you see a stack trace, 9 times out of 10 it's one of: missing Redis connection, missing `laravel-queue-metrics` publish, or a config cache from before the autoscaler was installed (`php artisan config:clear`).

## Ploi-specific notes

- **Server Cron.** If you use Ploi's scheduled tasks, none of them are needed for the autoscaler — the manager is event-loop driven, not cron-driven.
- **Multiple sites on one server.** Each site gets its own Daemon. Don't share one autoscaler across sites — the config is per-app.
- **Ploi's built-in uptime monitor** will alert you if the Daemon goes down unexpectedly. That's a useful backstop in addition to in-app SLA alerts.
