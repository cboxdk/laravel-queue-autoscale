<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\HintForecastPolicy;

final readonly class BackgroundProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 300,
                'percentile' => 95,
                'window_seconds' => 900,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => HintForecastPolicy::class,
                'horizon_seconds' => 300,
                'history_seconds' => 900,
            ],
            'workers' => [
                'min' => 0,
                'max' => 5,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 10,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 3.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
