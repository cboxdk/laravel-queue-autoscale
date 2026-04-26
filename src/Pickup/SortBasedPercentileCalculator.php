<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;

final class SortBasedPercentileCalculator implements PercentileCalculatorContract
{
    public function compute(array $values, int $percentile, int $minSamples = 20): ?float
    {
        $count = count($values);

        if ($count < $minSamples) {
            return null;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return $values[$index];
    }
}
