---
title: "Policy Execution Internals"
description: "How PolicyExecutor resolves, chains and isolates scaling policies, and exactly what the four shipped policies do"
weight: 31
---

# Policy Execution Internals

This page documents the machinery around `Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy`:
where policies run in the evaluation pipeline, how they are resolved and chained, what happens when
one throws, and the exact arithmetic inside the four shipped policies.

For the introduction — what a policy is, when to reach for one, and how to write your first
one — start with [Scaling Policies](../basic-usage/scaling-policies.md).

## The contract

```php
namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

interface ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision;

    public function afterScaling(ScalingDecision $decision): void;
}
```

Both hooks receive a `ScalingDecision` and nothing else. There is no metrics object and no
`QueueConfiguration` in the signature — everything a policy can react to must be reachable from the
decision itself:

| Property | Type | Notes |
|---|---|---|
| `connection` | `string` | |
| `queue` | `string` | Group decisions carry the group name here |
| `currentWorkers` | `int` | |
| `targetWorkers` | `int` | Already clamped by capacity, config bounds and the fuse |
| `reason` | `string` | Composed by the engine from the strategy and fuse |
| `predictedPickupTime` | `?float` | `ScalingStrategyContract::getLastPrediction()` |
| `slaTarget` | `int` | `sla.target_seconds`, default `30` |
| `capacity` | `?CapacityCalculationResult` | |
| `spawnCompensation` | `?SpawnCompensationConfiguration` | |

Helper methods: `shouldScaleUp()`, `shouldScaleDown()`, `shouldHold()`, `workersToAdd()`,
`workersToRemove()`, `action()` (`'scale_up' | 'scale_down' | 'hold'`) and `isSlaBreachRisk()`
(`predictedPickupTime > slaTarget`).

`ScalingDecision` is `readonly`. A policy never mutates a decision — it returns a **new** one, or
`null` to leave the incoming decision untouched.

## Where policies run

Policies run **after** the strategy and the engine, on a finished `ScalingDecision`. They do not
feed the calculation; they adjust its result.

```text
metrics (QueueMetricsData)
  └─ ScalingStrategyContract::calculateTargetWorkers()   ← the demand calculation
       └─ ScalingEngine::evaluate()
            ├─ min(target, this queue's share of system capacity)
            ├─ clamp to [workers.min, workers.max]
            └─ apply failure-fuse ceiling
                 └─ ScalingDecision
                      ├─ PolicyExecutor::beforeScaling()  ← policies, chained
                      ├─ spawn / terminate workers
                      ├─ PolicyExecutor::afterScaling()
                      └─ ScalingDecisionMade / SlaBreachPredicted events
```

Two consequences worth internalising:

- **The anti-flapping cooldown runs before the policies.** `AutoscaleManager::evaluateQueue()`
  returns early when a decision would reverse direction inside
  `queue-autoscale.scaling.cooldown_seconds`, so on a suppressed cycle neither hook is called at all.
- **`ScalingDecisionMade` and `SlaBreachPredicted` carry the post-policy decision.** They are
  dispatched after `afterScaling()`, so listeners see what a policy actually produced.

## Registration and resolution

Policies are configured as a list of **class strings** in `config/queue-autoscale.php`:

```php
'policies' => [
    \Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy::class,
    \Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy::class,
    \App\Autoscale\Policies\BusinessHoursPolicy::class,
],
```

`AutoscaleConfiguration::policyClasses()` maps every entry through
`is_string($policy) && class_exists($policy)` and drops everything else. That has one sharp edge:

```php
// ❌ Silently ignored — never constructed, never called, no warning is logged.
'policies' => [
    new \App\Autoscale\Policies\SlackNotificationPolicy('https://hooks.slack.com/...'),
    fn (ScalingDecision $decision) => $decision,
],
```

A pre-built **instance**, a closure, or a class string that does not autoload is filtered out
before `PolicyExecutor` ever sees it. Only class strings work.

Each surviving class string is resolved with `app($class)`, so constructor injection works — that is
how `NoScaleDownPolicy` receives its `CapacityCalculator`. Pass your own configuration by binding the
policy in a service provider:

