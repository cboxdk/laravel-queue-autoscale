# Cbox Queue Autoscale

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cboxdk/laravel-queue-autoscale.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-queue-autoscale)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-queue-autoscale/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/cboxdk/laravel-queue-autoscale/actions?query=workflow%3Atests+branch%3Amain)
[![GitHub Code Quality Action Status](https://img.shields.io/github/actions/workflow/status/cboxdk/laravel-queue-autoscale/code-quality.yml?branch=main&label=code%20quality&style=flat-square)](https://github.com/cboxdk/laravel-queue-autoscale/actions?query=workflow%3Acode-quality+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/cboxdk/laravel-queue-autoscale.svg?style=flat-square)](https://packagist.org/packages/cboxdk/laravel-queue-autoscale)

**SLA-driven autoscaling for Laravel queue workers.**

Queue Autoscale for Laravel is a long-running worker manager that spawns and terminates `queue:work`
processes to hold a pickup-time SLA. Instead of configuring worker counts, you declare how long a job
may wait before it is picked up, and the manager solves for the worker count each evaluation cycle
using queueing theory (Little's Law) and backlog-drain math, bounded by measured CPU and memory
capacity on the host.

## Features

- **SLA-based scaling** — declare a target pickup time; worker counts are derived, not configured
- **Little's Law steady state** — `workers = arrival rate × average job time` for the baseline
- **Backlog drain with progressive urgency** — a quadratic aggressiveness curve that ramps from 1.0x
  at half the SLA budget to 3.0x at the SLA target, capped at 5.0x
- **p95 pickup-time signal** — sliding-window percentile over real observed pickup times, with a
  fallback to oldest-job age when there are not enough samples
- **Spawn-latency compensation** — measured spawn time (EMA) is subtracted from the SLA budget
- **Failure fuse** — a downstream outage looks like load to any autoscaler; the fuse detects a high
  failure rate, holds the queue at `workers.min`, then probes with a single worker before releasing
- **Resource-aware** — CPU and memory ceilings measured on the host constrain every decision
- **Metrics-driven** — queue discovery and metrics come from
  [`laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics)
- **Cluster-aware** — managers auto-join via Redis, elect a leader, and distribute worker targets
  across hosts
- **Worker groups** — one worker set polling several queues in strict priority order
- **Queues matched by pattern** — `scrape-tenant-*` governs every tenant queue, so runtime-generated
  names need no configuration entry of their own
- **Configuration check** — `queue:autoscale:doctor` reports configurations that are valid and still
  govern the wrong queues
- **Testable** — fakes and assertions in `src/Testing` for proving what your own configuration does
- **Extensible** — custom scaling strategies and policies via interfaces
- **Events** — react to scaling decisions, SLA breaches, fuse transitions and cluster changes
- **Graceful shutdown** — SIGTERM, then SIGKILL after the shutdown timeout

## Requirements

- PHP 8.4 or 8.5
- Laravel 12 or 13
- `ext-pcntl` and `ext-posix` (the manager is a signal-handling daemon)
- [`cboxdk/laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics) `^3.0`

Redis is required only for cluster mode. Single-host mode works with any queue driver and needs no
Redis. [`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry) is optional and
enables the OpenTelemetry integration; it requires Laravel 12+.

## Installation

```bash
composer require cboxdk/laravel-queue-autoscale
```

Run the interactive installer to publish config, choose a topology, and generate matching `.env`
values:

```bash
php artisan queue:autoscale:install
```

It offers three presets via `--topology=`:

| Preset | Shape |
| --- | --- |
| `single-low` | single host, low traffic, no Redis infrastructure |
| `single-redis` | single host with Redis-backed metrics and predictive signals |
| `cluster` | multi-host cluster with Redis coordination |

Additional flags: `--metrics-connection=`, `--publish-migrations`, `--write-env`, `--env-file=`,
`--force`, `--no-publish`.

If you prefer the manual path, publish the config yourself:

```bash
php artisan vendor:publish --tag=queue-autoscale-config
```

### Set up the metrics package

This package does not discover queues or collect metrics itself. Both come from
[`laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics), which is installed as a
dependency:

```bash
php artisan vendor:publish --tag=queue-metrics-config
```

Configure its storage backend in `.env`:

```env
# Option A: Redis (fast, in-memory)
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default

# Option B: Database (persistent)
QUEUE_METRICS_STORAGE=database
```

If you use database storage, publish and run its migrations:

```bash
php artisan vendor:publish --tag=queue-metrics-migrations
php artisan migrate
```

### Choose an autoscale topology

```env
# Single host without Redis
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto

# Single host with Redis-backed predictive signals
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=redis

# Multi-host cluster
QUEUE_AUTOSCALE_CLUSTER_ENABLED=true
```

`auto` keeps single-host mode Redis-free and switches to Redis-backed coordination in cluster mode.
The installer can write these values into `.env` for you with `--write-env`.

## Quick Start

### 1. Configure SLA targets (optional)

By default `sla_defaults` is `BalancedProfile::class` — a 30-second p95 pickup-time target with
1–10 workers. If that suits you, there is nothing to configure.

To customise, edit `config/queue-autoscale.php`. Each entry is **either** a `ProfileContract` class
**or** a literal array that is deep-merged over `sla_defaults`:

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ConnectionLimitedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

return [
    'enabled' => true,

    // Shipped profiles: BalancedProfile, CriticalProfile, HighVolumeProfile,
    // BurstyProfile, BackgroundProfile, ExclusiveProfile, ConnectionLimitedProfile.
    'sla_defaults' => BalancedProfile::class,

    'queues' => [
        // A profile class...
        'payments' => CriticalProfile::class,

        // ...or a partial override merged over sla_defaults.
        'reports' => [
            'sla' => ['target_seconds' => 120],
            'workers' => ['min' => 0, 'max' => 4],
        ],

        // ...or a glob, for queue names generated at runtime. An exact name
        // above always wins over a pattern.
        'scrape-tenant-*' => [
            'profile' => ConnectionLimitedProfile::class,
            'workers' => ['max' => 5],
        ],
    ],
];
```

A per-queue entry does **not** accept `'profile'` and `'overrides'` keys — those belong to `groups`
only. See [Workload Profiles](docs/basic-usage/workload-profiles.md).

### 2. Run the autoscaler

```bash
php artisan queue:autoscale

# Custom evaluation interval (default: 5 seconds)
php artisan queue:autoscale --interval=10

# Stop the existing local manager and take over its host lock
php artisan queue:autoscale --replace

# Inspect cluster leader, hosts, capacity and workload targets
php artisan queue:autoscale:cluster

# Inspect one queue's raw state, metrics and fuse status
php artisan queue:autoscale:debug --queue=payments

# Check the configuration against the queues that actually exist
php artisan queue:autoscale:doctor

# Signal running managers to restart gracefully (use this on deploy)
php artisan queue:autoscale:restart
```

Each cycle the manager:

1. Pulls queue metrics from `laravel-queue-metrics`
2. Runs the configured strategy to get a target worker count
3. Constrains that target by measured host CPU/memory capacity
4. Clamps it to `workers.min` / `workers.max`
5. Applies the failure fuse
6. Runs the registered policies over the resulting decision
7. Spawns or terminates workers, and dispatches events

### 3. Monitor with events

```php
use Cbox\LaravelQueueAutoscale\Events\SlaBreachPredicted;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(WorkersScaled::class, function (WorkersScaled $event): void {
    Log::info("Scaled {$event->queue}: {$event->from} → {$event->to} workers");
    Log::info("Reason: {$event->reason}");
});

Event::listen(SlaBreachPredicted::class, function (SlaBreachPredicted $event): void {
    $decision = $event->decision;

    Log::warning("SLA breach predicted for {$decision->queue}", [
        'predicted_pickup' => $decision->predictedPickupTime,
        'sla_target' => $decision->slaTarget,
    ]);
});
```

## How It Works

### The hybrid strategy

`HybridStrategy` (the default) computes two candidate worker counts and takes the **maximum**:

**1. Steady state — Little's Law**

```text
workers = arrivalRate × avgJobTime
```

The arrival rate comes from `ArrivalRateEstimator`, which tracks backlog deltas and blends in a
forecast. It is used only when its confidence clears `scaling.min_arrival_rate_confidence` (0.5);
otherwise the observed processing rate (`throughputPerMinute / 60`) is used instead. When the
failure rate exceeds 5%, an estimated retry volume is subtracted so that retries are not counted as
new arrivals.

**2. Backlog drain — SLA protection**

```text
slaProgress   = min(slaSignal / effectiveSla, 1.5)
baseWorkers   = backlog / max((effectiveSla - slaSignal) / avgJobTime, 1.0)
multiplier    = min(1.0 + 8.0 × (slaProgress - 0.5)², 5.0)
workers       = baseWorkers × multiplier
```

This returns zero until `slaProgress` reaches `scaling.breach_threshold` (default `0.5`, i.e. half
the SLA budget consumed). The multiplier then ramps continuously: 1.0x at 50%, ~1.72x at 80%,
3.0x at 100%, capped at 5.0x. `effectiveSla` is the SLA target minus the measured spawn latency, and
`slaSignal` is the p95 of recent observed pickup times, falling back to oldest-job age when there
are too few samples.

The larger of the two wins. A saturation guard then bumps the target to `activeWorkers + 1` if
workers are above 90% utilisation but neither calculation asked for more. Finally the target is
clamped to `[workers.min, workers.max]` and passed through a hysteresis smoother that limits
scale-down to one worker per cycle while throughput is stable.

### Constraints applied to every decision

- **Host capacity** — CPU and memory ceilings from measured system metrics, expressed as
  `currentWorkers + additional headroom` and reduced by what other queues on the host already use
- **Config bounds** — `workers.min` and `workers.max` from the queue's profile
- **Failure fuse** — holds a queue at `workers.min` while its failure rate is above threshold
- **Anti-flapping cooldown** — `scaling.cooldown_seconds` (default 60) blocks only a *scale-down*,
  and only while the window opened by a recent scale-up is still running; scaling further in the
  same direction is always allowed, and a scale-up is never held

See the [Architecture](docs/algorithms/architecture.md) deep dive for the full derivation.

## Configuration Reference

The published `config/queue-autoscale.php` is documented inline. The keys most people touch:

```php
'sla_defaults' => BalancedProfile::class,   // ProfileContract class or literal array
'queues' => [],                             // per-queue: profile class or partial override array
'excluded' => [],                           // fnmatch globs never managed, e.g. 'legacy-*'
'groups' => [],                             // multi-queue workers with strict priority

'scaling' => [
    'fallback_job_time_seconds' => 2.0,     // used when metrics have no avg duration
    'breach_threshold' => 0.5,              // fraction of SLA budget before backlog drain engages
    'cooldown_seconds' => 60,               // anti-flapping window for downward reversals
],

'limits' => [
    'max_cpu_percent' => 85,
    'max_memory_percent' => 85,
    'worker_memory_mb_estimate' => 128,     // cold-start estimate; measured data wins once available
    'worker_cpu_core_estimate' => 0.2,
    'reserve_cpu_cores' => 0.2,
],

'manager' => [
    // Fallback drain window for workers whose queue config no longer resolves.
    'shutdown_grace_seconds' => 30,
],

'strategy' => HybridStrategy::class,        // a plain class string, not an array

'policies' => [
    ConservativeScaleDownPolicy::class,
    BreachNotificationPolicy::class,
],
```

A per-queue entry may also carry a `resources` block declaring cold-start CPU/memory estimates for
that queue's workers:

```php
'queues' => [
    'video-encode' => [
        'workers' => ['min' => 0, 'max' => 4],
        'resources' => ['cpu_cores' => 1.0, 'memory_mb' => 2048],
    ],
],
```

`manager.evaluation_interval_seconds` (default 5) sets the evaluation interval;
`queue:autoscale --interval=` overrides it for a single process.

Every key, including the profile, forecast, fuse, pickup-time, spawn-latency, cluster, alerting and
telemetry blocks, is covered in [Configuration](docs/basic-usage/configuration.md).

## Custom Scaling Strategies

A strategy answers one question: how many workers should this queue have right now?

```php
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

final class CustomStrategy implements ScalingStrategyContract
{
    private int $lastTarget = 0;

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        // avgDuration is already in seconds by the time a strategy sees it.
        $jobsPerSecond = $metrics->throughputPerMinute / 60.0;
        $target = (int) ceil($jobsPerSecond * max($metrics->avgDuration, 0.1) * 2);

        return $this->lastTarget = max(
            $config->workers->min,
            min($config->workers->max, $target),
        );
    }

    public function getLastReason(): string
    {
        return "Custom strategy: doubled steady-state demand → {$this->lastTarget} workers";
    }

    public function getLastPrediction(): ?float
    {
        return null; // Optional: predicted pickup time in seconds
    }
}
```

Register it as a plain class string:

```php
'strategy' => \App\Scaling\CustomStrategy::class,
```

The engine still applies capacity limits, config bounds and the fuse on top of whatever a strategy
returns. See [Custom Strategies](docs/advanced-usage/custom-strategies.md).

## Scaling Policies

A policy runs *after* the strategy and engine have produced a `ScalingDecision`. `beforeScaling()`
may return a modified decision (or `null` to leave it alone); `afterScaling()` observes the result.

```php
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

final class BusinessHoursFloorPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        if (! $decision->shouldScaleDown() || ! now()->isWeekday()) {
            return null;
        }

        if ($decision->targetWorkers >= 2) {
            return null;
        }

        return new ScalingDecision(
            connection: $decision->connection,
            queue: $decision->queue,
            currentWorkers: $decision->currentWorkers,
            targetWorkers: 2,
            reason: "business-hours floor of 2 applied (was: {$decision->reason})",
            predictedPickupTime: $decision->predictedPickupTime,
            slaTarget: $decision->slaTarget,
        );
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        //
    }
}
```

Register **class strings** — the loader resolves each through the container, so constructor
injection works. An instance or closure placed in this array is silently ignored:

```php
'policies' => [
    \App\Policies\BusinessHoursFloorPolicy::class,
],
```

Policies are chained: a non-null return becomes the decision the next policy sees. An exception
thrown by a policy is caught and logged, and scaling continues. See
[Scaling Policies](docs/basic-usage/scaling-policies.md).

## Events

All events live in `Cbox\LaravelQueueAutoscale\Events`.

| Event | Properties |
| --- | --- |
| `ScalingDecisionMade` | `decision` |
| `SlaBreachPredicted` | `decision` |
| `WorkersScaled` | `connection`, `queue`, `from`, `to`, `action`, `reason` |
| `SlaBreached` | `connection`, `queue`, `oldestJobAge`, `slaTarget`, `pending`, `activeWorkers` |
| `SlaRecovered` | `connection`, `queue`, `currentJobAge`, `slaTarget`, `pending`, `activeWorkers` |
| `FuseTripped` | `connection`, `queue`, `failureRate`, `samples`, `failures`, `thresholdPercent`, `heldAtWorkers` |
| `FuseProbing` | `connection`, `queue`, `probeWorkers`, `cooldownSeconds` |
| `FuseRecovered` | `connection`, `queue`, `failureRate`, `samples` |
| `AutoscaleManagerStarted` | `managerId`, `host`, `clusterEnabled`, `clusterId`, `intervalSeconds`, `startedAt`, `packageVersion` |
| `AutoscaleManagerStopped` | `managerId`, `host`, `clusterEnabled`, `clusterId`, `startedAt`, `stoppedAt`, `reason`, `workerCount`, `packageVersion` |
| `ClusterLeaderChanged` | `clusterId`, `previousLeaderId`, `currentLeaderId`, `observedByManagerId`, `changedAt` |
| `ClusterManagerPresenceChanged` | `clusterId`, `managerIds`, `addedManagerIds`, `removedManagerIds`, `leaderId`, `observedByManagerId`, `observedAt` |
| `ClusterSummaryPublished` | `clusterId`, `leaderId`, `summary`, `publishedAt` |

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

Event::listen(ScalingDecisionMade::class, function (ScalingDecisionMade $event): void {
    $decision = $event->decision;

    Log::info('Scaling decision', [
        'queue' => $decision->queue,
        'current' => $decision->currentWorkers,
        'target' => $decision->targetWorkers,
        'action' => $decision->action(),   // 'scale_up' | 'scale_down' | 'hold'
        'reason' => $decision->reason,
    ]);
});

Event::listen(WorkersScaled::class, function (WorkersScaled $event): void {
    Metrics::gauge('queue.workers', $event->to, [
        'queue' => $event->queue,
        'action' => $event->action,        // 'up' | 'down'
    ]);
});
```

