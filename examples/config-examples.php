<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\HighVolumeProfile;
use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;

/**
 * Queue Autoscale for Laravel — configuration examples
 *
 * Copy the parts you need into config/queue-autoscale.php. Only one array can
 * be returned from a config file, so the first example is live PHP and the
 * rest are commented shapes.
 *
 * See docs/basic-usage/configuration.md for the full reference and
 * docs/basic-usage/workload-profiles.md for what each profile sets.
 */

// ============================================================================
// Example 1 — a customer-facing app with mixed workloads
// ============================================================================
// Payments must be picked up fast, email can wait, and report generation is
// background work that may stand fully down when idle.
return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    // The baseline every queue inherits unless it names its own profile.
    'sla_defaults' => BalancedProfile::class,

    'queues' => [
        'payments' => CriticalProfile::class,
        'emails' => HighVolumeProfile::class,
        'reports' => BackgroundProfile::class,

        // A partial override deep-merges on top of sla_defaults. Note that
        // 'profile' and 'overrides' keys are honoured only for GROUPS — a
        // per-queue entry is either a profile class or the override array
        // itself, as here.
        'exports' => [
            'sla' => ['target_seconds' => 120],
            'workers' => ['min' => 0, 'max' => 6],
        ],

        // A queue whose downstream is flaky enough to want a looser fuse.
        'webhooks' => [
            'fuse' => [
                'failure_threshold_percent' => 70.0,
                'window_seconds' => 120,
            ],
        ],
    ],

    // Queues supervised elsewhere. fnmatch globs.
    'excluded' => [
        'horizon-*',
    ],

    // One worker set polling several queues in strict priority order, so idle
    // capacity on 'push' absorbs a burst on 'sms' without paying spawn
    // latency. A queue may appear here OR in 'queues', never both.
    'groups' => [
        'notifications' => [
            'queues' => ['sms', 'push'],
            'connection' => 'redis',
            'profile' => BalancedProfile::class,
            'overrides' => [
                'workers' => ['min' => 1, 'max' => 12],
            ],
        ],
    ],

    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
        'worker_memory_mb_estimate' => 128,
        'worker_cpu_core_estimate' => 0.2,
        'reserve_cpu_cores' => 0.2,

        // Hard host-wide ceiling across every queue and group. Worth setting
        // whenever queue names are dynamic (per-tenant, per-deploy): queues
        // are discovered from metrics, and each discovered queue is raised to
        // its floor.
        'max_total_workers' => 60,
    ],

    'strategy' => HybridStrategy::class,

    'policies' => [
        ConservativeScaleDownPolicy::class,
        BreachNotificationPolicy::class,
    ],
];

/*
|------------------------------------------------------------------------------
| Example 2 — bursty, webhook-driven traffic
|------------------------------------------------------------------------------
| Long idle stretches punctuated by spikes. BurstyProfile allows scale-to-zero
| and widens the fuse window, because a short window can be empty between
| bursts and never reach min_samples.
|
|   'sla_defaults' => BurstyProfile::class,
|
| Mind the scale-from-zero cost: the evaluation interval plus worker spawn
| latency puts a floor of roughly 8-12 seconds on first pickup, so do not pair
| scale-to-zero with an SLA target below that.
|
|------------------------------------------------------------------------------
| Example 3 — a queue that must run strictly sequentially
|------------------------------------------------------------------------------
| A legacy integration where two workers racing would corrupt state.
| ExclusiveProfile pins the queue at exactly one worker: the package becomes a
| process supervisor for it and never makes a scaling decision. The failure
| fuse is off for the same reason — there is no scale-up to hold back.
|
|   'queues' => [
|       'legacy-sync' => ExclusiveProfile::class,
|   ],
|
|------------------------------------------------------------------------------
| Example 4 — multiple hosts behind one Redis
|------------------------------------------------------------------------------
| Managers auto-join, elect a leader, and receive per-host recommendations.
| Redis is required for this; single-host mode is not.
|
|   'cluster' => [
|       'enabled' => true,
|       'heartbeat_ttl_seconds' => 15,
|       'leader_lease_seconds' => 15,
|   ],
|
| Give each host a stable, UNIQUE identity — two hosts sharing one manager_id
| overwrite each other's heartbeat and both spawn the full target.
|
|   'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID'),
|
|------------------------------------------------------------------------------
| Example 5 — custom strategy and policies
|------------------------------------------------------------------------------
| See examples/Strategies and examples/Policies for implementations, and
| docs/advanced-usage/custom-strategies.md for the contracts.
|
|   'strategy' => \App\QueueAutoscale\Strategies\TimeBasedStrategy::class,
|
|   'policies' => [
|       ConservativeScaleDownPolicy::class,
|       \App\QueueAutoscale\Policies\SlackNotificationPolicy::class,
|   ],
|
| Policies are resolved from a config LIST of class strings — an instance
| passed here is silently dropped. They also run AFTER bounds, capacity and
| the fuse have been applied, and a target a policy returns is not re-clamped.
*/
