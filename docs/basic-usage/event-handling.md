---
title: "Event Handling"
description: "Complete guide to using Laravel events with Queue Autoscale for notifications and integrations"
weight: 15
---

# Event Handling

Complete guide to using Laravel events with Queue Autoscale.

## Table of Contents
- [Overview](#overview)
- [Available Events](#available-events)
- [Listening to Events](#listening-to-events)
- [Event Payloads](#event-payloads)
- [Common Use Cases](#common-use-cases)
- [Best Practices](#best-practices)

## Overview

Laravel Queue Autoscale dispatches Laravel events at key points during the autoscaling lifecycle. You can listen to these events to:
- Send custom notifications
- Collect metrics
- Trigger external workflows
- Audit scaling decisions
- Integrate with other systems

### Events vs Policies

**Events** are Laravel's native event system - decoupled, broadcast to all listeners.
**Policies** are executed in-order as part of the scaling pipeline.

Use **Events** when:
- Multiple systems need to react independently
- You want loose coupling
- You're using Laravel's existing event infrastructure

Use **Policies** when:
- You need guaranteed execution order
- You want to modify scaling behavior
- You need to enforce constraints

## Available Events

### ScalingDecisionMade

Dispatched after a scaling decision is calculated, before workers are actually scaled.

```php
namespace Cbox\LaravelQueueAutoscale\Events;

class ScalingDecisionMade
{
    public function __construct(
        public readonly ScalingDecision $decision,
        public readonly QueueConfiguration $config,
        public readonly int $currentWorkers,
        public readonly object $metrics
    ) {}
}
```

**When**: After strategy calculates target workers
**Use for**: Logging decisions, sending notifications, analytics

### WorkersScaled

Dispatched after workers have been successfully spawned or terminated.

```php
namespace Cbox\LaravelQueueAutoscale\Events;

class WorkersScaled
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $previousCount,
        public readonly int $newCount,
        public readonly string $direction  // 'up', 'down', or 'none'
    ) {}
}
```

**When**: After worker count changes
**Use for**: Tracking actual scaling operations, cost accounting

### ScalingFailed

Dispatched when a scaling operation fails.

```php
namespace Cbox\LaravelQueueAutoscale\Events;

class ScalingFailed
{
    public function __construct(
        public readonly QueueConfiguration $config,
        public readonly \Throwable $exception,
        public readonly int $attemptedWorkers,
        public readonly int $currentWorkers
    ) {}
}
```

**When**: Scaling operation encounters an error
**Use for**: Error tracking, alerting, incident response

### WorkerHealthCheckFailed

Dispatched when a worker fails health checks.

```php
namespace Cbox\LaravelQueueAutoscale\Events;

class WorkerHealthCheckFailed
{
    public function __construct(
        public readonly WorkerProcess $worker,
        public readonly string $reason
    ) {}
}
```

**When**: Worker becomes unhealthy or unresponsive
**Use for**: Debugging, alerting, worker lifecycle tracking

## Listening to Events

### Method 1: Event Listeners

Create a dedicated listener class:

```php
<?php

namespace App\Listeners;

use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class LogScalingDecision
{
    public function handle(ScalingDecisionMade $event): void
    {
        logger()->info('Scaling decision made', [
            'queue' => $event->config->queue,
            'current_workers' => $event->currentWorkers,
            'target_workers' => $event->decision->targetWorkers,
            'reason' => $event->decision->reason,
            'confidence' => $event->decision->confidence,
            'pending_jobs' => $event->metrics->depth->pending ?? 0,
        ]);
    }
}
```

Register in `EventServiceProvider`:

```php
protected $listen = [
    \Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade::class => [
        \App\Listeners\LogScalingDecision::class,
        \App\Listeners\SendSlackNotification::class,
    ],
    \Cbox\LaravelQueueAutoscale\Events\WorkersScaled::class => [
        \App\Listeners\RecordWorkerMetrics::class,
    ],
    \Cbox\LaravelQueueAutoscale\Events\ScalingFailed::class => [
        \App\Listeners\AlertOperations::class,
    ],
];
```

### Method 2: Closure Listeners

For simple cases, use closures in a service provider:

```php
use Illuminate\Support\Facades\Event;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

public function boot(): void
{
    Event::listen(function (ScalingDecisionMade $event) {
        logger()->info('Scaling decision', [
            'queue' => $event->config->queue,
            'target' => $event->decision->targetWorkers,
        ]);
    });
}
```

### Method 3: Queued Listeners

For heavy processing, queue the listener:

```php
<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

class RecordWorkerMetrics implements ShouldQueue
{
    public function handle(WorkersScaled $event): void
    {
        // Heavy processing - runs on queue
        app(MetricsService::class)->recordScalingEvent([
            'queue' => $event->queue,
            'previous' => $event->previousCount,
            'new' => $event->newCount,
            'direction' => $event->direction,
        ]);
    }
}
```

## Event Payloads

### ScalingDecisionMade Payload

```php
$event->decision          // ScalingDecision object
$event->decision->targetWorkers
$event->decision->reason
$event->decision->confidence
$event->decision->predictedPickupTime

$event->config           // QueueConfiguration object
$event->config->connection
$event->config->queue
$event->config->maxPickupTimeSeconds
$event->config->minWorkers
$event->config->maxWorkers

$event->currentWorkers   // int

$event->metrics          // object
$event->metrics->processingRate
$event->metrics->activeWorkerCount
$event->metrics->depth->pending
$event->metrics->depth->oldestJobAgeSeconds
$event->metrics->trend->direction
$event->metrics->trend->forecast
```

### WorkersScaled Payload

```php
$event->connection       // 'redis'
$event->queue           // 'default'
$event->previousCount   // 5
$event->newCount        // 10
$event->direction       // 'up', 'down', or 'none'
```

Calculate change:

```php
$workerChange = $event->newCount - $event->previousCount;
$scalingUp = $event->direction === 'up';
$scalingDown = $event->direction === 'down';
```

### ScalingFailed Payload

```php
$event->config           // QueueConfiguration
$event->exception        // Throwable
$event->attemptedWorkers // int
$event->currentWorkers   // int
```

Access error details:

```php
$errorMessage = $event->exception->getMessage();
$stackTrace = $event->exception->getTraceAsString();
$errorClass = get_class($event->exception);
```

## Common Use Cases

### Use Case 1: Slack Notifications

Send rich Slack messages on scaling events:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Http;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class SendSlackNotification
{
    public function handle(ScalingDecisionMade $event): void
    {
        $workerChange = $event->decision->targetWorkers - $event->currentWorkers;

        if (abs($workerChange) < 5) {
            return; // Only notify for significant changes
        }

        $color = $workerChange > 0 ? '#36a64f' : '#ff9900';
        $direction = $workerChange > 0 ? '⬆️ Scaling UP' : '⬇️ Scaling DOWN';

        Http::post(config('services.slack.webhook'), [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => "{$direction}: {$event->config->queue}",
                    'fields' => [
                        [
                            'title' => 'Worker Change',
                            'value' => "{$event->currentWorkers} → {$event->decision->targetWorkers}",
                            'short' => true,
                        ],
                        [
                            'title' => 'Pending Jobs',
                            'value' => $event->metrics->depth->pending ?? 'N/A',
                            'short' => true,
                        ],
                        [
                            'title' => 'Reason',
                            'value' => $event->decision->reason,
                            'short' => false,
                        ],
                    ],
                    'footer' => 'Queue Autoscale',
                    'ts' => time(),
                ],
            ],
        ]);
    }
}
```

### Use Case 2: Metrics Collection

Send metrics to Datadog, CloudWatch, etc:

```php
<?php

namespace App\Listeners;

use App\Services\DatadogClient;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

class RecordWorkerMetrics
{
    public function __construct(
        private readonly DatadogClient $datadog
    ) {}

    public function handle(WorkersScaled $event): void
    {
        $tags = [
            "queue:{$event->queue}",
            "connection:{$event->connection}",
            "direction:{$event->direction}",
        ];

        // Record worker count
        $this->datadog->gauge('queue.autoscale.workers', $event->newCount, $tags);

        // Record worker change
        $change = $event->newCount - $event->previousCount;
        $this->datadog->gauge('queue.autoscale.worker_change', abs($change), $tags);

        // Increment scaling events
        $this->datadog->increment('queue.autoscale.events', 1, $tags);
    }
}
```

### Use Case 3: Cost Tracking

Track autoscaling costs:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

class TrackScalingCosts
{
    private const WORKER_COST_PER_HOUR = 0.50;

    public function handle(WorkersScaled $event): void
    {
        $workerChange = $event->newCount - $event->previousCount;

        if ($workerChange === 0) {
            return;
        }

        // Calculate hourly cost impact
        $costImpact = $workerChange * self::WORKER_COST_PER_HOUR;

        DB::table('autoscale_costs')->insert([
            'queue' => $event->queue,
            'connection' => $event->connection,
            'previous_workers' => $event->previousCount,
            'new_workers' => $event->newCount,
            'worker_change' => $workerChange,
            'hourly_cost_impact' => $costImpact,
            'recorded_at' => now(),
        ]);

        // Alert if cost exceeds threshold
        if ($this->getDailyCost() > 1000) {
            $this->alertFinanceTeam();
        }
    }

    private function getDailyCost(): float
    {
        return DB::table('autoscale_costs')
            ->where('recorded_at', '>=', now()->subDay())
            ->sum('hourly_cost_impact');
    }
}
```

### Use Case 4: PagerDuty Alerts

Alert on-call for failures:

```php
<?php

namespace App\Listeners;

use App\Services\PagerDutyClient;
use Cbox\LaravelQueueAutoscale\Events\ScalingFailed;

class AlertOnScalingFailure
{
    public function __construct(
        private readonly PagerDutyClient $pagerDuty
    ) {}

    public function handle(ScalingFailed $event): void
    {
        $this->pagerDuty->trigger([
            'summary' => "Autoscaling failed for queue: {$event->config->queue}",
            'severity' => 'error',
            'source' => 'laravel-queue-autoscale',
            'custom_details' => [
                'queue' => $event->config->queue,
                'connection' => $event->config->connection,
                'error' => $event->exception->getMessage(),
                'attempted_workers' => $event->attemptedWorkers,
                'current_workers' => $event->currentWorkers,
                'stack_trace' => $event->exception->getTraceAsString(),
            ],
        ]);
    }
}
```

### Use Case 5: Audit Logging

Maintain detailed audit trail:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

class AuditScalingDecisions
{
    public function handle(ScalingDecisionMade $event): void
    {
        DB::table('scaling_audit_log')->insert([
            'queue' => $event->config->queue,
            'connection' => $event->config->connection,
            'current_workers' => $event->currentWorkers,
            'target_workers' => $event->decision->targetWorkers,
            'worker_change' => $event->decision->targetWorkers - $event->currentWorkers,
            'reason' => $event->decision->reason,
            'confidence' => $event->decision->confidence,
            'predicted_pickup_time' => $event->decision->predictedPickupTime,
            'pending_jobs' => $event->metrics->depth->pending ?? null,
            'processing_rate' => $event->metrics->processingRate ?? null,
            'oldest_job_age' => $event->metrics->depth->oldestJobAgeSeconds ?? null,
            'trend_direction' => $event->metrics->trend->direction ?? null,
            'decision_metadata' => json_encode([
                'config' => $event->config,
                'metrics' => $event->metrics,
            ]),
            'created_at' => now(),
        ]);
    }
}
```

### Use Case 6: External Workflow Integration

Trigger external systems:

```php
<?php

namespace App\Listeners;

use App\Services\JenkinsClient;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

class TriggerLoadTestOnScaling
{
    public function __construct(
        private readonly JenkinsClient $jenkins
    ) {}

    public function handle(WorkersScaled $event): void
    {
        // Only for production queue
        if ($event->queue !== 'production') {
            return;
        }

        // Only when scaling up significantly
        if ($event->direction !== 'up' || $event->newCount < 20) {
            return;
        }

        // Trigger load test to verify capacity
        $this->jenkins->triggerBuild('queue-load-test', [
            'queue' => $event->queue,
            'worker_count' => $event->newCount,
            'trigger' => 'autoscale_event',
        ]);
    }
}
```

## Best Practices

### 1. Keep Listeners Fast

Listeners execute synchronously unless queued. Keep them fast:

```php
// ✅ Good: Fast operation
public function handle(ScalingDecisionMade $event): void
{
    logger()->info('Scaling decision', ['queue' => $event->config->queue]);
}

// ❌ Bad: Slow operation
public function handle(ScalingDecisionMade $event): void
{
    sleep(5);  // Don't block the autoscaling process!
}

// ✅ Good: Queue heavy work
class HeavyMetricsProcessor implements ShouldQueue
{
    public function handle(ScalingDecisionMade $event): void
    {
        // Heavy processing runs async
    }
}
```

### 2. Handle Failures Gracefully

Don't let listener exceptions break autoscaling:

```php
public function handle(ScalingDecisionMade $event): void
{
    try {
        $this->sendNotification($event);
    } catch (\Exception $e) {
        logger()->error('Notification failed', [
            'error' => $e->getMessage(),
            'queue' => $event->config->queue,
        ]);
        // Don't throw - allow autoscaling to continue
    }
}
```

### 3. Filter Events Appropriately

Don't process every event if you only care about some:

```php
public function handle(ScalingDecisionMade $event): void
{
    // Only care about production queue
    if ($event->config->queue !== 'production') {
        return;
    }

    // Only care about significant changes
    $change = abs($event->decision->targetWorkers - $event->currentWorkers);
    if ($change < 5) {
        return;
    }

    // Now process...
}
```

### 4. Use Type Hints

Laravel's event discovery works best with type hints:

```php
// ✅ Good: Type-hinted parameter
public function handle(ScalingDecisionMade $event): void
{
    // Laravel auto-discovers this
}

// ❌ Bad: No type hint
public function handle($event): void
{
    // Requires manual registration
}
```

### 5. Consider Event Order

If order matters, use policies instead:

```php
// Events: All listeners execute (order not guaranteed)
Event::listen(ScalingDecisionMade::class, Listener1::class);
Event::listen(ScalingDecisionMade::class, Listener2::class);

// Policies: Execute in defined order
'policies' => [
    Policy1::class,  // Always executes first
    Policy2::class,  // Always executes second
]
```

### 6. Test Event Listeners

```php
use Illuminate\Support\Facades\Event;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;

it('dispatches scaling decision event', function () {
    Event::fake([ScalingDecisionMade::class]);

    // Trigger autoscaling
    $this->autoscaleManager->evaluate();

    Event::assertDispatched(ScalingDecisionMade::class, function ($event) {
        return $event->config->queue === 'default'
            && $event->decision->targetWorkers > 0;
    });
});

it('sends slack notification on scaling', function () {
    Http::fake();

    $event = new ScalingDecisionMade(
        decision: new ScalingDecision(10, 'test', 0.9, 5.0),
        config: $this->config,
        currentWorkers: 5,
        metrics: (object) ['depth' => (object) ['pending' => 100]]
    );

    $listener = new SendSlackNotification();
    $listener->handle($event);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'slack.com');
    });
});
```

## Advanced Patterns

### Pattern: Event Aggregation

Collect multiple events before processing:

```php
class AggregatedMetricsCollector implements ShouldQueue
{
    public function handle(ScalingDecisionMade $event): void
    {
        Cache::remember("scaling_events:{$event->config->queue}", 300, function () {
            return collect();
        })->push([
            'timestamp' => now(),
            'workers' => $event->decision->targetWorkers,
            'pending' => $event->metrics->depth->pending ?? 0,
        ]);

        // Flush every 100 events or 5 minutes
        if ($this->shouldFlush()) {
            $this->flushToDataWarehouse();
        }
    }
}
```

### Pattern: Conditional Queueing

Queue listeners only under certain conditions:

```php
class ConditionallyQueuedListener implements ShouldQueue
{
    public function shouldQueue(ScalingDecisionMade $event): bool
    {
        // Only queue for critical queues
        return in_array($event->config->queue, ['critical', 'production']);
    }

    public function handle(ScalingDecisionMade $event): void
    {
        // Heavy processing...
    }
}
```

### Pattern: Event Replay

Store events for later replay/analysis:

```php
class EventRecorder
{
    public function handle(ScalingDecisionMade $event): void
    {
        DB::table('event_stream')->insert([
            'event_type' => ScalingDecisionMade::class,
            'event_data' => serialize($event),
            'occurred_at' => now(),
        ]);
    }
}

// Later: Replay events
$events = DB::table('event_stream')
    ->where('occurred_at', '>=', now()->subHours(24))
    ->get();

foreach ($events as $record) {
    $event = unserialize($record->event_data);
    $this->replayEvent($event);
}
```

## See Also

- [Scaling Policies](scaling-policies.md) - Alternative to events for ordered execution
- [Monitoring](monitoring.md) - Monitoring and observability
- [Custom Strategies](../advanced-usage/custom-strategies.md) - Custom scaling strategies
- [API Reference: Events](../api-reference/_index.md) - Complete event API documentation
