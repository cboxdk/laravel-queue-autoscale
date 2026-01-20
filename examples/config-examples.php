<?php

/**
 * Advanced configuration examples for Laravel Queue Autoscale
 *
 * These examples show different configuration patterns for common use cases.
 * Copy relevant sections to your config/queue-autoscale.php file.
 */

// ============================================================================
// Example 1: High-Traffic E-commerce Site
// ============================================================================
// Characteristics: Predictable daily patterns, strict SLAs, cost-conscious
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 15,  // Fast pickup for customer-facing jobs
        'min_workers' => 2,                 // Always ready for traffic
        'max_workers' => 20,                // Cap at reasonable limit
        'scale_cooldown_seconds' => 30,     // Quick response to traffic changes
    ],

    'strategy' => \App\QueueAutoscale\Strategies\TimeBasedStrategy::class,

    'policies' => [
        \App\QueueAutoscale\Policies\SlackNotificationPolicy::class,
        \App\QueueAutoscale\Policies\MetricsLoggingPolicy::class,
    ],

    'queue_overrides' => [
        'emails' => [
            'max_pickup_time_seconds' => 60,  // Less critical
            'max_workers' => 5,                // Limited resources
        ],
        'orders' => [
            'max_pickup_time_seconds' => 5,   // Critical priority
            'min_workers' => 5,                // Always high capacity
            'max_workers' => 30,               // Allow burst capacity
        ],
    ],
];

// ============================================================================
// Example 2: Cost-Optimized Startup
// ============================================================================
// Characteristics: Limited budget, acceptable latency, variable traffic
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 120,  // Relaxed SLA for cost savings
        'min_workers' => 0,                 // Scale to zero when idle
        'max_workers' => 5,                 // Keep costs low
        'scale_cooldown_seconds' => 180,    // Avoid frequent scaling
    ],

    'strategy' => \App\QueueAutoscale\Strategies\CostOptimizedStrategy::class,

    'policies' => [],  // Minimal overhead

    'evaluation_interval_seconds' => 30,  // Less frequent checks
];

// ============================================================================
// Example 3: Enterprise Multi-Tenant SaaS
// ============================================================================
// Characteristics: Multiple priority tiers, complex routing, strict SLAs
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 3,
        'max_workers' => 50,
        'scale_cooldown_seconds' => 60,
    ],

    'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class,

    'policies' => [
        \App\QueueAutoscale\Policies\MetricsLoggingPolicy::class,
        \App\QueueAutoscale\Policies\SlackNotificationPolicy::class,
    ],

    'queue_overrides' => [
        // Enterprise tier: premium SLA
        'tenant-enterprise' => [
            'max_pickup_time_seconds' => 10,
            'min_workers' => 10,
            'max_workers' => 100,
            'scale_cooldown_seconds' => 30,
        ],
        // Pro tier: standard SLA
        'tenant-pro' => [
            'max_pickup_time_seconds' => 30,
            'min_workers' => 5,
            'max_workers' => 25,
        ],
        // Free tier: relaxed SLA
        'tenant-free' => [
            'max_pickup_time_seconds' => 300,
            'min_workers' => 1,
            'max_workers' => 5,
            'scale_cooldown_seconds' => 300,
        ],
    ],
];

// ============================================================================
// Example 4: Media Processing Platform
// ============================================================================
// Characteristics: Resource-intensive jobs, variable processing times, burst traffic
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 60,   // Jobs take long anyway
        'min_workers' => 1,
        'max_workers' => 10,                // Limited by CPU/memory
        'scale_cooldown_seconds' => 120,    // Let jobs finish before reassessing
    ],

    'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class,

    'queue_overrides' => [
        'video-encoding' => [
            'max_workers' => 4,  // Very CPU intensive
            'scale_cooldown_seconds' => 300,
        ],
        'image-processing' => [
            'max_workers' => 8,  // Less intensive
        ],
        'thumbnail-generation' => [
            'max_workers' => 15,  // Light work
            'scale_cooldown_seconds' => 60,
        ],
    ],
];

