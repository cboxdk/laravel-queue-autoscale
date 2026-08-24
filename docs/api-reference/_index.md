---
title: "API Reference"
description: "Contracts, value objects, events and shipped implementations for Queue Autoscale for Laravel"
weight: 71
---

# API Reference

# API Reference

The public surface of Queue Autoscale for Laravel. For conceptual guidance see [Basic Usage](../basic-usage/_index.md); for the algorithms themselves see [Algorithms](../algorithms/_index.md). This page is strictly about types and signatures — your editor's go-to-definition will take you the rest of the way.

## Sections

- **[Contracts](contracts.md)** — the twelve interfaces bound in the service provider.
  Implement one of these to replace a capability; depend on them rather than on the
  concrete classes.
- **[Configuration Value Objects](configuration.md)** — what `config/queue-autoscale.php`
  is parsed into, field by field.
- **[Scaling Decision](scaling-decision.md)** — what a strategy returns, what a policy
  may change, and how cluster scope works.
- **[Events](events.md)** — every event dispatched, plus the alerting surface.
- **[Workers](workers.md)** — the pool, spawner, terminator and scaler.
- **[Facade, Commands and Bindings](wiring.md)** — the facade, the console commands,
  what the provider binds, and what this package deliberately does not do.