```php
// AppServiceProvider::register()
$this->app->bind(\App\Autoscale\Policies\SlackNotificationPolicy::class, function (): object {
    return new \App\Autoscale\Policies\SlackNotificationPolicy(
        webhookUrl: config('services.slack.autoscale_webhook'),
        minWorkerChange: 5,
    );
});
```

If a resolved object does not implement `ScalingPolicy` it is skipped and a warning is written to
`AutoscaleConfiguration::logChannel()` (`queue-autoscale.manager.log_channel`, default `stack`).

`PolicyExecutor` is registered as a container **singleton** and loads its policy list once, in its
constructor. Changing `queue-autoscale.policies` at runtime has no effect until the manager restarts.

## Chaining semantics

`PolicyExecutor::beforeScaling()` threads one decision through every policy in configuration order:

```php
$currentDecision = $decision;

foreach ($this->policies as $policy) {
    $modifiedDecision = $policy->beforeScaling($currentDecision);

    if ($modifiedDecision !== null) {
        $currentDecision = $modifiedDecision;
    }
}

return $currentDecision;
```

- Returning `null` means "no opinion" — the chain continues with the decision unchanged.
- Returning a `ScalingDecision` replaces the working decision for **every later policy** and for the
  scaling action itself.
- Order therefore matters. A policy that widens a scale-down placed after one that narrows it wins,
  which is exactly how `AggressiveScaleDownPolicy` is meant to override
  `ConservativeScaleDownPolicy`.

`afterScaling()` is different: it is a plain fan-out. Every policy receives the same final decision,
return values are ignored, and nothing can be changed at that point.

### Rebuilding a decision correctly

The constructor takes named arguments and defaults the last four. Anything you do not copy across is
silently reset — most commonly `capacity` and `spawnCompensation`, and dropping the latter means the
spawn path falls back to `QueueConfiguration::fromConfig()` to recover it.

```php
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

final class MinimumDuringBusinessHoursPolicy implements ScalingPolicy
{
    public function __construct(private readonly int $floor = 5) {}

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        $hour = now()->hour;

        if ($hour < 9 || $hour > 17 || $decision->targetWorkers >= $this->floor) {
            return null;
        }

        return new ScalingDecision(
            connection: $decision->connection,
            queue: $decision->queue,
            currentWorkers: $decision->currentWorkers,
            targetWorkers: $this->floor,
            reason: sprintf(
                'BusinessHoursPolicy raised target to %d (original: %s)',
                $this->floor,
                $decision->reason,
            ),
            predictedPickupTime: $decision->predictedPickupTime,
            slaTarget: $decision->slaTarget,
            capacity: $decision->capacity,
            spawnCompensation: $decision->spawnCompensation,
        );
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No side effects.
    }
}
```

## Error isolation

Both hooks are wrapped per policy:

```php
try {
    $modifiedDecision = $policy->beforeScaling($currentDecision);
    // ...
} catch (\Throwable $e) {
    Log::channel(AutoscaleConfiguration::logChannel())->error('Policy beforeScaling failed', [
        'policy' => get_class($policy),
        'error' => $e->getMessage(),
    ]);
}
```

A throwing policy is logged and skipped. The chain continues with the last good decision, the
remaining policies still run, and scaling proceeds. You never need a `try`/`catch` inside a policy to
protect the autoscaler — add one only when you want a narrower log line or a fallback value.

The corollary: a policy that throws on every cycle is invisible unless you watch the configured log
channel. `grep 'Policy beforeScaling failed'` (and `afterScaling`) belongs in your log alerting.

## Policies override the safety clamps

The engine clamps to capacity, then to `[workers.min, workers.max]`, then to the fuse ceiling — and
then hands the decision to the policies. **Nothing re-clamps afterwards.** `scaleUp()` spawns
`workersToAdd()` processes for whatever target comes back.

So a policy returning `targetWorkers: 500` will spawn towards 500 regardless of `workers.max`, the
CPU/memory ceiling, and a tripped fuse. If your policy raises a target, clamp it yourself:

```php
$config = \Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration::fromConfig(
    $decision->connection,
    $decision->queue,
);

$target = min(max($proposed, $config->workers->min), $config->workers->max);
```

This matters most for the failure fuse. When the fuse is open the engine has already pinned the
target down to the fuse ceiling and set `$decision->capacity->limitingFactor === 'fuse'`. A policy
that unconditionally raises the target will scale workers into the downstream outage the fuse was
protecting you from. Check the limiting factor before overriding:

