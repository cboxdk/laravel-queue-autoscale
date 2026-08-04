---
title: "Scaling Policies"
description: "The four shipped scaling policies, what each one actually does, and how to write your own"
weight: 17
---

# Scaling Policies

Scaling policies are behaviour modifiers that run on a finished `ScalingDecision`, before and after the scaling action. They let you customise what the autoscaler does without replacing the scaling algorithm.

## Quick Reference

| Policy | Effect | Use Case | Default |
|--------|--------|----------|---------|
| **ConservativeScaleDownPolicy** | Caps scale-down at `max(1, ceil(currentWorkers × 0.25))` per cycle | Prevent thrashing | ✅ Yes |
| **AggressiveScaleDownPolicy** | Forces the exact target when the queue is idle and the target is ≤ 1; otherwise passes through | Rapid stand-down | No |
| **NoScaleDownPolicy** | Blocks scale-down, except when the host is over capacity | Critical workloads | No |
| **BreachNotificationPolicy** | Logs SLA breach risk and high SLA utilisation, rate-limited | Monitoring | ✅ Yes |

All four implement `Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy`.

## How Policies Work

Policies execute in two phases:

1. **Before Scaling** (`beforeScaling`): Can modify the scaling decision
2. **After Scaling** (`afterScaling`): Can perform side effects (logging, alerts, etc.)

### Execution Flow

Policies run **after** the engine has already produced a decision. They never run before the strategy, and they never see the queue metrics.

```text
1. Strategy calculates a target
2. Host capacity clamps it
3. workers.min / workers.max clamp it
4. Failure fuse clamps it
5. ScalingDecision is built
6. PolicyExecutor::beforeScaling(decision)   ← policies may replace the decision
7. Scaling action performed (spawn / terminate / nothing)
8. PolicyExecutor::afterScaling(finalDecision)
9. Events dispatched, carrying the post-policy decision
```

A policy that throws is caught by `PolicyExecutor`, logged to `manager.log_channel`, and skipped — scaling continues with the decision as it stood.

### Policy Chaining

Multiple policies execute in order. Each policy receives the potentially modified decision from previous policies:

```php
'policies' => [
    ConservativeScaleDownPolicy::class,   // Runs first
    BreachNotificationPolicy::class,      // Runs second (sees result of first)
],
```

Entries must be **class strings**. `PolicyExecutor` filters the array with `is_string($policy) && class_exists($policy)`, so a policy *instance* or a closure placed here is silently dropped. Classes are resolved through `app()`, so constructor injection works — that is how `NoScaleDownPolicy` receives its `CapacityCalculator`.

## Policy Deep Dive

### ConservativeScaleDownPolicy

**Caps how many workers a single cycle may remove at 25% of the current count, minimum 1.**

```php
$maxRemovable = max(1, (int) ceil($decision->currentWorkers * 0.25));
```

If the decision asks to remove more than that, the policy returns a replacement decision with `targetWorkers = currentWorkers - $maxRemovable` and a reason that records the clamp. If the decision asks to remove fewer, or is not a scale-down at all, it returns `null` and the decision passes through untouched.

> The class docblock in `src/` still says "1 worker per evaluation cycle". That is stale — read the `beforeScaling()` body: the limit is proportional.

#### Configuration

```php
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;

'policies' => [
    ConservativeScaleDownPolicy::class,
],
```

#### How It Works

**Without the policy:**
```text
Cycle 1: 10 workers → queue empties → scale to 2 workers (-8)
Cycle 2: New jobs arrive → scale to 9 workers (+7)
Cycle 3: Jobs complete → scale to 3 workers (-6)
Result: thrashing, wasted spawn cost
```

**With the policy** (strategy target 2 throughout):
```text
Cycle 1: 10 workers, max removable = ceil(10 × 0.25) = 3  → 7
Cycle 2:  7 workers, max removable = ceil(7 × 0.25)  = 2  → 5
Cycle 3:  5 workers, max removable = ceil(5 × 0.25)  = 2  → 3
Cycle 4:  3 workers, removal of 1 is within the limit     → 2
Result: geometric decay to the target, not a fixed -1 per cycle
```

The proportional shape means large pools shed workers quickly at first and slow down as they approach the floor.

#### When to Use

✅ **Perfect for:**
- High-volume queues with variable load
- Workloads with "bursty but persistent" patterns
- Preventing oscillation in unpredictable workloads
- General-purpose applications (default behavior)

❌ **Not suitable for:**
- Cost-sensitive background jobs (use AggressiveScaleDown instead)
- Truly idle queues needing rapid scale-down
- Workloads with clear on/off patterns

