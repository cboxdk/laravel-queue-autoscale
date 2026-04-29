---
title: "Requirements"
description: "System requirements and prerequisites for Queue Autoscale for Laravel"
weight: 2
---

# Requirements

Before installing Queue Autoscale for Laravel, ensure your environment meets these prerequisites.

## Runtime

| Requirement | Version |
|---|---|
| **PHP** | 8.3, 8.4, or 8.5 |
| **Laravel** | 11.0+, 12.0+, or 13.0+ |
| **Composer** | Latest version recommended |

## PHP Extensions

No additional PHP extensions are required beyond what Laravel itself needs. If you enable cluster mode, the `phpredis` extension or `predis/predis` package is required for Redis connectivity.

## Package Dependencies

These are installed automatically via Composer:

| Package | Version | Purpose |
|---|---|---|
| `cboxdk/laravel-queue-metrics` | ^3.0 | Queue discovery and metrics collection |
| `cboxdk/system-metrics` | ^3.0 (via queue-metrics) | CPU and memory monitoring for resource-aware scaling |
| `symfony/process` | ^7.0 \| ^8.0 | Worker process spawning and management |
| `spatie/laravel-package-tools` | ^1.16 | Service provider conventions |

## Infrastructure

### Redis (optional)

Redis is **not required** for single-host autoscaling. The package works with any Laravel-supported queue driver (`database`, `sqs`, `redis`, etc.).

Redis **is required** when:

- **Cluster mode** is enabled (multi-host coordination, leader election, heartbeats)
- You want **Redis-backed predictive signals** on a single host (pickup-time percentiles, spawn-latency tracking)

See [Installation](installation.md) for deployment shape options.

### Process Supervisor (production)

In production, use **Supervisor** or **systemd** to keep the `php artisan queue:autoscale` manager process running and to restart it on failure. The autoscale manager replaces manual `queue:work` process management — you do not need Supervisor entries for individual queue workers.

See [Deployment Guides](deployment-guides/docker.md) for platform-specific setup.

## SLA Timing Floor

The autoscaler operates within the timing constraints of Laravel's queue worker internals. There is a hard floor on how fast jobs can be picked up:

**~3-5 seconds minimum pickup time** — even with a running worker, Laravel's `queue:work` command has an internal sleep/poll loop (`sleep_seconds`, default 3s) plus job pickup overhead. SLA targets below 5 seconds will produce flaky breach events. This is expected behaviour, not a bug.

**~8-12 seconds from zero** — profiles with `workers.min = 0` add the evaluation interval (default 5s) and worker spawn latency on top of the poll floor.

Setting `sla.target_seconds` below 5 is not recommended. See [Understanding SLA Timing](basic-usage/how-it-works.md#understanding-sla-timing) for the full explanation.

## Next Steps

Ready to install? Follow the [Installation](installation.md) guide.
