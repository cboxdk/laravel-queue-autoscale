<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\DisabledForecastPolicy;

/**
 * Profile for queues that must run sequentially (exactly one worker, no parallelism).
 *
 * Typical use cases:
 * - Customer integrations that require strict job ordering
 * - Legacy APIs with single-connection rate limits
 * - Any job stream where two workers racing would corrupt state
 *
 * Behaviourally this makes the package a process supervisor for that queue:
 * the autoscaler never evaluates scaling, but it does respawn the worker if
 * it dies. The SLA/forecast fields are kept so observability still works —
 * they just never drive a scaling decision.
 */
final readonly class ExclusiveProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 60,
                'percentile' => 95,
                'window_seconds' => 300,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => DisabledForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 1,
                'max' => 1,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 3,
                'shutdown_timeout_seconds' => 30,
                'scalable' => false,
            ],
            'spawn_compensation' => [
                'enabled' => false,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
