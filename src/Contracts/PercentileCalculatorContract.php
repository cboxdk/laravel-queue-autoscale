<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface PercentileCalculatorContract
{
    /**
     * Compute the given percentile (0-100) over the values.
     *
     * @param  list<float>  $values
     * @return float|null Null if insufficient samples.
     */
    public function compute(array $values, int $percentile): ?float;
}