```php
if ($decision->capacity?->limitingFactor === 'fuse') {
    return null; // Leave a fuse-limited decision alone.
}
```

### Reading `$decision->capacity`

`CapacityCalculationResult` on a decision reports:

| Field | Meaning |
|---|---|
| `maxWorkersByCpu` | Host CPU ceiling from `CapacityCalculator` |
| `maxWorkersByMemory` | Host memory ceiling from `CapacityCalculator` |
| `maxWorkersByConfig` | `workers.max` for this queue |
| `finalMaxWorkers` | The engine's final target — **the same number as `targetWorkers`**, not the raw system maximum |
| `limitingFactor` | `'config' \| 'strategy' \| 'cpu' \| 'memory' \| 'balanced' \| 'fuse' \| 'system_metrics_unavailable'` |
| `details` | Raw sample values used for the calculation |

If you need the untouched host ceiling rather than the final target, resolve
`CapacityCalculator` yourself — see `NoScaleDownPolicy` below.

## The shipped policies

Defaults in `config/queue-autoscale.php` are `ConservativeScaleDownPolicy` followed by
`BreachNotificationPolicy`.

### ConservativeScaleDownPolicy

Ignores anything that is not a scale-down. For scale-downs it computes

```php
$maxRemovable = max(1, (int) ceil($decision->currentWorkers * 0.25));
```

— **25% of the current worker count, with a floor of 1** — and if the decision removes more than
that, rebuilds it with `targetWorkers: currentWorkers - $maxRemovable`. Otherwise it returns `null`.

Convergence towards an idle target is therefore geometric, not one worker per cycle. Starting from
40 workers with a strategy target of 0:

```text
40 → 30 → 22 → 16 → 12 → 9 → 6 → 4 → 3 → 2 → 1 → 0
```

The tail is where the `max(1, ...)` floor takes over: below 4 workers, 25% rounds up to exactly one
worker per cycle.

The rebuilt decision copies `predictedPickupTime` and `slaTarget` but **not** `capacity` or
`spawnCompensation`, so a decision modified by this policy reaches later policies with
`capacity === null`. Guard with `$decision->capacity?->` when you place a policy after it.

`afterScaling()` does nothing.

### AggressiveScaleDownPolicy

Also scale-down only. It forces the strategy's exact target when both of these hold:

- `predictedPickupTime` is `null` or `0.0` (nothing is waiting), and
- `targetWorkers <= 1`.

In that case it returns a decision with `targetWorkers` unchanged from the incoming value but a new
reason — the point being that it **replaces** a target that an earlier `ConservativeScaleDownPolicy`
narrowed. In every other case it returns `null`, which is what lets a full-size scale-down through
when Conservative is not in the list.

That is why the class is designed to sit **after** Conservative rather than instead of being its
peer, and why listing it alone is the normal configuration:

```php
'policies' => [
    \Cbox\LaravelQueueAutoscale\Policies\AggressiveScaleDownPolicy::class,
    \Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy::class,
],
```

It copies `capacity` onto the decision it returns. `afterScaling()` does nothing.

### NoScaleDownPolicy

Blocks scale-down by returning a decision with `targetWorkers: $decision->currentWorkers`, with one
deliberate exception. It receives a `CapacityCalculator` through constructor injection and asks it
for the host ceiling on every scale-down:

```php
$capacityResult = $this->capacity->calculateMaxWorkers(
    $decision->currentWorkers,
    ResourceEstimate::globalDefault(),
);

if ($decision->currentWorkers > $capacityResult->finalMaxWorkers) {
    return null; // Resource-forced scale-down proceeds.
}
```

If the host can no longer support the workers that are already running, the scale-down is let
through to keep the machine stable. Everything else is held.

Note that it evaluates capacity with `ResourceEstimate::globalDefault()` — the global
`limits.worker_cpu_core_estimate` / `limits.worker_memory_mb_estimate` values — not any per-queue
`resources` override.

Because `CapacityCalculator` caches system metrics for a few seconds, this adds no measurable cost
per cycle. It is not registered by default; add the class string to `policies` to enable it.

### BreachNotificationPolicy

