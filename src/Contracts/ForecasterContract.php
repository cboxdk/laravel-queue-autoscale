<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;

interface ForecasterContract
{
    /**
     * @param  list<array{timestamp: float, rate: float}>  $history
     */
    public function forecast(array $history, int $horizonSeconds): ForecastResult;
}
