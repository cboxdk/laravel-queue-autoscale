<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

final readonly class ForecastConfiguration
{
    public function __construct(
        public string $forecasterClass,
        public string $policyClass,
        public int $horizonSeconds,
        public int $historySeconds,
    ) {
        if (! class_exists($forecasterClass) || ! is_subclass_of($forecasterClass, ForecasterContract::class)) {
            throw new InvalidConfigurationException("forecast.forecaster must implement ForecasterContract: {$forecasterClass}");
        }

        if (! class_exists($policyClass) || ! is_subclass_of($policyClass, ForecastPolicyContract::class)) {
            throw new InvalidConfigurationException("forecast.policy must implement ForecastPolicyContract: {$policyClass}");
        }

        if ($horizonSeconds <= 0) {
            throw new InvalidConfigurationException("forecast.horizon_seconds must be > 0, got {$horizonSeconds}");
        }

        if ($historySeconds < $horizonSeconds) {
            throw new InvalidConfigurationException("forecast.history_seconds ({$historySeconds}) must be >= horizon_seconds ({$horizonSeconds})");
        }
    }
}