#### Real-World Example

**Email Queue with Variable Load**

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\HighVolumeProfile;
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;

'queues' => [
    'emails' => HighVolumeProfile::class,   // profile handles scale-up
],

'policies' => [
    ConservativeScaleDownPolicy::class,
],
```

**Behaviour:**
```text
10:00 - Campaign sends 10,000 emails
  → Scales to 25 workers, processes them rapidly

10:30 - Campaign complete, queue empty, strategy target drops to 3
  → Without the policy: 25 → 3 in one cycle
  → With the policy:    25 → 19 (max removable = ceil(25 × 0.25) = 7)

10:30:05 - 19 → 15, then 15 → 12, then 12 → 9, then 9 → 7 ...

11:00 - New campaign starts (2,000 emails)
  → Some warm workers are still running
  → The spike is absorbed with less cold-start cost
```

#### Cost/Performance Trade-offs

**Pros:**
- Prevents expensive thrashing cycles
- Maintains some capacity for follow-up spikes
- Smoother resource utilization
- Better for cloud providers with per-minute billing

**Cons:**
- Slower cost reduction when truly idle
- May maintain excess workers longer than needed
- Not optimal for clear on/off workloads

---

### AggressiveScaleDownPolicy

**Overrides a conservative clamp so an idle queue can stand down in one cycle.**

It is narrower than the name suggests. `beforeScaling()`:

1. Returns `null` immediately unless the decision is a scale-down.
2. If the queue looks **idle** (`predictedPickupTime` is `null` or `0.0`) **and** the target is already `<= 1`, it returns a replacement decision forcing that exact target (0 or 1).
3. Otherwise it returns `null` — the decision passes through exactly as it arrived.

Case 3 is the important one: on its own, this policy changes nothing. Its purpose is to sit **after** `ConservativeScaleDownPolicy` in the chain so it can undo that policy's clamp for the idle case.

#### Configuration

```php
use Cbox\LaravelQueueAutoscale\Policies\AggressiveScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;

'policies' => [
    ConservativeScaleDownPolicy::class,
    AggressiveScaleDownPolicy::class,   // must come AFTER, to override
],
```

#### How It Works

```text
Queue goes idle. Strategy target = 0 (workers.min = 0), current = 4.

With Conservative only:
  max removable = ceil(4 × 0.25) = 1  → 3, then 2, then 1, then 0

With Conservative then Aggressive:
  Conservative clamps 4 → 3
  Aggressive sees an idle queue with target 0 (≤ 1) and forces 4 → 0
```

If the queue is **not** idle — `predictedPickupTime` is a real number — Aggressive does nothing, and Conservative's 25% clamp stands.

#### When to Use

✅ **Perfect for:**
- Background/maintenance queues
- Bursty workloads with clear idle periods
- Cost-sensitive applications
- Development/staging environments
- Scheduled batch jobs

❌ **Not suitable for:**
- Steady workloads
- Queues sensitive to cold start delays
- High-volume queues with persistent load

#### Real-World Example

**Nightly Analytics Queue**

```php
'queues' => [
    'analytics' => BackgroundProfile::class,
],

'policies' => [
    AggressiveScaleDownPolicy::class,
],
```

**Behavior:**
```text
22:00 - Nightly job dispatches 1,000 analytics tasks
  → Scales 0 → 5 workers
  → Processes tasks

23:30 - All tasks complete, queue empty
  → Strategy: scale to 0 workers (workers.min = 0)
  → Policy: Allows full scale-down
  → Result: 5 → 0 workers immediately

Cost savings:
  Conservative: Maintains 1-2 workers until 00:00 = $1.50
  Aggressive: Scales to 0 immediately = $0
  Savings: $1.50/night = $45/month
```

#### Combining with ConservativeScaleDown

This ordering is the **intended** use, not a mistake:

```php
'policies' => [
    ConservativeScaleDownPolicy::class,
    AggressiveScaleDownPolicy::class,  // overrides the clamp for idle queues
],
```

The reverse order does nothing useful: Aggressive would run first, return `null` in almost every case, and Conservative would then clamp the result anyway.

If you want unrestricted scale-down in all cases, do not list either policy — an empty (or Conservative-free) `policies` array lets the strategy's target through unmodified, subject only to the strategy's own smoothing.

---

### NoScaleDownPolicy

**Prevents all scale-down to maintain constant capacity**

#### Configuration

```php
use Cbox\LaravelQueueAutoscale\Policies\NoScaleDownPolicy;

