---
title: "Scaling Policies"
description: "Complete guide to implementing and using scaling policies in Queue Autoscale for Laravel"
weight: 31
---

# Scaling Policies

Complete guide to implementing and using scaling policies in Queue Autoscale for Laravel.

## Table of Contents
- [Overview](#overview)
- [Policy Contract](#policy-contract)
- [Implementation Steps](#implementation-steps)
- [Policy Examples](#policy-examples)
- [Testing Policies](#testing-policies)
- [Best Practices](#best-practices)
- [Common Use Cases](#common-use-cases)

## Overview

Scaling policies add cross-cutting concerns to the autoscaling process. They execute **before** and **after** scaling decisions, allowing you to:
- Send notifications (Slack, email, PagerDuty)
- Log metrics and decisions
- Enforce resource constraints
- Implement custom validation
- Track scaling history
- Integrate with external systems

### When to Use Policies

Use policies when you need to:
- **React to scaling events** (notifications, alerts)
- **Enforce constraints** (budget limits, resource caps)
- **Collect data** (metrics, analytics, audit trails)
- **Integrate systems** (monitoring, incident management)
- **Validate decisions** (compliance, safety checks)

### Policy vs Strategy

**Strategies** calculate **how many workers** are needed.
**Policies** add **behavior around** scaling decisions.

```
┌─────────────────────────────────────┐
│      Scaling Decision Flow          │
├─────────────────────────────────────┤
│                                     │
│  1. Policies: beforeScaling()       │ ← Validate, log, prepare
│                                     │
│  2. Strategy: calculateWorkers()    │ ← Core calculation
│                                     │
│  3. Policies: afterScaling()        │ ← Notify, record, cleanup
│                                     │
└─────────────────────────────────────┘
```

## Policy Contract

All policies must implement `ScalingPolicyContract`:

```php
<?php

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

interface ScalingPolicyContract
{
    /**
     * Execute before scaling decision is made
     *
     * @param object $metrics Queue metrics
     * @param QueueConfiguration $config Queue configuration
     * @param int $currentWorkers Current worker count
     * @return void
     */
    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void;

    /**
     * Execute after scaling decision is made
     *
     * @param ScalingDecision $decision The scaling decision
     * @param QueueConfiguration $config Queue configuration
     * @param int $currentWorkers Current worker count
     * @return void
     */
    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void;
}
```

### Method Responsibilities

#### `beforeScaling()`
Called **before** the strategy calculates workers.

Use for:
- Logging decision start
- Validating preconditions
- Preparing external systems
- Recording metrics state

#### `afterScaling()`
Called **after** the strategy calculates workers.

Use for:
- Sending notifications
- Recording decisions
- Updating external systems
- Logging outcomes

## Implementation Steps

### Step 1: Create Policy Class

```php
<?php

namespace App\Autoscale\Policies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicyContract;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class CustomPolicy implements ScalingPolicyContract
{
    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void
    {
        // Your before logic here
    }

    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        // Your after logic here
    }
}
```

### Step 2: Register Policy

Add to `config/queue-autoscale.php`:

```php
'policies' => [
    \Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy::class,
    \Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy::class,

    // Your custom policies
    \App\Autoscale\Policies\CustomPolicy::class,
],
```

### Step 3: Test Policy

```php
use App\Autoscale\Policies\CustomPolicy;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

it('executes policy hooks', function () {
    $policy = new CustomPolicy();

    $metrics = (object) ['processingRate' => 10.0];
    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 60,
        minWorkers: 1,
        maxWorkers: 10,
    );

    // Test before hook
    $policy->beforeScaling($metrics, $config, 5);

    // Test after hook
    $decision = new ScalingDecision(
        targetWorkers: 10,
        reason: 'Test scaling',
        confidence: 0.9,
        predictedPickupTime: 5.0
    );

    $policy->afterScaling($decision, $config, 5);

    // Assert your expectations
});
```

## Policy Examples

### Example 1: Slack Notification Policy

Send scaling notifications to Slack:

```php
<?php

namespace App\Autoscale\Policies;

use Illuminate\Support\Facades\Http;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicyContract;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class SlackNotificationPolicy implements ScalingPolicyContract
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly int $minWorkerChange = 5  // Only notify for significant changes
    ) {}

    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void
    {
        // No action needed before scaling
    }

    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        $workerChange = abs($decision->targetWorkers - $currentWorkers);

        // Only notify for significant changes
        if ($workerChange < $this->minWorkerChange) {
            return;
        }

        $direction = $decision->targetWorkers > $currentWorkers ? '⬆️ SCALE UP' : '⬇️ SCALE DOWN';
        $color = $decision->targetWorkers > $currentWorkers ? '#36a64f' : '#ff9900';

        $message = [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => "{$direction}: {$config->queue} queue",
                    'fields' => [
                        [
                            'title' => 'Worker Change',
                            'value' => "{$currentWorkers} → {$decision->targetWorkers}",
                            'short' => true,
                        ],
                        [
                            'title' => 'Pending Jobs',
                            'value' => $metrics->depth->pending ?? 'N/A',
                            'short' => true,
                        ],
                        [
                            'title' => 'Reason',
                            'value' => $decision->reason,
                            'short' => false,
                        ],
                        [
                            'title' => 'Predicted Pickup Time',
                            'value' => $decision->predictedPickupTime
                                ? sprintf('%.1fs', $decision->predictedPickupTime)
                                : 'N/A',
                            'short' => true,
                        ],
                        [
                            'title' => 'Confidence',
                            'value' => sprintf('%.1f%%', $decision->confidence * 100),
                            'short' => true,
                        ],
                    ],
                    'footer' => 'Queue Autoscale for Laravel',
                    'ts' => time(),
                ],
            ],
        ];

        Http::post($this->webhookUrl, $message);
    }
}
```

Usage:

```php
'policies' => [
    new \App\Autoscale\Policies\SlackNotificationPolicy(
        webhookUrl: config('services.slack.autoscale_webhook'),
        minWorkerChange: 5
    ),
],
```

### Example 2: Metrics Logging Policy

Log detailed metrics for analysis:

```php
<?php

namespace App\Autoscale\Policies;

use Illuminate\Support\Facades\DB;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicyContract;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class MetricsLoggingPolicy implements ScalingPolicyContract
{
    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void
    {
        // Log pre-scaling metrics
        DB::table('autoscale_metrics')->insert([
            'connection' => $config->connection,
            'queue' => $config->queue,
            'event_type' => 'before_scaling',
            'current_workers' => $currentWorkers,
            'pending_jobs' => $metrics->depth->pending ?? null,
            'oldest_job_age' => $metrics->depth->oldestJobAgeSeconds ?? null,
            'processing_rate' => $metrics->processingRate ?? null,
            'trend_direction' => $metrics->trend->direction ?? null,
            'trend_forecast' => $metrics->trend->forecast ?? null,
            'cpu_percent' => $metrics->resources->cpuPercent ?? null,
            'memory_percent' => $metrics->resources->memoryPercent ?? null,
            'recorded_at' => now(),
        ]);
    }

    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        // Log scaling decision
        DB::table('autoscale_decisions')->insert([
            'connection' => $config->connection,
            'queue' => $config->queue,
            'current_workers' => $currentWorkers,
            'target_workers' => $decision->targetWorkers,
            'worker_change' => $decision->targetWorkers - $currentWorkers,
            'reason' => $decision->reason,
            'confidence' => $decision->confidence,
            'predicted_pickup_time' => $decision->predictedPickupTime,
            'max_pickup_time_sla' => $config->maxPickupTimeSeconds,
            'decision_at' => now(),
        ]);
    }
}
```

Create migration:

```php
Schema::create('autoscale_metrics', function (Blueprint $table) {
    $table->id();
    $table->string('connection');
    $table->string('queue');
    $table->string('event_type');
    $table->integer('current_workers');
    $table->integer('pending_jobs')->nullable();
    $table->float('oldest_job_age')->nullable();
    $table->float('processing_rate')->nullable();
    $table->string('trend_direction')->nullable();
    $table->float('trend_forecast')->nullable();
    $table->float('cpu_percent')->nullable();
    $table->float('memory_percent')->nullable();
    $table->timestamp('recorded_at');
    $table->index(['connection', 'queue', 'recorded_at']);
});

Schema::create('autoscale_decisions', function (Blueprint $table) {
    $table->id();
    $table->string('connection');
    $table->string('queue');
    $table->integer('current_workers');
    $table->integer('target_workers');
    $table->integer('worker_change');
    $table->text('reason');
    $table->float('confidence');
    $table->float('predicted_pickup_time')->nullable();
    $table->integer('max_pickup_time_sla');
    $table->timestamp('decision_at');
    $table->index(['connection', 'queue', 'decision_at']);
});
```

### Example 3: Budget Enforcement Policy

Prevent cost overruns:

```php
<?php

namespace App\Autoscale\Policies;

use Illuminate\Support\Facades\Cache;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicyContract;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class BudgetEnforcementPolicy implements ScalingPolicyContract
{
    public function __construct(
        private readonly float $hourlyBudget = 100.00,
        private readonly float $workerCostPerHour = 0.50
    ) {}

    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void
    {
        // No validation needed before
    }

    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        $currentHour = now()->format('Y-m-d-H');
        $cacheKey = "autoscale:budget:{$currentHour}";

        // Calculate cost for this hour
        $currentSpend = Cache::get($cacheKey, 0.0);
        $projectedCost = $decision->targetWorkers * $this->workerCostPerHour;

        if ($currentSpend + $projectedCost > $this->hourlyBudget) {
            // Reduce workers to fit budget
            $maxAffordableWorkers = (int) floor(($this->hourlyBudget - $currentSpend) / $this->workerCostPerHour);

            // Update decision (reflection hack for readonly properties)
            $reflection = new \ReflectionProperty($decision, 'targetWorkers');
            $reflection->setAccessible(true);
            $reflection->setValue($decision, max($config->minWorkers, $maxAffordableWorkers));

            $reflection = new \ReflectionProperty($decision, 'reason');
            $reflection->setAccessible(true);
            $reflection->setValue(
                $decision,
                "Budget constraint: reduced to {$maxAffordableWorkers} workers (original: {$decision->reason})"
            );

            // Log budget event
            logger()->warning('Autoscale budget constraint applied', [
                'queue' => $config->queue,
                'original_workers' => $decision->targetWorkers,
                'budget_workers' => $maxAffordableWorkers,
                'current_spend' => $currentSpend,
                'budget' => $this->hourlyBudget,
            ]);
        }

        // Track spending
        Cache::put($cacheKey, $currentSpend + $projectedCost, now()->addHours(2));
    }
}
```

### Example 4: PagerDuty Integration

Alert on-call for critical scaling events:

```php
<?php

namespace App\Autoscale\Policies;

use Illuminate\Support\Facades\Http;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicyContract;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class PagerDutyAlertPolicy implements ScalingPolicyContract
{
    public function __construct(
        private readonly string $integrationKey,
        private readonly float $slaBreachThreshold = 0.9  // Alert at 90% of SLA
    ) {}

    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void
    {
        $oldestJobAge = $metrics->depth->oldestJobAgeSeconds ?? 0;
        $slaLimit = $config->maxPickupTimeSeconds;

        // Check for imminent SLA breach
        if ($oldestJobAge > ($slaLimit * $this->slaBreachThreshold)) {
            $this->triggerAlert(
                severity: 'warning',
                summary: "Queue SLA breach imminent: {$config->queue}",
                details: [
                    'queue' => $config->queue,
                    'oldest_job_age' => $oldestJobAge,
                    'sla_limit' => $slaLimit,
                    'pending_jobs' => $metrics->depth->pending ?? 0,
                    'current_workers' => $currentWorkers,
                ]
            );
        }
    }

    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        // Alert if we hit max workers (capacity limit)
        if ($decision->targetWorkers >= $config->workers->max) {
            $this->triggerAlert(
                severity: 'error',
                summary: "Queue at maximum capacity: {$config->queue}",
                details: [
                    'queue' => $config->queue,
                    'workers_max' => $config->workers->max,
                    'current_workers' => $currentWorkers,
                    'reason' => $decision->reason,
                ]
            );
        }
    }

    private function triggerAlert(string $severity, string $summary, array $details): void
    {
        Http::post('https://events.pagerduty.com/v2/enqueue', [
            'routing_key' => $this->integrationKey,
            'event_action' => 'trigger',
            'payload' => [
                'summary' => $summary,
                'severity' => $severity,
                'source' => 'laravel-queue-autoscale',
                'custom_details' => $details,
            ],
        ]);
    }
}
```

### Example 5: Cooldown Tracking Policy

Track and enforce cooldown periods:

```php
<?php

namespace App\Autoscale\Policies;

use Illuminate\Support\Facades\Cache;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicyContract;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class CooldownTrackingPolicy implements ScalingPolicyContract
{
    public function beforeScaling(object $metrics, QueueConfiguration $config, int $currentWorkers): void
    {
        $cacheKey = "autoscale:cooldown:{$config->connection}:{$config->queue}";
        $lastScaleTime = Cache::get($cacheKey);

        if ($lastScaleTime && now()->timestamp - $lastScaleTime < $config->scaleCooldownSeconds) {
            $remainingCooldown = $config->scaleCooldownSeconds - (now()->timestamp - $lastScaleTime);

            logger()->info('Scaling suppressed by cooldown', [
                'queue' => $config->queue,
                'remaining_seconds' => $remainingCooldown,
            ]);
        }
    }

    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        // Only update cooldown if workers actually changed
        if ($decision->targetWorkers !== $currentWorkers) {
            $cacheKey = "autoscale:cooldown:{$config->connection}:{$config->queue}";
            Cache::put($cacheKey, now()->timestamp, $config->scaleCooldownSeconds);

            logger()->info('Cooldown period started', [
                'queue' => $config->queue,
                'duration_seconds' => $config->scaleCooldownSeconds,
                'worker_change' => $decision->targetWorkers - $currentWorkers,
            ]);
        }
    }
}
```

## Testing Policies

### Unit Tests

Test policy behavior in isolation:

```php
use App\Autoscale\Policies\SlackNotificationPolicy;
use Illuminate\Support\Facades\Http;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

describe('SlackNotificationPolicy', function () {
    beforeEach(function () {
        Http::fake();

        $this->policy = new SlackNotificationPolicy(
            webhookUrl: 'https://hooks.slack.com/test',
            minWorkerChange: 5
        );

        $this->config = new QueueConfiguration(
            connection: 'redis',
            queue: 'default',
            maxPickupTimeSeconds: 60,
            minWorkers: 1,
            maxWorkers: 20,
        );
    });

    it('sends notification for significant worker increase', function () {
        $decision = new ScalingDecision(
            targetWorkers: 15,
            reason: 'High load detected',
            confidence: 0.9,
            predictedPickupTime: 30.0
        );

        $metrics = (object) [
            'depth' => (object) ['pending' => 100],
        ];

        $this->policy->afterScaling($decision, $this->config, 5);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/test'
                && str_contains($request['attachments'][0]['title'], 'SCALE UP');
        });
    });

    it('does not send notification for small changes', function () {
        $decision = new ScalingDecision(
            targetWorkers: 7,
            reason: 'Minor adjustment',
            confidence: 0.8,
            predictedPickupTime: 45.0
        );

        $metrics = (object) ['depth' => (object) ['pending' => 10]];

        $this->policy->afterScaling($decision, $this->config, 5);

        Http::assertNothingSent();
    });
});
```

### Integration Tests

Test policy interaction with scaling engine:

```php
use App\Autoscale\Policies\MetricsLoggingPolicy;
use Illuminate\Support\Facades\DB;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;

it('logs metrics during scaling evaluation', function () {
    DB::shouldReceive('table->insert')->twice();  // before + after

    $policy = new MetricsLoggingPolicy();

    // Register policy with engine
    $engine = app(ScalingEngine::class);
    // ... configure engine with policy

    $metrics = (object) [
        'processingRate' => 10.0,
        'activeWorkerCount' => 5,
        'depth' => (object) ['pending' => 100, 'oldestJobAgeSeconds' => 5],
    ];

    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 60,
        minWorkers: 1,
        maxWorkers: 20,
    );

    $engine->evaluate($metrics, $config, 5);

    // Both before and after should have been logged
});
```

## Best Practices

### 1. Keep Policies Focused

Each policy should have a single responsibility:

```php
// ✅ Good: Single responsibility
class SlackNotificationPolicy implements ScalingPolicyContract { }
class MetricsLoggingPolicy implements ScalingPolicyContract { }

// ❌ Bad: Multiple responsibilities
class NotificationAndLoggingPolicy implements ScalingPolicyContract { }
```

### 2. Handle Failures Gracefully

Don't let policy failures break scaling:

```php
public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
{
    try {
        Http::timeout(5)->post($this->webhookUrl, $this->buildMessage($decision));
    } catch (\Exception $e) {
        // Log but don't throw - don't break scaling
        logger()->error('Slack notification failed', [
            'error' => $e->getMessage(),
            'queue' => $config->queue,
        ]);
    }
}
```

### 3. Use Dependency Injection

Make policies testable:

```php
public function __construct(
    private readonly HttpClient $http,
    private readonly Logger $logger
) {}

// Test with mocks
$policy = new SlackNotificationPolicy(
    http: $mockHttp,
    logger: $mockLogger
);
```

### 4. Respect Performance

Policies execute on every evaluation - keep them fast:

```php
// ✅ Good: Fast, async
Http::async()->post($url, $data);

// ❌ Bad: Slow, synchronous
sleep(5);
Http::retry(3, 10000)->post($url, $data);
```

### 5. Make Policies Configurable

Use constructor parameters:

```php
public function __construct(
    private readonly int $minWorkerChange = 5,
    private readonly array $notifyChannels = ['slack', 'email'],
    private readonly bool $enableDebugLogging = false
) {}
```

## Common Use Cases

### Use Case 1: Multi-Channel Notifications

Notify different channels based on severity:

```php
class MultiChannelNotificationPolicy implements ScalingPolicyContract
{
    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        $workerChange = abs($decision->targetWorkers - $currentWorkers);

        if ($workerChange >= 20) {
            // Critical: PagerDuty + Slack + Email
            $this->pagerDuty->alert(...);
            $this->slack->notify(...);
            $this->email->send(...);
        } elseif ($workerChange >= 10) {
            // Warning: Slack + Email
            $this->slack->notify(...);
            $this->email->send(...);
        } elseif ($workerChange >= 5) {
            // Info: Slack only
            $this->slack->notify(...);
        }
    }
}
```

### Use Case 2: Audit Trail

Maintain compliance audit trail:

```php
class AuditTrailPolicy implements ScalingPolicyContract
{
    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        DB::table('autoscale_audit_log')->insert([
            'user_id' => auth()->id() ?? null,
            'action' => 'scaling_decision',
            'queue' => $config->queue,
            'before_workers' => $currentWorkers,
            'after_workers' => $decision->targetWorkers,
            'reason' => $decision->reason,
            'decision_data' => json_encode($decision),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
```

### Use Case 3: External Metrics Integration

Send to Datadog, Prometheus, etc:

```php
class DatadogMetricsPolicy implements ScalingPolicyContract
{
    public function afterScaling(ScalingDecision $decision, QueueConfiguration $config, int $currentWorkers): void
    {
        $this->datadog->gauge('queue.autoscale.workers', $decision->targetWorkers, [
            'queue' => $config->queue,
            'connection' => $config->connection,
        ]);

        $this->datadog->gauge('queue.autoscale.predicted_pickup_time', $decision->predictedPickupTime ?? 0, [
            'queue' => $config->queue,
        ]);

        $this->datadog->increment('queue.autoscale.decisions', 1, [
            'queue' => $config->queue,
            'direction' => $decision->targetWorkers > $currentWorkers ? 'up' : 'down',
        ]);
    }
}
```

## See Also

- [Custom Strategies](custom-strategies.md) - Implementing custom strategies
- [Event Handling](../basic-usage/event-handling.md) - Using Laravel events
- [Monitoring](../basic-usage/monitoring.md) - Monitoring and observability
- [API Reference](../api-reference/_index.md) - Complete API documentation