`WorkersScaled::$action` is the literal string `'up'` or `'down'`. `ScalingDecision::action()` uses a
different vocabulary (`'scale_up'`, `'scale_down'`, `'hold'`) — do not mix them up. There is no
`confidence` property on `ScalingDecision`, and no worker-health event.

See [Event Handling](docs/basic-usage/event-handling.md).

## Running as a Daemon

The manager is a long-running process. Run exactly one per app in single-host mode, and exactly one
per host in cluster mode. Use Supervisor to keep it alive:

```ini
[program:queue-autoscale]
command=php /path/to/artisan queue:autoscale --interval=5
directory=/path/to/project
user=www-data
autostart=true
autorestart=true
stopsignal=TERM
redirect_stderr=true
stdout_logfile=/path/to/logs/autoscale.log
```

On deploy, restart it through Artisan so it drains its workers before Supervisor starts the new
release:

```bash
php artisan queue:autoscale:restart
```

With `manager.honor_queue_restart` enabled (the default), a plain `php artisan queue:restart` also
stops the manager gracefully, so a standard deploy pipeline needs no extra step.

See [Deployment](docs/deployment/_index.md) for Forge, Ploi, Docker and self-hosted recipes.

## Metrics Integration

**This package does not discover queues or collect metrics itself.** Both come from
[`laravel-queue-metrics`](https://github.com/cboxdk/laravel-queue-metrics):

```php
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

$metrics = QueueMetrics::getQueueMetrics('redis', 'payments');

echo "Queue: {$metrics->connection}/{$metrics->queue}\n";
echo "Pending: {$metrics->pending} jobs\n";
echo "Oldest job age: {$metrics->oldestJobAge}s\n";
echo "Throughput: {$metrics->throughputPerMinute} jobs/min\n";
echo "Avg duration: {$metrics->avgDuration}\n";
echo "Active workers: {$metrics->activeWorkers}\n";
```

`QueueMetrics::getAllQueuesWithMetrics()` returns a keyed array of raw metric arrays; use
`getQueueMetrics()` when you want the `QueueMetricsData` object.

**Division of responsibility**

| [laravel-queue-metrics](https://github.com/cboxdk/laravel-queue-metrics) | laravel-queue-autoscale |
| --- | --- |
| Scans configured queue connections | Applies the scaling algorithms |
| Discovers active queues | Makes SLA-based scaling decisions |
| Collects depth, age and duration metrics | Manages the worker pool lifecycle |
| Calculates throughput and failure rates | Enforces CPU/memory constraints |
| Tracks worker heartbeats | Runs policies and dispatches events |

## OpenTelemetry via laravel-telemetry

When [`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry) is installed, the
autoscaler publishes its scaling signals automatically — no configuration needed. Disable with
`QUEUE_AUTOSCALE_TELEMETRY_ENABLED=false`.

When it is not installed, `queue:autoscale:debug` reports `Telemetry: not installed` and everything
else carries on unchanged — the integration is optional, not a dependency.

| Metric | Type | Unit | Labels |
| --- | --- | --- | --- |
| `queue_autoscale.workers.target` | gauge | `{workers}` | `connection`, `queue` |
| `queue_autoscale.sla.predicted_pickup` | gauge | `s` | `connection`, `queue` |
| `queue_autoscale.sla.target` | gauge | `s` | `connection`, `queue` |
| `queue_autoscale.sla.breach` | gauge | `1` | `connection`, `queue` |
| `queue_autoscale.capacity.max_workers` | gauge | `{workers}` | `limiter` |
| `queue_autoscale.fuse.state` | gauge | `1` | `connection`, `queue` |
| `queue_autoscale.scaling.actions` | counter | `{actions}` | `connection`, `queue`, `direction` |
| `queue_autoscale.sla.breaches` | counter | `{breaches}` | `connection`, `queue` |
| `queue_autoscale.fuse.trips` | counter | `{trips}` | `connection`, `queue` |
| `queue_autoscale.cluster.leader_changes` | counter | `{changes}` | — |
| `queue_autoscale.cluster.managers` | gauge (observable) | `{managers}` | — |
| `queue_autoscale.cluster.workers` | gauge (observable) | `{workers}` | — |
| `queue_autoscale.cluster.required_workers` | gauge (observable) | `{workers}` | — |
| `queue_autoscale.cluster.worker_capacity` | gauge (observable) | `{workers}` | — |
| `queue_autoscale.cluster.utilization` | gauge (observable) | `%` | — |
| `queue_autoscale.cluster.recommended_hosts` | gauge (observable) | `{hosts}` | — |
| `queue_autoscale.cluster.host_workers` | gauge (observable) | `{workers}` | `host` |
| `queue_autoscale.cluster.host_capacity` | gauge (observable) | `{workers}` | `host` |

Scaling actions, SLA breaches and recoveries, fuse transitions, manager start/stop and cluster leader
changes are also emitted as structured OTLP events (`queue_autoscale.scaling.action`,
`queue_autoscale.sla.breached`, `queue_autoscale.fuse.tripped`, …) carrying the full context —
including the scaling `reason`, which is deliberately not a metric label.

Deliberately **not** exported: queue depth, oldest-job age, health scores, worker busy/idle state and
job baselines (owned by `cboxdk/laravel-queue-metrics`), and per-job durations/outcomes (covered by
laravel-telemetry's own queue instrumentation). There is no active-worker gauge here —
queue-metrics' `queue_metrics.queue.active_workers` gauge is the one to join against
`queue_autoscale.workers.target` in your dashboards.

Metrics are shipped to your OTLP endpoint by the telemetry package's `telemetry:flush` (cron or
`--daemon`) — make sure one is scheduled.

## Testing

### Testing your own configuration

The package ships fakes and assertions so an application can prove what its queues will do, without
Redis and without waiting for load:

```php
use Cbox\LaravelQueueAutoscale\Testing\InteractsWithAutoscaling;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;

uses(InteractsWithAutoscaling::class);

test('no tenant ever gets a sixth connection', function () {
    $this->assertWorkersCappedAt(5, 'scrape-tenant-42');
});

test('a failing provider stops the queue scaling up', function () {
    $behind = QueueMetricsFactory::behind(1000, oldestJobAge: 300, queue: 'payments');

    $this->tripFuseFor('payments');

    expect($this->workersDemandedFor($behind))->toBe(0);
});
```

See [Testing Your Configuration](docs/basic-usage/testing.md).

### Testing the package itself

```bash
composer test              # run the suite
composer test-coverage     # run with coverage
composer analyse           # PHPStan / Larastan
vendor/bin/pint            # code style
```

SQS and FIFO specs run against [ElasticMQ](https://github.com/softwaremill/elasticmq) and skip when
it is not running:

```bash
docker run -d --name autoscale-elasticmq -p 9324:9324 softwaremill/elasticmq-native:1.6.11
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

Please see the [Contributing Guide](docs/advanced-usage/contributing.md) for details.

## Security

Please report security issues privately through
[GitHub Private Vulnerability Reporting](https://github.com/cboxdk/laravel-queue-autoscale/security/advisories/new)
rather than the public issue tracker. This is a community-maintained package; reports are handled on
a best-effort basis, and fixes land on the current major line. See
[Security](docs/advanced-usage/security.md).

## Credits

- [Sylvester Damgaard](https://github.com/sylvesterdamgaard)
- [All Contributors](../../contributors)

## Resources

### Documentation

- **[Introduction](docs/index.md)** — what the package is and when to reach for it
- **[Quick Start](docs/quickstart.md)** — one queue autoscaled in five minutes
- **[Installation](docs/basic-usage/installation.md)** — full install and configuration walkthrough
- **[Architecture](docs/algorithms/architecture.md)** — deep dive into the algorithms and system design
- **[Troubleshooting](docs/basic-usage/troubleshooting.md)** — common issues and debugging
- **[examples/README.md](examples/README.md)** — templates for custom strategies and policies

### Examples

- **Custom strategies**
  - [TimeBasedStrategy](examples/Strategies/TimeBasedStrategy.php) — scale on time-of-day patterns
  - [CostOptimizedStrategy](examples/Strategies/CostOptimizedStrategy.php) — conservative scaling
- **Custom policies**
  - [SlackNotificationPolicy](examples/Policies/SlackNotificationPolicy.php) — Slack alerts on scaling events
  - [MetricsLoggingPolicy](examples/Policies/MetricsLoggingPolicy.php) — log detailed metrics to a dedicated file

> `examples/config-examples.php` is written against the current schema. The strategy and policy
> classes implement the real contracts and are meant to be adapted, not dropped in as-is. The
> authoritative reference is [the documentation](docs/index.md).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
