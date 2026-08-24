---
title: "Facade, Commands and Bindings"
description: "The facade surface, the console commands, and what the service provider binds"
weight: 60
---

## Facade

`Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale` proxies `Cbox\LaravelQueueAutoscale\LaravelQueueAutoscale`, which has exactly two public methods:

```php
readonly class LaravelQueueAutoscale
{
    /** @return array<string, mixed> The Redis cluster summary; [] when cluster mode is off. */
    public function cluster(): array;

    /** @return array<int, array{name: string, value: int|float, labels: array<string, scalar|null>}> */
    public function clusterMetrics(): array;
}
```

There is no runtime API for overriding a queue's bounds or forcing a scale — no `overrideMinWorkers()`, `scaleToCapacity()` or `resetToNormal()`. Use config, or a [scaling policy](../basic-usage/scaling-policies.md).

## Console Commands

| Command | Signature |
|---|---|
| `queue:autoscale` | `{--interval=5} {--replace}` — the daemon. `--interval` is the **only** way to set the evaluation interval. |
| `queue:autoscale:cluster` | `{--json}` — cluster leader, active managers, host capacity, workload targets, host scale signal. |
| `queue:autoscale:debug` | `{--queue=default} {--connection=}` — dump queue state and metrics for diagnosis. |
| `queue:autoscale:install` | `{--topology=} {--metrics-connection=} {--publish-migrations} {--write-env} {--env-file=} {--force} {--no-publish}` — `--topology` is one of `single-low`, `single-redis`, `cluster`. |
| `queue:autoscale:restart` | Signal running managers to restart gracefully. |
| `queue-autoscale:migrate-config` | `{--source=} {--destination=}` — migrates a **v1** config to **v2** shape. Default destination is `config/queue-autoscale.v2.php`; it warns and skips if the source does not look like a v1 config. |

`queue-autoscale:debug-queue` does not exist — the debug command is `queue:autoscale:debug`.

## Service Provider Bindings

Everything binds in `LaravelQueueAutoscaleServiceProvider`. Override by binding before this provider boots:

```php
// AppServiceProvider::register()
$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract::class,
    \App\Autoscale\MyForecaster::class,
);
```

See [Custom Strategies](../advanced-usage/custom-strategies.md) for writing your own implementations.

## Not in this package

Names that appear in older documentation, blog posts or generated code but do **not** exist in v3:

| Name | Reality |
|---|---|
| `ScalingPolicyContract` | The interface is `ScalingPolicy`. |
| `PredictiveStrategy` | Only `HybridStrategy`, `BacklogOnlyStrategy`, `ConservativeStrategy`, `SimpleRateStrategy` ship. |
| `ProfilePresets` (`::balanced()` etc.) | Removed in v3. Use the profile classes. |
| `ResourceConstraintChecker`, `ResourceConstraintPolicy` | Resource limits live in `CapacityCalculator`, applied inside `ScalingEngine`. |
| `ScalingDecision::$confidence` | No confidence value exists anywhere in the package. |
| `WorkerHealthCheckFailed` | No worker-health event exists. the manager's inline liveness check only answers whether a PID is alive. |
| `WorkersScaled::$newCount` | The properties are `from` and `to`. |
| `QueueConfiguration::$minWorkers` / `$maxWorkers` / `$maxPickupTimeSeconds` | Nested: `$config->workers->min`, `$config->workers->max`, `$config->sla->targetSeconds`. |
| `AutoscaleManager::getWorkerCount()` | The manager exposes only `configure()`, `setOutput()`, `setRenderer()` and `run()`. |
| `queue-autoscale:debug-queue` | The command is `queue:autoscale:debug`. |
| Cost/budget config (`cost_limits`, spot-instance keys) | No cost feature exists. |
| `'strategy' => ['class' => ..., 'options' => [...]]` | `strategy` is a plain class string. |
| `trend_weight`, `safety_margin`, `min_trend_samples` | No such config keys. |
| `QUEUE_AUTOSCALE_EVALUATION_INTERVAL` and similar env vars | Not read. The interval is `queue:autoscale --interval=`. |

## See Also

- [Basic Usage](../basic-usage/_index.md) — Implementation guides
- [Advanced Usage](../advanced-usage/_index.md) — Custom strategies and policies
- [Algorithms](../algorithms/_index.md) — Mathematical foundations
- [Queue Topology](../basic-usage/queue-topology.md) — Per-queue vs. groups vs. exclusive vs. excluded
