<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

final readonly class LittlesLawCalculator
{
    /**
     * Little's Law: L = λW
     *
     * L = Queue length (backlog)
     * λ = Arrival rate (jobs/second)
     * W = Average time in system (processing time)
     *
     * Rearranged for workers: Workers = λ × W
     *
     * @param  float  $arrivalRate  Jobs per second
     * @param  float  $avgProcessingTime  Average seconds per job
     * @return float Required workers (fractional, caller should ceil())
     */
    public function calculate(float $arrivalRate, float $avgProcessingTime): float
    {
        if ($arrivalRate <= 0 || $avgProcessingTime <= 0) {
            return 0.0;
        }

        return $arrivalRate * $avgProcessingTime;
    }
}
