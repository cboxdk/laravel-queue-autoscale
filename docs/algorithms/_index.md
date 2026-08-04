---
title: "Algorithms"
description: "The real formulas behind the autoscaler: Little's Law, backlog drain, forecasting and capacity limits"
weight: 50
---

# Algorithms

Deep dives into the calculations `HybridStrategy` and `ScalingEngine` actually run, with the source
file named for every formula.

## The target worker count

The default strategy takes the maximum of **two** calculations:

```text
targetWorkers = max(
    steadyStateWorkers,     # Little's Law:  arrivalRate x avgJobTime
    backlogDrainWorkers     # SLA protection: backlog / timeUntilBreach, x aggressiveness
)

targetWorkers = max(workers.min, min(workers.max, ceil(targetWorkers)))
targetWorkers = TargetSmoother::smooth(...)
```

- **[Little's Law](littles-law.md)** — the steady-state term. `L = lambda x W`, where lambda is the
  estimated arrival rate and W is the average job duration.
- **[Backlog Drain](backlog-drain.md)** — the SLA term. Abstains below
  `scaling.breach_threshold` (default 50% of the SLA window), then scales with a progressive
  aggressiveness multiplier that reaches 3.0x at the SLA line and caps at 5.0x.

Forecasting is **not** a third term:

- **[Trend Prediction](trend-prediction.md)** — linear-regression forecasting blended into the
  arrival rate that feeds Little's Law, gated by a per-queue forecast policy.

## Constraints on the target

Once the strategy has produced a number, it can only be reduced (or raised to `workers.min`):

- **[Resource Constraints](resource-constraints.md)** — CPU and memory capacity, per-worker resource
  estimates, and the per-queue share of a host-wide ceiling.

## The whole pipeline

- **[Architecture](architecture.md)** — signals, the decision pipeline in execution order, the
  failure fuse, the anti-flapping cooldown, worker lifecycle and extension points.

## Which calculation dominates

| Situation | Term that wins |
|---|---|
| Steady arrival rate, no aged backlog | Little's Law |
| Arrival rate climbing, clean trend | Little's Law with a forecast-blended rate |
| Backlog aged past `scaling.breach_threshold` | Backlog drain |
| Oldest job at or past the SLA | Backlog drain, multiplied 3.0x–5.0x |
| Host near `limits.max_cpu_percent` / `max_memory_percent` | Neither — capacity caps the result |
| Downstream failing | Neither — the failure fuse holds at `workers.min` |

## Further reading

- [How It Works](../basic-usage/how-it-works.md) — the same pipeline without the mathematics
- [Custom Strategies](../advanced-usage/custom-strategies.md) — implementing `ScalingStrategyContract`
- [Scaling Policies](../advanced-usage/scaling-policies.md) — modifying decisions after the strategy
