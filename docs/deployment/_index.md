---
title: "Deployment"
description: "Run the Queue Autoscale manager on self-hosted VPS, Laravel Forge, Ploi, or Docker"
weight: 12
---

# Deployment

The autoscaler manager (`php artisan queue:autoscale`) is a long-running daemon. Run exactly one instance per app, let your platform handle restarts on deploy.

## Pick your platform

- [Self-Hosted VPS](self-hosted-vps.md) — systemd or Supervisor
- [Laravel Forge](forge.md) — one Daemon entry
- [Ploi](ploi.md) — Daemons panel
- [Docker / Compose](docker.md) — service with signal forwarding

## What they all have in common

- **One instance.** Running two autoscalers against the same queues will double-spawn workers. If you horizontally scale your web layer, the autoscaler belongs on one dedicated node or a leader-elected container, not every web node.
- **Graceful shutdown.** The manager catches `SIGTERM` and cleanly stops all spawned workers. Your platform should send SIGTERM, wait for `shutdown_timeout_seconds` (default 30), then SIGKILL.
- **Don't double-configure workers.** If your platform has a "queue workers" section (Forge, Ploi) **do not** add entries for queues the autoscaler manages. The autoscaler IS your queue worker supervisor — the platform UI is for `queue:work` daemons that the autoscaler would fight with.
- **Restart on deploy.** When your code changes, the manager must restart to pick up new config. All supported platforms do this automatically as part of the zero-downtime deploy flow.

## Logs to keep an eye on

During the first few days after deployment, watch for:

- `Worker spawned` / `Worker terminated gracefully` — the autoscaler is actively managing processes.
- `Failed to spawn worker` — the PHP binary, `artisan`, or `queue:work` cannot launch. Check the paths and the user permissions.
- `Group configuration is invalid — groups disabled until manager restart` — FATAL. Fix config and redeploy.
- Nothing at all — the manager may not be running. `ps aux | grep queue:autoscale` on the host.

See [Cookbook → Alert via Log](../cookbook/alert-via-log.md) for a production-ready log-alerting setup.
