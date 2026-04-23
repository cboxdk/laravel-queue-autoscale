---
title: "Deployment"
description: "Run the Queue Autoscale manager on self-hosted VPS, Laravel Forge, Ploi, or Docker"
weight: 12
---

# Deployment

The autoscaler manager (`php artisan queue:autoscale`) is a long-running daemon. In single-host mode, run exactly one instance per app. In cluster mode, run exactly one instance per host for that app and let the leader coordinate the cluster.

## Pick your platform

- [Self-Hosted VPS](self-hosted-vps.md) — systemd or Supervisor
- [Laravel Forge](forge.md) — one Daemon entry
- [Ploi](ploi.md) — Daemons panel
- [Docker / Compose](docker.md) — service with signal forwarding

## What they all have in common

- **Single-node or cluster mode.** By default, run one autoscaler. If you enable `queue-autoscale.cluster.enabled`, multiple managers can safely run against the same queues: they auto-join via Redis, elect a leader, and receive per-host worker recommendations.
- **Graceful shutdown.** The manager catches `SIGTERM` and cleanly stops all spawned workers. Your platform should send SIGTERM, wait for `shutdown_timeout_seconds` (default 30), then SIGKILL.
- **Don't double-configure workers.** If your platform has a "queue workers" section (Forge, Ploi) **do not** add entries for queues the autoscaler manages. The autoscaler IS your queue worker supervisor — the platform UI is for `queue:work` daemons that the autoscaler would fight with.
- **Restart on deploy.** When your code changes, the manager must restart to pick up new config. All supported platforms do this automatically as part of the zero-downtime deploy flow, or you can signal a graceful restart with `php artisan queue:autoscale:restart`.

## Logs to keep an eye on

During the first few days after deployment, watch for:

- `Worker spawned` / `Worker terminated gracefully` — the autoscaler is actively managing processes.
- `Failed to spawn worker` — the PHP binary, `artisan`, or `queue:work` cannot launch. Check the paths and the user permissions.
- `Group configuration is invalid — groups disabled until manager restart` — FATAL. Fix config and redeploy.
- Nothing at all — the manager may not be running. `ps aux | grep queue:autoscale` on the host.

See [Cookbook → Alert via Log](../cookbook/alert-via-log.md) for a production-ready log-alerting setup.
