<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface ForecastPolicyContract
{
    /** Minimum R² for a forecast to be trusted. Returns > 1.0 to effectively disable. */
    public function minRSquared(): float;

    /** Blending weight for forecast in [0.0, 1.0]. */
    public function forecastWeight(): float;
}
