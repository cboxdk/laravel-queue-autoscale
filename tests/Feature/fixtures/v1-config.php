<?php

return [
    'enabled' => true,
    'manager_id' => 'test-host',
    'sla_defaults' => [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 60,
    ],
    'queues' => [],
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
