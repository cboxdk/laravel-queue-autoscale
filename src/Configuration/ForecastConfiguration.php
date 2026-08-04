<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

readonly class ForecastConfiguration
{
    /**
     * @param  class-string<ForecasterContract>  $forecasterClass
     * @param  class-string<ForecastPolicyContract>  $policyClass
     */
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

    /**
     * Resolve the configured forecaster from the container.
     *
     * Construction lives here because this is where the class-strings are
     * validated. The instance check is not redundant with the constructor's:
     * a container binding can return something other than the requested class.
     */
    public function makeForecaster(): ForecasterContract
    {
        $forecaster = app($this->forecasterClass);

        if (! $forecaster instanceof ForecasterContract) {
            throw new InvalidConfigurationException(
                "The container resolved {$this->forecasterClass} to something that is not a ForecasterContract."
            );
        }

        return $forecaster;
    }

    public function makePolicy(): ForecastPolicyContract
    {
        $policy = app($this->policyClass);

        if (! $policy instanceof ForecastPolicyContract) {
            throw new InvalidConfigurationException(
                "The container resolved {$this->policyClass} to something that is not a ForecastPolicyContract."
            );
        }

        return $policy;
    }
}