// ============================================================================
// Example 5: Real-Time Analytics Pipeline
// ============================================================================
// Characteristics: High throughput, real-time requirements, predictable patterns
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 5,    // Near real-time
        'min_workers' => 10,                // Always ready
        'max_workers' => 100,               // High capacity
        'scale_cooldown_seconds' => 15,     // Rapid response
    ],

    'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class,

    'policies' => [
        \App\QueueAutoscale\Policies\MetricsLoggingPolicy::class,
    ],

    'evaluation_interval_seconds' => 3,  // Frequent evaluation

    'queue_overrides' => [
        'analytics-realtime' => [
            'max_pickup_time_seconds' => 2,
            'min_workers' => 20,
        ],
        'analytics-batch' => [
            'max_pickup_time_seconds' => 60,
            'min_workers' => 5,
        ],
    ],
];

// ============================================================================
// Example 6: Development/Staging Environment
// ============================================================================
// Characteristics: Low traffic, testing focus, cost minimization
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 300,  // Relaxed for dev
        'min_workers' => 0,                 // Scale to zero
        'max_workers' => 2,                 // Minimal resources
        'scale_cooldown_seconds' => 300,    // Infrequent scaling
    ],

    'strategy' => \App\QueueAutoscale\Strategies\CostOptimizedStrategy::class,

    'policies' => [
        \App\QueueAutoscale\Policies\MetricsLoggingPolicy::class,  // For debugging
    ],

    'evaluation_interval_seconds' => 60,  // Infrequent checks
];

// ============================================================================
// Example 7: Hybrid Strategy with Time-Based Overrides
// ============================================================================
// Characteristics: Predictive during business hours, cost-optimized otherwise
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 1,
        'max_workers' => 20,
        'scale_cooldown_seconds' => 60,
    ],

    // Use custom strategy that switches based on time
    'strategy' => new class implements \Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract
    {
        private string $lastReason = '';

        private ?float $lastPrediction = null;

        public function calculateTargetWorkers(object $metrics, $config): int
        {
            $hour = (int) now()->format('H');

            // Business hours: 9am-5pm use predictive
            if ($hour >= 9 && $hour < 17) {
                $strategy = app(\Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class);
                $workers = $strategy->calculateTargetWorkers($metrics, $config);
                $this->lastReason = 'Business hours (predictive): '.$strategy->getLastReason();
                $this->lastPrediction = $strategy->getLastPrediction();

                return $workers;
            }

            // Off hours: use cost-optimized
            $strategy = new \App\QueueAutoscale\Strategies\CostOptimizedStrategy;
            $workers = $strategy->calculateTargetWorkers($metrics, $config);
            $this->lastReason = 'Off hours (cost-optimized): '.$strategy->getLastReason();
            $this->lastPrediction = $strategy->getLastPrediction();

            return $workers;
        }

        public function getLastReason(): string
        {
            return $this->lastReason;
        }

        public function getLastPrediction(): ?float
        {
            return $this->lastPrediction;
        }
    },
];

// ============================================================================
// Example 8: Queue Isolation Pattern
// ============================================================================
// Characteristics: Separate queues by tenant, team, or service with isolation
return [
    'enabled' => true,

    'sla_defaults' => [
        'max_pickup_time_seconds' => 60,
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 90,
    ],

    'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy::class,

    // Prefix-based configuration
    'queue_overrides' => [
        // Team-based isolation
        'team-platform' => ['max_workers' => 15],
        'team-mobile' => ['max_workers' => 10],
        'team-data' => ['max_workers' => 20],

        // Service-based isolation
        'service-api' => ['max_pickup_time_seconds' => 10],
        'service-webhooks' => ['max_pickup_time_seconds' => 30],
        'service-reports' => ['max_pickup_time_seconds' => 300],
    ],
];