`beforeScaling()` always returns `null` — this policy never modifies a decision. All of its work is
in `afterScaling()`:

- When `$decision->isSlaBreachRisk()` is true it logs `SLA BREACH RISK DETECTED` at **warning**
  level with connection, queue, predicted pickup time, SLA target, current/target workers and reason.
- When `predictedPickupTime !== null` and `predictedPickupTime / slaTarget >= 0.90` it logs
  `High SLA utilization: NN.N%` at **notice** level.

Both are gated through `AlertRateLimiter` on keys `breach_risk:{connection}:{queue}` and
`high_util:{connection}:{queue}`, using `queue-autoscale.alerting.cooldown_seconds` (default `300`).
Without that gate a persistent breach would log on every evaluation cycle; with it you get at most
one line per queue per condition per cooldown window.

Everything goes to `AutoscaleConfiguration::logChannel()`.

To add your own delivery channel, **write a second policy alongside it** rather than extending it —
inheriting from a shipped class couples you to its internals, and its `AlertRateLimiter` is a
private promoted property a subclass could not reuse anyway. The reusable piece is
`AlertRateLimiter` itself: `allow(string $key)` returns `false` while the key is still inside its
cooldown, backed by an atomic `Cache::lock`, so it is safe across processes and hosts.

```php
use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Http;

final readonly class SlackBreachNotificationPolicy implements ScalingPolicy
{
    public function __construct(
        private AlertRateLimiter $limiter = new AlertRateLimiter(cooldownSeconds: 900),
    ) {}

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        if (! $decision->isSlaBreachRisk()) {
            return;
        }

        if (! $this->limiter->allow("slack:breach:{$decision->connection}:{$decision->queue}")) {
            return;
        }

        Http::timeout(5)->post(config('services.slack.autoscale_webhook'), [
            'text' => sprintf(
                '%s:%s predicted pickup %.1fs exceeds SLA %ds',
                $decision->connection,
                $decision->queue,
                $decision->predictedPickupTime ?? 0.0,
                $decision->slaTarget,
            ),
        ]);
    }
}
```

```php
'policies' => [
    \Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy::class,
    \Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy::class,
    \App\Autoscale\Policies\SlackBreachNotificationPolicy::class,
],
```

Both policies then run in `afterScaling()`, each with its own cooldown. Alternatively, skip the
policy layer entirely and listen for `SlaBreachPredicted` — see
[Event Handling](../basic-usage/event-handling.md).

## Testing a policy

Policies are plain objects over a readonly DTO, so they unit-test without the container:

```php
use App\Autoscale\Policies\MinimumDuringBusinessHoursPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

it('raises a low target during business hours', function (): void {
    $this->travelTo(now()->setTime(10, 0));

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 2,
        targetWorkers: 2,
        reason: 'steady state',
        predictedPickupTime: 4.0,
        slaTarget: 30,
    );

    $result = (new MinimumDuringBusinessHoursPolicy(floor: 5))->beforeScaling($decision);

    expect($result)->not->toBeNull()
        ->and($result->targetWorkers)->toBe(5)
        ->and($result->reason)->toContain('BusinessHoursPolicy');
});
```

To exercise the chain, resolve the executor after setting the config:

```php
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

it('limits scale-down to 25 percent of current workers', function (): void {
    config()->set('queue-autoscale.policies', [ConservativeScaleDownPolicy::class]);

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 40,
        targetWorkers: 4,
        reason: 'queue drained',
    );

    $final = app(PolicyExecutor::class)->beforeScaling($decision);

    expect($final->targetWorkers)->toBe(30);
});
```

`PolicyExecutor` is a singleton that reads the policy list in its constructor, so set the config
**before** the first `app(PolicyExecutor::class)` call in the test (or call
`app()->forgetInstance(PolicyExecutor::class)`).

## See Also

- [Scaling Policies](../basic-usage/scaling-policies.md) - Introduction and the shipped policy catalogue
- [Custom Strategies](custom-strategies.md) - The other extension point, running before the engine
- [Failure Fuse](../basic-usage/failure-fuse.md) - What the fuse ceiling protects
- [Event Handling](../basic-usage/event-handling.md) - Reacting to decisions without a policy
- [Architecture](../algorithms/architecture.md) - The full evaluation pipeline
