<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;

final class SortBasedPercentileCalculator implements PercentileCalculatorContract
{
    private const MIN_SAMPLES = 20;

    public function compute(array $values, int $percentile): ?float
    {
        $count = count($values);

        if ($count < self::MIN_SAMPLES) {
            return null;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return $values[$index];
    }
}
