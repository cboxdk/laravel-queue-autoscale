---
title: "Scaling Policies"
description: "Complete guide to using scaling policies to modify autoscaler behavior"
weight: 17
---

# Scaling Policies

Scaling policies are behavior modifiers that run before and after scaling decisions. They allow you to customize how the autoscaler behaves without changing core algorithms.

## Quick Reference

| Policy | Effect | Use Case | Default |
|--------|--------|----------|---------|
| **ConservativeScaleDown** | Limit scale-down to 1 worker/cycle | Prevent thrashing | ✅ Yes |
| **AggressiveScaleDown** | Allow rapid scale-down | Cost optimization | No |
| **NoScaleDown** | Prevent all scale-down | Critical workloads | No |
| **BreachNotification** | Log SLA breaches | Monitoring | ✅ Yes |

## How Policies Work

Policies execute in two phases:

1. **Before Scaling** (`beforeScaling`): Can modify the scaling decision
2. **After Scaling** (`afterScaling`): Can perform side effects (logging, alerts, etc.)

### Execution Flow

```
1. Strategy calculates target workers
2. Policies execute (beforeScaling) - can modify target
3. Scaling action performed
4. Policies execute (afterScaling) - side effects only
5. Events dispatched
```

### Policy Chaining

Multiple policies execute in order. Each policy receives the potentially modified decision from previous policies:

```php
'policies' => [
    ConservativeScaleDownPolicy::class,   // Runs first
    BreachNotificationPolicy::class,      // Runs second (sees result of first)
],
```

## Policy Deep Dive

### ConservativeScaleDownPolicy

**Prevents scaling thrashing by limiting scale-down to 1 worker per evaluation cycle**

#### Configuration

```php
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;

'policies' => [
    ConservativeScaleDownPolicy::class,
],
```

#### How It Works

**Without Policy:**
```
Cycle 1: 10 workers → queue empties → scale to 2 workers (-8)
Cycle 2: New jobs arrive → scale to 9 workers (+7)
Cycle 3: Jobs complete → scale to 3 workers (-6)
Result: Thrashing, wasted resources
```

**With Policy:**
```
Cycle 1: 10 workers → queue empties → scale to 9 workers (-1) ← Limited
Cycle 2: Still quiet → scale to 8 workers (-1)
Cycle 3: Still quiet → scale to 7 workers (-1)
...
Cycle 8: Reach target of 2 workers
Result: Smooth, gradual scale-down
```

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
'queues' => [
    'emails' => array_merge(ProfilePresets::highVolume(), [
        // Profile handles scale-up
    ]),
],

'policies' => [
    ConservativeScaleDownPolicy::class,
],
```

**Behavior:**
```
10:00 - Campaign sends 10,000 emails
  → Scales to 25 workers
  → Processes emails rapidly

10:30 - Campaign complete, queue empty
  → Without policy: Would scale 25 → 3 workers immediately
  → With policy: Scales 25 → 24 workers (cycle 1)

10:35 - Still empty
  → Scales 24 → 23 workers (cycle 2)

11:00 - New campaign starts (2,000 emails)
  → Still have 18 workers running
  → Handle spike immediately without cold start
  → No thrashing occurred

Result: Smooth operation, prevented oscillation
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

**Allows rapid scale-down for maximum cost efficiency**

#### Configuration

```php
use Cbox\LaravelQueueAutoscale\Policies\AggressiveScaleDownPolicy;

'policies' => [
    AggressiveScaleDownPolicy::class,
],
```

#### How It Works

**Without Policy (or with Conservative):**
```
Workers: 20 → 19 → 18 → 17 → ... → 3 (min_workers)
Time to scale down: 17 cycles × 60s = 17 minutes
```

**With AggressiveScaleDownPolicy:**
```
Workers: 20 → 3 (min_workers) immediately
Time to scale down: 1 cycle = 60s
```

The policy doesn't prevent the strategy's decision - it simply allows the full scale-down that the strategy calculated.

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
    'analytics' => ProfilePresets::background(),
],

'policies' => [
    AggressiveScaleDownPolicy::class,
],
```

**Behavior:**
```
22:00 - Nightly job dispatches 1,000 analytics tasks
  → Scales 0 → 5 workers
  → Processes tasks

23:30 - All tasks complete, queue empty
  → Strategy: scale to 0 workers (min_workers = 0)
  → Policy: Allows full scale-down
  → Result: 5 → 0 workers immediately

Cost savings:
  Conservative: Maintains 1-2 workers until 00:00 = $1.50
  Aggressive: Scales to 0 immediately = $0
  Savings: $1.50/night = $45/month
```

#### Combining with ConservativeScaleDown

**Don't do this:**
```php
'policies' => [
    ConservativeScaleDownPolicy::class,
    AggressiveScaleDownPolicy::class,  // ← These conflict!
],
```

These policies have opposite goals. Choose one:
- Conservative for steady workloads
- Aggressive for cost-sensitive workloads

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

**Without Policy:**
```
Load spike: 5 → 20 workers
Load drops: 20 → 5 workers (scales down)
```

**With Policy:**
```
Load spike: 5 → 20 workers
Load drops: 20 → 20 workers (maintains capacity)
```

The policy intercepts any scale-down decision and replaces it with a "hold" decision (target = current).

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
    'payments' => ProfilePresets::critical(),
],

'policies' => [
    NoScaleDownPolicy::class,
    BreachNotificationPolicy::class,
],
```