'policies' => [
    NoScaleDownPolicy::class,
],
```

#### How It Works

```text
Load spike: 5 → 20 workers   (scale-up is untouched)
Load drops: 20 → 20 workers  (scale-down replaced with a hold)
```

The policy intercepts a scale-down decision and replaces it with `targetWorkers = currentWorkers`.

**One exception.** Before blocking, it asks the `CapacityCalculator` whether the host can still support the current worker count:

```php
$capacityResult = $this->capacity->calculateMaxWorkers(
    $decision->currentWorkers,
    ResourceEstimate::globalDefault(),
);

if ($decision->currentWorkers > $capacityResult->finalMaxWorkers) {
    return null;   // resource-forced scale-down is allowed through
}
```

So a host under CPU or memory pressure can still shed workers — the policy protects capacity, not stability.

It takes `CapacityCalculator` via constructor injection, which is why `policies` entries must be class strings resolved through the container.

#### When to Use

✅ **Perfect for:**
- Mission-critical queues with zero tolerance for delays
- Payment processing systems
- Real-time notification systems
- Queues with SLA contracts and penalties
- Workloads where cost < reliability

❌ **Not suitable for:**
- Cost-sensitive applications
- Variable workloads
- Background processing
- Any queue where over-provisioning is wasteful

#### Real-World Example

**Payment Processing Queue**

```php
'queues' => [
    'payments' => CriticalProfile::class,
],

'policies' => [
    NoScaleDownPolicy::class,
    BreachNotificationPolicy::class,
],
```

**Behaviour:**
```text
10:00 - Sale starts, 1,000 payment requests/hour
  → Scales 5 → 35 workers, all within the 10s SLA

14:00 - Load drops to 200/hour
  → Without the policy: 35 → 10 workers
  → With the policy:    stays at 35

18:00 - Load returns to 800/hour
  → Already at 35 workers, no cold start

Later - Host memory pressure pushes capacity below 35
  → The policy steps aside; the host sheds workers to stay stable
```

#### Trade-offs

- Scales up during spikes
- Never scales down under normal conditions
- Holds peak capacity indefinitely, so you pay for peak all day
- Still yields to host resource pressure
- Use only when the cost of a cold start outweighs the cost of idle workers

---

### BreachNotificationPolicy

**Logs and notifies about SLA compliance issues**

#### Configuration

```php
use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;

'policies' => [
    BreachNotificationPolicy::class,
],
```

#### How It Works

`beforeScaling()` always returns `null` — this policy never modifies a decision. All of its behaviour is in `afterScaling()`, which checks two conditions and logs to `manager.log_channel`.

**1. SLA breach risk** (`$decision->isSlaBreachRisk()`, i.e. `predictedPickupTime > slaTarget`), logged at **warning** level:

```text
[warning] SLA BREACH RISK DETECTED
{
    "connection": "redis",
    "queue": "emails",
    "predicted_pickup_time": 35.2,
    "sla_target": 30,
    "current_workers": 5,
    "target_workers": 8,
    "reason": "..."
}
```

**2. High SLA utilisation** (`predictedPickupTime / slaTarget >= 90%`), logged at **notice** level:

```text
[notice] High SLA utilization: 92.5%
{
    "connection": "redis",
    "queue": "payments",
    "sla_utilization_percent": 92.5,
    "predicted_pickup_time": 27.75,
    "sla_target": 30,
    "current_workers": 8,
    "target_workers": 10
}
```

Both conditions are true on **every** evaluation cycle while they hold, so each is gated through `AlertRateLimiter` under its own key (`breach_risk:{connection}:{queue}` and `high_util:{connection}:{queue}`). The cooldown is `queue-autoscale.alerting.cooldown_seconds`, 300 seconds by default.

The policy logs nothing else — no "workers scaled successfully" line, no recovery line. For recovery, listen to the `SlaRecovered` event.

#### When to Use

✅ **Perfect for:**
- Production environments
- Queues with SLA requirements
- Systems requiring audit trails
- On-call rotation scenarios
- Performance monitoring

❌ **Not suitable for:**
- Development environments (noisy logs)
- Queues without SLA requirements
- When log volume is a concern

#### Real-World Example

**Production Queue Monitoring**

```php
'queues' => [
    'default' => BalancedProfile::class,
],

'policies' => [
    ConservativeScaleDownPolicy::class,
    BreachNotificationPolicy::class,
],
```

**Log output:**
```text
[2026-08-04 10:30:15] local.NOTICE: High SLA utilization: 91.2%
  {"connection":"redis","queue":"default","sla_utilization_percent":91.2,
   "predicted_pickup_time":27.4,"sla_target":30,
   "current_workers":8,"target_workers":10}

