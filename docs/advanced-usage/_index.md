---
title: "Advanced Usage"
description: "Extension points, integration surfaces, production operations and the v2 to v3 upgrade path"
weight: 30
---

# Advanced Usage

This section covers the extension points, the integration surfaces, and the operational side of
running Queue Autoscale in production. It assumes you already have the autoscaler running — start
with [How It Works](../basic-usage/how-it-works.md) and
[Configuration](../basic-usage/configuration.md) if you do not.

## Upgrading

- **[Upgrading to v4](upgrade-guide-v4.md)** - PHP 8.4, the split worker timeouts, the removed
  global `workers` block and the renamed cluster metrics.
- **[Upgrading from v2 to v3](upgrade-guide-v3.md)** - Renamed APIs, the restructured config, and
  what the migration command actually does

## Extensibility

The package has exactly two pluggable seams, plus container bindings for every internal
collaborator.

- **[Custom Strategies](custom-strategies.md)** - Replace the demand calculation. Runs *before* the
  engine and answers "how many workers does this queue need?"
- **[Policy Execution Internals](scaling-policies.md)** - Adjust a finished decision. Runs *after*
  the engine, chained, with error isolation
- **[Integrations & Developer Hooks](integrations.md)** - Facade, cluster JSON snapshot, event
  stream and the telemetry provider

Which seam do you need?

| You want to… | Use |
|---|---|
| Change how the worker count is calculated | A strategy |
| Clamp, floor or veto a calculated target | A policy |
| React to what happened without changing it | An event listener |
| Export state to a dashboard or metrics pipeline | The snapshot, events, or telemetry |

## Production

- **[Production Deployment Reference](deployment.md)** - Prerequisites, supervision, environment
  variables, what the spawned workers actually run, and the operational runbook
- **[Security](security.md)** - Reporting a vulnerability, and the security-relevant behaviour of
  spawning and configuration

## Contributing

- **[Contributing](contributing.md)** - Development setup, coding standards and the quality gate

## Prerequisites

1. Understand [How It Works](../basic-usage/how-it-works.md)
2. Be familiar with the [Configuration](../basic-usage/configuration.md) keys
3. Have the autoscaler running successfully in your environment
