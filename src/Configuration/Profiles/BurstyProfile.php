<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\AggressiveForecastPolicy;

readonly class BurstyProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 60,
                'percentile' => 90,
                'window_seconds' => 600,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => AggressiveForecastPolicy::class,
                'horizon_seconds' => 120,
                'history_seconds' => 600,
            ],
            'workers' => [
                'min' => 0,
                'max' => 100,
                'tries' => 3,
                'max_time_seconds' => 3600,
                'timeout_seconds' => 300,
                'sleep_seconds' => 3,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
            'fuse' => [
                'enabled' => true,
                'failure_threshold_percent' => 50.0,
                'min_samples' => 20,
                'window_seconds' => 120,
                'cooldown_seconds' => 60,
            ],
        ];
    }
}