[2026-08-04 10:35:20] local.WARNING: SLA BREACH RISK DETECTED
  {"connection":"redis","queue":"default","predicted_pickup_time":35.2,
   "sla_target":30,"current_workers":10,"target_workers":12,
   "reason":"..."}
```

Nothing appears again for either key until its 300-second cooldown expires.

#### Extending with Alerts

You can extend this policy for custom alerting:

```php
namespace App\Policies;

use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy as BasePolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Notification;
use App\Notifications\SlaBreachAlert;

readonly class CustomBreachNotificationPolicy extends BasePolicy
{
    public function afterScaling(ScalingDecision $decision): void
    {
        // Call parent for the rate-limited logging
        parent::afterScaling($decision);

        // Add custom alerting
        if ($decision->isSlaBreachRisk()) {
            Notification::route('slack', config('alerts.slack_webhook'))
                ->notify(new SlaBreachAlert($decision));
        }
    }
}
```

`BreachNotificationPolicy` is declared `readonly`, so the subclass **must** be `readonly` too — PHP fatals otherwise. Note also that your `afterScaling()` override is not rate-limited: `AlertRateLimiter` gates the parent's log calls only. Inject your own limiter if the Slack notification should be throttled.

Remember to register the subclass instead of the base policy:

```php
'policies' => [
    ConservativeScaleDownPolicy::class,
    \App\Policies\CustomBreachNotificationPolicy::class,
],
```

---

## Policy Combinations

### Recommended Combinations by Profile

#### Critical Profile
```php
'policies' => [
    NoScaleDownPolicy::class,           // Maintain capacity
    BreachNotificationPolicy::class,    // Monitor compliance
],
```

**Why:**
- Critical workloads prioritize reliability over cost
- NoScaleDown prevents cold starts
- BreachNotification provides visibility

---

#### High-Volume Profile
```php
'policies' => [
    ConservativeScaleDownPolicy::class,  // Prevent thrashing
    BreachNotificationPolicy::class,     // Monitor performance
],
```

**Why:**
- Steady workloads benefit from gradual scale-down
- Conservative prevents oscillation
- BreachNotification tracks SLA compliance

---

#### Balanced Profile (Default)
```php
'policies' => [
    ConservativeScaleDownPolicy::class,  // Safe defaults
    BreachNotificationPolicy::class,     // Basic monitoring
],
```

**Why:**
- Safe defaults for unknown workloads
- Conservative prevents surprises
- BreachNotification provides baseline visibility

---

#### Bursty Profile
```php
'policies' => [
    ConservativeScaleDownPolicy::class,  // 25%-per-cycle clamp
    AggressiveScaleDownPolicy::class,    // lifted once the queue is idle
    BreachNotificationPolicy::class,     // monitor spikes
],
```

**Why:**
- Bursty workloads have clear idle periods
- Aggressive must come **after** Conservative to override its clamp — on its own it does nothing
- BreachNotification tracks spike handling

---

#### Background Profile
```php
'policies' => [
    ConservativeScaleDownPolicy::class,
    AggressiveScaleDownPolicy::class,    // stand down fully once idle
],
```

**Why:**
- Background queues can afford to go cold between batches
- BreachNotification is omitted because a multi-minute SLA rarely warrants a log line

> Policies are configured **globally**, not per queue. `queue-autoscale.policies` applies to every queue and group. The headings above describe which combination suits an app whose queues are predominantly of that shape — to vary behaviour per queue, write a policy that inspects `$decision->queue`.

## Custom Policies

### Creating a Policy

Implement the `ScalingPolicy` interface:

```php
namespace App\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class MyCustomPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // Modify decision or return null to allow it through

        if ($this->shouldModify($decision)) {
            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: $this->calculateNewTarget($decision),
                reason: 'MyCustomPolicy modified decision',
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
                capacity: $decision->capacity,                     // carry these through
                spawnCompensation: $decision->spawnCompensation,   // or downstream sees null
            );
        }

        return null; // Allow original decision
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // Perform side effects (logging, metrics, alerts)
    }
}
```

### Example: Time-Based Scaling Policy

```php
namespace App\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class BusinessHoursScalingPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        $hour = now()->hour;
        $isBusinessHours = $hour >= 9 && $hour <= 17;

        // During business hours, maintain a higher floor
        if ($isBusinessHours && $decision->targetWorkers < 5) {
            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: 5,
                reason: 'BusinessHoursScalingPolicy enforcing a floor of 5 workers (09:00-17:00)',
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
                capacity: $decision->capacity,
                spawnCompensation: $decision->spawnCompensation,
            );
        }

        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // No action needed
    }
}
```

**Usage:**
```php
'policies' => [
    \App\Policies\BusinessHoursScalingPolicy::class,
    ConservativeScaleDownPolicy::class,
    BreachNotificationPolicy::class,
],
```

> **A policy's target is not re-clamped.** `workers.min`, `workers.max`, the host capacity ceiling and the failure fuse are all applied *before* policies run. Whatever number the last policy returns is what the manager spawns or terminates to. Keep your policy's output inside the bounds you actually want — a floor above `workers.max` will be honoured.

### Example: Global Worker Cap

`workers.max` is per queue. If you need a cap across a specific queue regardless of its profile, a policy is the place to put it:

```php
namespace App\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Log;