**Behavior:**
```
Monday 10:00 - Black Friday sale starts
  → Spike: 1,000 payment requests/hour
  → Scales 5 → 35 workers
  → All payments processed within 10s SLA

Monday 14:00 - Sale slows down
  → Load drops to 200 payments/hour
  → Without policy: Would scale 35 → 10 workers
  → With policy: Maintains 35 workers
  → Ready for next spike instantly

Monday 18:00 - Evening spike
  → Load returns to 800 payments/hour
  → Already have 35 workers
  → Zero cold start delay
  → Zero SLA breaches

Cost: $840/day (35 workers × $1/hour × 24h)
Fixed 35 workers anyway: $840/day
Benefit: Scales UP for even bigger spikes, never DOWN
```

#### Cost Implications

This policy trades cost efficiency for maximum reliability:

**Example Queue:**
```php
ProfilePresets::critical() + NoScaleDownPolicy
```

- Scales up during spikes ✅
- Never scales down ❌
- Maintains peak capacity 24/7
- Cost equals peak load cost
- **Use only when reliability > cost**

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

The policy monitors two conditions:

**1. SLA Breach Risk** (predicted pickup time > SLA target):
```php
// Logs WARNING level
[WARNING] SLA BREACH RISK DETECTED
{
    "connection": "redis",
    "queue": "emails",
    "predicted_pickup_time": 35,  // Exceeds SLA
    "sla_target": 30,
    "current_workers": 5,
    "target_workers": 8,
    "reason": "backlog requires 8 workers to prevent breach"
}
```

**2. High SLA Utilization** (>90% of SLA used):
```php
// Logs NOTICE level
[NOTICE] High SLA utilization: 92.5%
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
    'default' => ProfilePresets::balanced(),
],

'policies' => [
    ConservativeScaleDownPolicy::class,
    BreachNotificationPolicy::class,
],
```

**Log Output:**
```
[2024-11-19 10:30:15] NOTICE: High SLA utilization: 91.2%
  Queue: redis:default
  Predicted: 27.4s / 30s SLA
  Action: Scaling 8 → 10 workers

[2024-11-19 10:30:45] INFO: Workers scaled successfully
  Added 2 workers (8 → 10)

[2024-11-19 10:35:20] WARNING: SLA BREACH RISK DETECTED
  Queue: redis:default
  Predicted: 35.2s / 30s SLA
  Backlog: 250 jobs
  Current: 10 workers
  Target: 12 workers (scaling up)

[2024-11-19 10:36:00] INFO: SLA breach risk resolved
  Predicted: 22.1s / 30s SLA
  Workers: 12
```

#### Extending with Alerts

You can extend this policy for custom alerting:

```php
namespace App\Policies;

use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy as BasePolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Notification;
use App\Notifications\SlaBreachAlert;

class CustomBreachNotificationPolicy extends BasePolicy
{
    public function afterScaling(ScalingDecision $decision): void
    {
        // Call parent for logging
        parent::afterScaling($decision);

        // Add custom alerting
        if ($decision->isSlaBreachRisk()) {
            Notification::route('slack', config('alerts.slack_webhook'))
                ->notify(new SlaBreachAlert($decision));
        }
    }
}
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
    AggressiveScaleDownPolicy::class,    // Fast cost reduction
    BreachNotificationPolicy::class,     // Monitor spikes
],
```

**Why:**
- Bursty workloads have clear idle periods
- Aggressive maximizes savings between spikes
- BreachNotification tracks spike handling

---

#### Background Profile
```php
'policies' => [
    AggressiveScaleDownPolicy::class,    // Maximum cost savings
    // No BreachNotification (delays acceptable)
],
```

**Why:**
- Background jobs prioritize cost
- Aggressive ensures rapid scale-down
- SLA breaches don't matter for background work

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

        // During business hours, maintain higher minimum
        if ($isBusinessHours && $decision->targetWorkers < 5) {
            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: max($decision->targetWorkers, 5),
                reason: 'BusinessHoursPolicy enforcing minimum 5 workers (9-5)',
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
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

### Example: Cost Limit Policy

```php
namespace App\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class CostLimitPolicy implements ScalingPolicy
{
    public function __construct(
        private int $maxWorkers = 50,
        private float $costPerWorkerHour = 0.05,
    ) {}

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // Hard cap on worker count for budget control
        if ($decision->targetWorkers > $this->maxWorkers) {
            \Log::warning('CostLimitPolicy capping workers', [
                'requested' => $decision->targetWorkers,
                'capped_to' => $this->maxWorkers,
                'estimated_cost_per_hour' => $this->maxWorkers * $this->costPerWorkerHour,
            ]);

            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: $this->maxWorkers,
                reason: sprintf(
                    'CostLimitPolicy capped from %d to %d workers (budget limit)',
                    $decision->targetWorkers,
                    $this->maxWorkers
                ),
                predictedPickupTime: $decision->predictedPickupTime,
                slaTarget: $decision->slaTarget,
            );
        }

        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // Track cost metrics
        $estimatedCost = $decision->targetWorkers * $this->costPerWorkerHour;
        \Log::info('Current estimated cost', [
            'workers' => $decision->targetWorkers,
            'cost_per_hour' => $estimatedCost,
            'cost_per_day' => $estimatedCost * 24,
        ]);
    }
}
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

**Problem:**
```php
'policies' => [
    NoScaleDownPolicy::class,          // Prevents scale-down
    ConservativeScaleDownPolicy::class, // Tries to limit scale-down
    // Conflict: NoScaleDown prevents it entirely
],
```

**Solution:**
Choose compatible policies or ensure order matters:
```php
'policies' => [
    // These work together
    ConservativeScaleDownPolicy::class,
    BreachNotificationPolicy::class,
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

Policies add minimal overhead:

- Execution time: <1ms per policy
- No impact on scaling decision quality
- Logging is asynchronous
- Safe for production use

## Next Steps

- [Workload Profiles](workload-profiles.md) - Choose the right profile
- [Monitoring](monitoring.md) - Track policy effectiveness
- [Event Handling](event-handling.md) - React to scaling events
