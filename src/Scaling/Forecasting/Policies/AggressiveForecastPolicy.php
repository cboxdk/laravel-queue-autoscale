<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

final readonly class AggressiveForecastPolicy implements ForecastPolicyContract
{
    public function minRSquared(): float
    {
        return 0.4;
    }

    public function forecastWeight(): float
    {
        return 0.8;
    }
}