class HardWorkerCapPolicy implements ScalingPolicy
{
    public function __construct(
        private readonly int $maxWorkers = 50,
    ) {}

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        if ($decision->targetWorkers <= $this->maxWorkers) {
            return null;
        }

        Log::warning('HardWorkerCapPolicy capping workers', [
            'connection' => $decision->connection,
            'queue' => $decision->queue,
            'requested' => $decision->targetWorkers,
            'capped_to' => $this->maxWorkers,
        ]);

        return new ScalingDecision(
            connection: $decision->connection,
            queue: $decision->queue,
            currentWorkers: $decision->currentWorkers,
            targetWorkers: $this->maxWorkers,
            reason: sprintf(
                'HardWorkerCapPolicy capped from %d to %d workers (original: %s)',
                $decision->targetWorkers,
                $this->maxWorkers,
                $decision->reason,
            ),
            predictedPickupTime: $decision->predictedPickupTime,
            slaTarget: $decision->slaTarget,
            capacity: $decision->capacity,
            spawnCompensation: $decision->spawnCompensation,
        );
    }

    public function afterScaling(ScalingDecision $decision): void {}
}
```

The constructor default is used only if the container can resolve it — `app(HardWorkerCapPolicy::class)` will autowire the scalar from its default value. Bind it explicitly in a service provider if you want a different cap:

```php
$this->app->bind(
    \App\Policies\HardWorkerCapPolicy::class,
    fn () => new \App\Policies\HardWorkerCapPolicy(maxWorkers: 120),
);
```

## Troubleshooting

### Policies Not Executing

**Check registration:**
```php
// config/queue-autoscale.php
'policies' => [
    \Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy::class,
    // Fully qualified class name required
],
```

**Check logs:**
```bash
tail -f storage/logs/laravel.log | grep -i policy
```

### Policy Conflicts

**Redundant:**
```php
'policies' => [
    NoScaleDownPolicy::class,           // turns every scale-down into a hold
    ConservativeScaleDownPolicy::class, // then sees a hold, and does nothing
],
```

`NoScaleDownPolicy` runs first and replaces the scale-down with `targetWorkers = currentWorkers`. Conservative then sees a decision that is no longer a scale-down and returns `null`. Not harmful, just pointless — drop the second entry.

**Order-sensitive:**
```php
'policies' => [
    ConservativeScaleDownPolicy::class,
    AggressiveScaleDownPolicy::class,   // only works in this order
],
```

**Fine together:**
```php
'policies' => [
    ConservativeScaleDownPolicy::class,
    BreachNotificationPolicy::class,    // never modifies anything
],
```

### Policy Order Matters

Policies execute in order. Later policies see modifications from earlier policies:

```php
'policies' => [
    MyScaleUpPolicy::class,            // Increases target by 2
    ConservativeScaleDownPolicy::class, // Sees already-increased target
],
```

## Performance Impact

Policies run **synchronously inside the manager daemon**, once per queue per evaluation cycle. Keep them fast:

- A slow policy delays the whole cycle for every queue behind it.
- Logging is synchronous — the shipped `BreachNotificationPolicy` gates its writes through `AlertRateLimiter` for exactly this reason.
- An HTTP call inside `beforeScaling()` or `afterScaling()` is a bad idea. Dispatch a job or fire an event and do the work elsewhere.
- Exceptions are caught by `PolicyExecutor` and logged; a throwing policy does not abort scaling, but it does mean its effect is silently missing.

## Next Steps

- [Workload Profiles](workload-profiles.md) - Choose the right profile
- [Monitoring](monitoring.md) - Track policy effectiveness
- [Event Handling](event-handling.md) - React to scaling events
