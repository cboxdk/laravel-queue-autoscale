<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

final readonly class HighVolumeProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 20,
                'percentile' => 95,
                'window_seconds' => 300,
                'min_samples' => 50,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => ModerateForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 3,
                'max' => 40,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 2,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
