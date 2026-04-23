---
title: "Basic Usage"
description: "Essential guides for getting started with Queue Autoscale for Laravel"
weight: 10
---

# Basic Usage

Day-to-day usage of Queue Autoscale for Laravel, from install to alerting.

## Start here (in this order)

1. [Installation](../installation.md) — install and publish config
2. [Quick Start](../quickstart.md) — get one queue autoscaled in 5 minutes
3. [Queue Topology](queue-topology.md) — when to use per-queue vs. groups vs. exclusive vs. excluded
4. [Configuration](configuration.md) — the full config reference (only when you outgrow the defaults)

## Most-used recipes

- [Alerting via Log / Slack / Email](../cookbook/_index.md) — paste-and-go event listeners
- [Event Handling](event-handling.md) — the full list of events the autoscaler emits
- [Workload Profiles](workload-profiles.md) — the five shipped profiles + when to pick each

## Running in production

- [Deployment](../deployment/_index.md) — platform-specific guides (self-hosted VPS, Forge, Ploi, Docker)
- [Monitoring](monitoring.md) — what to watch, what's normal
- [Cluster Scaling](cluster-scaling.md) — run multiple autoscale managers with Redis-backed coordination
- [Troubleshooting](troubleshooting.md) — symptom → diagnosis → fix
- [Performance](performance.md) — tuning knobs that matter

## Going deeper

- [How It Works](how-it-works.md) — the hybrid predictive algorithm explained
- [Integrations & Developer Hooks](../advanced-usage/integrations.md) — facade APIs, cluster JSON, and lifecycle events for monitor packages
- [Custom Strategies](../advanced-usage/custom-strategies.md) — replace any algorithm with your own
- [Scaling Policies](../advanced-usage/scaling-policies.md) — cross-cutting concerns
- [Algorithms](../algorithms/_index.md) — the math behind Little's Law, backlog drain, forecasting
