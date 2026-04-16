<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Forecasting;

final readonly class ForecastResult
{
    public function __construct(
        public float $projectedRate,
        public float $rSquared,
        public float $slope,
        public int $sampleCount,
        public bool $hasSufficientData,
    ) {}

    public static function insufficientData(): self
    {
        return new self(0.0, 0.0, 0.0, 0, false);
    }
}
