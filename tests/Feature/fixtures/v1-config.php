<?php

return [
    'enabled' => true,
    'manager_id' => 'test-host',
    'sla_defaults' => [
        'max_pickup_time_seconds' => 60,
        'min_workers' => 5,
        'max_workers' => 30,
        'scale_cooldown_seconds' => 90,
    ],
    'queues' => [
        'emails' => [
            'max_pickup_time_seconds' => 45,
            'min_workers' => 2,
            'max_workers' => 15,
        ],
        'notifications' => [
            'connection' => 'redis-high',
            'min_workers' => 3,
        ],
    ],
    'scaling' => [
        'fallback_job_time_seconds' => 2.0,
        'min_arrival_rate_confidence' => 0.5,
        'trend_window_seconds' => 300,
        'forecast_horizon_seconds' => 60,
        'breach_threshold' => 0.5,
        'trend_policy' => 'moderate',
    ],
    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
    ],
    'workers' => [
        'timeout_seconds' => 3600,
        'tries' => 3,
    ],
    'manager' => [
        'evaluation_interval_seconds' => 5,
    ],
    'strategy' => 'Cbox\\LaravelQueueAutoscale\\Scaling\\Strategies\\PredictiveStrategy',
    'policies' => [],
];
