<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
    'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Default Profile (per-queue settings)
    |--------------------------------------------------------------------------
    |
    | Provide a ProfileContract class OR a literal array matching the shape
    | returned by BalancedProfile::resolve(). See docs/upgrade-guide-v2.md
    | for migration details from v1.
    |
    */
    'sla_defaults' => BalancedProfile::class,

    /*
    |--------------------------------------------------------------------------
    | Per-queue overrides
    |--------------------------------------------------------------------------
    |
    | Each value can be a ProfileContract class OR an array of partial overrides
    | that merges with sla_defaults.
    |
    */
    'queues' => [
        // 'payments' => CriticalProfile::class,
        // 'custom' => ['sla' => ['target_seconds' => 45]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded queues
    |--------------------------------------------------------------------------
    |
    | Queue name patterns (fnmatch globs) that this package will never manage.
    | Use this for queues supervised elsewhere (e.g. by Horizon), throwaway
    | queues, or queues you want to run manually with a fixed worker count.
    |
    | Examples: 'legacy-*', 'test-?', 'horizon-managed'
    |
    */
    'excluded' => [
        // 'legacy-*',
        // 'horizon-managed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker groups (multi-queue workers with strict priority)
    |--------------------------------------------------------------------------
    |
    | A group runs one set of workers that polls multiple queues in priority
    | order (first listed = highest priority). Use groups when several queues
    | share a workload budget and you want idle workers in one queue to absorb
    | bursts on another without paying spawn latency.
    |
    | A queue name may appear EITHER under 'queues' OR inside a group's
    | 'queues' list, never both. Start-up validation will fail if it does.
    |
    | Supported 'mode' values: 'priority' (the only mode in v2).
    |
    | Example:
    |     'notifications' => [
    |         'queues' => ['email', 'sms', 'push'],
    |         'profile' => BalancedProfile::class,
    |         'connection' => 'redis',
    |         'mode' => 'priority',
    |     ],
    |
    */
    'groups' => [
        // 'notifications' => [
        //     'queues' => ['email', 'sms', 'push'],
        //     'profile' => BalancedProfile::class,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pickup time storage (global)
    |--------------------------------------------------------------------------
    |
    | Controls the optional shared store used for p95 pickup-time forecasting.
    |
    | Supported values:
    | - 'auto'  => Redis in cluster mode, null/no-op in single-host mode
    | - 'redis' => force RedisPickupTimeStore
    | - 'null'  => disable shared pickup-time persistence
    | - FQCN    => custom PickupTimeStoreContract implementation
    |
    */
    'pickup_time' => [
        'store' => env('QUEUE_AUTOSCALE_PICKUP_TIME_STORE', 'auto'),
        'percentile_calculator' => SortBasedPercentileCalculator::class,
        'max_samples_per_queue' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Spawn latency tracker (global)
    |--------------------------------------------------------------------------
    |
    | Controls the optional cross-process tracker used for spawn compensation.
    |
    | Supported values:
    | - 'auto'  => Redis in cluster mode, null/fallback in single-host mode
    | - 'redis' => force EMA latency tracking in Redis
    | - 'null'  => disable shared spawn-latency tracking
    | - FQCN    => custom SpawnLatencyTrackerContract implementation
    |
    */
    'spawn_latency' => [
        'tracker' => env('QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scaling algorithm tuning (global)
    |--------------------------------------------------------------------------
    */
    'scaling' => [
        'fallback_job_time_seconds' => env('QUEUE_AUTOSCALE_FALLBACK_JOB_TIME', 2.0),
        'breach_threshold' => 0.5,
        'cooldown_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource limits (global)
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
        'worker_memory_mb_estimate' => 128,
        'reserve_cpu_cores' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker configuration
    |--------------------------------------------------------------------------
    |
    | Settings for spawned queue workers. These control how queue:work
    | processes are started by the autoscale manager.
    |
    */
    'workers' => [
        'timeout_seconds' => 3600,
        'tries' => 3,
        'sleep_seconds' => 3,
        'shutdown_timeout_seconds' => 30,
        'health_check_interval_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manager process
    |--------------------------------------------------------------------------
    */
    'manager' => [
        'evaluation_interval_seconds' => 5,
        'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cluster coordination
    |--------------------------------------------------------------------------
    |
    | Single-host mode does NOT require Redis and continues to work with any
    | supported queue driver (database, redis, SQS, etc.).
    |
    | Enable this when you run the autoscale manager on multiple hosts against
    | the same queues. Managers auto-join the cluster via Redis, elect a
    | leader, publish local host capacity/state, and receive per-host worker
    | recommendations from the leader.
    |
    | Redis is required for cluster mode.
    |
    */
    'cluster' => [
        'enabled' => env('QUEUE_AUTOSCALE_CLUSTER_ENABLED', false),
        'heartbeat_ttl_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_HEARTBEAT_TTL', 15),
        'leader_lease_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_LEADER_LEASE', 15),
        'recommendation_ttl_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_RECOMMENDATION_TTL', 30),
        'summary_ttl_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_SUMMARY_TTL', 30),
    ],

    'strategy' => HybridStrategy::class,

    'policies' => [
        ConservativeScaleDownPolicy::class,
        BreachNotificationPolicy::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert rate limiting
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) to suppress repeated alerts for the same queue
    | and signal. Applies to the built-in BreachNotificationPolicy and any
    | listener that injects the AlertRateLimiter.
    |
    | A breach that stays active will log once, then go quiet for this many
    | seconds. Flapping alerts are suppressed too.
    |
    */
    'alerting' => [
        'cooldown_seconds' => env('QUEUE_AUTOSCALE_ALERT_COOLDOWN', 300),
    ],
];
