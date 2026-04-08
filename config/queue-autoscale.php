<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ProfilePresets;
use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
    'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID', gethostname()),

    /*
    |--------------------------------------------------------------------------
    | Default SLA Configuration (Balanced Profile)
    |--------------------------------------------------------------------------
    |
    | These defaults use the "Balanced" profile - a general-purpose configuration
    | suitable for typical web applications with mixed workloads.
    |
    | Available Profiles:
    | - ProfilePresets::critical()    - Mission-critical (10s SLA, 5-50 workers)
    | - ProfilePresets::highVolume()  - High-throughput (20s SLA, 3-40 workers)
    | - ProfilePresets::balanced()    - General-purpose (30s SLA, 1-10 workers) [DEFAULT]
    | - ProfilePresets::bursty()      - Sporadic spikes (60s SLA, 0-100 workers)
    | - ProfilePresets::background()  - Low-priority (300s SLA, 0-5 workers)
    |
    */
    'sla_defaults' => ProfilePresets::balanced(),

    /*
    |--------------------------------------------------------------------------
    | Queue-Specific Overrides
    |--------------------------------------------------------------------------
    |
    | Override settings for specific queues. You can use ProfilePresets or
    | customize individual settings.
    |
    | Examples:
    |
    | 'queues' => [
    |     // Use a preset profile
    |     'payments' => ProfilePresets::critical(),
    |     'emails' => ProfilePresets::highVolume(),
    |     'cleanup' => ProfilePresets::background(),
    |
    |     // Customize a profile
    |     'custom' => array_merge(ProfilePresets::balanced(), [
    |         'max_workers' => 20,
    |     ]),
    |
    |     // Full manual configuration
    |     'manual' => [
    |         'max_pickup_time_seconds' => 45,
    |         'min_workers' => 2,
    |         'max_workers' => 15,
    |         'scale_cooldown_seconds' => 90,
    |     ],
    | ],
    |
    */
    'queues' => [],

    /*
    |--------------------------------------------------------------------------
    | Scaling Algorithm Configuration
    |--------------------------------------------------------------------------
    |
    | Controls how the scaling algorithms calculate worker requirements.
    |
    | Job Time Estimation:
    | When actual job duration metrics are unavailable, the algorithm needs
    | a fallback estimate. Set this based on your typical job characteristics:
    | - Fast jobs (notifications, cache updates): 0.1 - 0.5 seconds
    | - Medium jobs (emails, API calls): 1 - 5 seconds
    | - Slow jobs (reports, file processing): 10 - 60 seconds
    | - Long jobs (video encoding, ML): 60+ seconds
    |
    | Arrival Rate Estimation:
    | The algorithm estimates job arrival rate from backlog changes over time.
    | This is more accurate than using processing rate during traffic spikes.
    | - min_confidence: Minimum confidence to use estimated arrival rate (0.0-1.0)
    |
    | Trend Policy Options:
    | - 'disabled'   - Ignore trend predictions, reactive scaling only
    | - 'hint'       - Conservative (30% trend weight, confidence >= 0.8)
    | - 'moderate'   - Balanced (50% trend weight, confidence >= 0.7) [DEFAULT]
    | - 'aggressive' - Proactive (80% trend weight, confidence >= 0.5)
    |
    */
    'scaling' => [
        'fallback_job_time_seconds' => env('QUEUE_AUTOSCALE_FALLBACK_JOB_TIME', 2.0),
        'min_arrival_rate_confidence' => 0.5,   // Use estimated rate when confidence >= 50%
        'trend_window_seconds' => 300,          // 5 minutes historical window
        'forecast_horizon_seconds' => 60,       // 1 minute ahead prediction
        'breach_threshold' => 0.5,              // Act at 50% of SLA for proactive scaling
        'trend_policy' => env('QUEUE_AUTOSCALE_TREND_POLICY', 'moderate'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prediction Configuration (DEPRECATED - use 'scaling' instead)
    |--------------------------------------------------------------------------
    |
    | Kept for backwards compatibility. Values here are merged with 'scaling'.
    |
    */
    'prediction' => [
        // These are read from 'scaling' now, but kept for backwards compatibility
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Constraints
    |--------------------------------------------------------------------------
    |
    | System resource limits to prevent over-provisioning workers.
    |
    */
    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
        'worker_memory_mb_estimate' => 128,
        'reserve_cpu_cores' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for spawned queue workers.
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
    | Manager Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the autoscale manager process.
    |
    */
    'manager' => [
        'evaluation_interval_seconds' => 5,
        'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scaling Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy class responsible for calculating target worker counts based
    | on queue metrics and SLA configuration.
    |
    | Available Strategies:
    | - PredictiveStrategy    - Multi-algorithm (rate + trend + backlog) [DEFAULT]
    | - SimpleRateStrategy    - Little's Law only, for stable workloads
    | - BacklogOnlyStrategy   - Backlog drain focus, for batch processing
    | - ConservativeStrategy  - PredictiveStrategy + 25% safety buffer
    |
    | PredictiveStrategy Algorithm (DEFAULT):
    | 1. Rate-Based: Little's Law (L = λW) for steady-state worker count
    | 2. Trend-Based: Predict future arrival rate using configured trend policy
    | 3. Backlog-Based: Calculate workers needed to prevent SLA breach
    | 4. Combine: Take maximum (most conservative) of all three calculations
    |
    | The strategy takes the maximum to ensure:
    | - Current workload is handled (rate-based)
    | - Future spikes are anticipated (trend-based)
    | - SLA breaches are prevented (backlog-based)
    |
    | Custom Strategies:
    | Create your own by implementing ScalingStrategyContract with methods:
    | - calculateTargetWorkers(QueueMetricsData, QueueConfiguration): int
    | - getLastReason(): string
    | - getLastPrediction(): ?float
    |
    | Example custom strategy:
    | class SimpleRateStrategy implements ScalingStrategyContract {
    |     public function calculateTargetWorkers($metrics, $config): int {
    |         return (int) ceil($metrics->pending / 100); // 1 worker per 100 jobs
    |     }
    |     // ... implement other required methods
    | }
    |
    | Strategy Selection Guide:
    |
    | PredictiveStrategy (DEFAULT):
    | ✓ General-purpose workloads
    | ✓ Bursty traffic patterns
    | ✓ Need proactive scaling
    | ✗ Minimal resource usage (use SimpleRateStrategy)
    |
    | SimpleRateStrategy:
    | ✓ Stable, predictable workloads
    | ✓ Minimal overhead desired
    | ✓ Simple scaling logic
    | ✗ Bursty traffic (use PredictiveStrategy)
    | ✗ Strict SLA requirements (use ConservativeStrategy)
    |
    | BacklogOnlyStrategy:
    | ✓ Batch processing
    | ✓ Irregular arrival patterns
    | ✓ Processing accumulated work
    | ✗ Real-time requirements (use PredictiveStrategy)
    |
    | ConservativeStrategy:
    | ✓ Mission-critical queues
    | ✓ Strict SLA requirements
    | ✓ Over-provisioning acceptable
    | ✗ Cost-sensitive environments (use PredictiveStrategy)
    |
    | When to Create Custom Strategies:
    | - Domain-specific scaling logic (e.g., time-of-day patterns)
    | - Special constraints (hardware limits, cost optimization)
    | - Integration with external autoscalers (Kubernetes HPA, AWS ASG)
    | - Complex business rules for scaling decisions
    |
    */
    'strategy' => PredictiveStrategy::class,

    /*
    |--------------------------------------------------------------------------
    | Scaling Policies
    |--------------------------------------------------------------------------
    |
    | Policies modify scaling behavior. Policies are executed in order and can
    | modify scaling decisions before they are applied.
    |
    | Available Policies:
    | - ConservativeScaleDownPolicy - Limit scale-down to 1 worker per cycle
    | - AggressiveScaleDownPolicy   - Allow rapid scale-down when idle
    | - NoScaleDownPolicy           - Prevent all scale-down (critical workloads)
    | - BreachNotificationPolicy    - Log/alert on SLA breaches
    |
    | Recommended Combinations by Profile:
    | - Critical:    [NoScaleDownPolicy::class, BreachNotificationPolicy::class]
    | - High-Volume: [ConservativeScaleDownPolicy::class, BreachNotificationPolicy::class]
    | - Balanced:    [ConservativeScaleDownPolicy::class]
    | - Bursty:      [AggressiveScaleDownPolicy::class, BreachNotificationPolicy::class]
    | - Background:  [AggressiveScaleDownPolicy::class]
    |
    */
    'policies' => [
        ConservativeScaleDownPolicy::class,  // Default: Conservative scale-down
        BreachNotificationPolicy::class,     // Default: Monitor SLA compliance
    ],
];
