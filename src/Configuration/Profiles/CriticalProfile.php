<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\AggressiveForecastPolicy;

final readonly class CriticalProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 10,
                'percentile' => 99,
                'window_seconds' => 120,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => AggressiveForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 5,
                'max' => 50,
                'tries' => 5,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 1,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 3,
                'ema_alpha' => 0.3,
            ],
        ];
    }
}
