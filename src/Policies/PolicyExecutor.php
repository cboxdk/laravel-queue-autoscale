<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Log;

readonly class PolicyExecutor
{
    /** @var array<int, ScalingPolicy> */
    private array $policies;

    public function __construct()
    {
        $this->policies = $this->loadPolicies();
    }

    /**
     * Execute all policies before scaling
     *
     * Policies can modify the scaling decision by returning a new ScalingDecision.
     * Each policy receives the potentially modified decision from previous policies.
     *
     * @return ScalingDecision The final decision after all policies have been applied
     */
    public function beforeScaling(ScalingDecision $decision): ScalingDecision
    {
        $currentDecision = $decision;

        foreach ($this->policies as $policy) {
            try {
                $modifiedDecision = $policy->beforeScaling($currentDecision);

                // If policy returns a modified decision, use it for subsequent policies
                if ($modifiedDecision !== null) {
                    $currentDecision = $modifiedDecision;
                }
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Policy beforeScaling failed',
                    [
                        'policy' => get_class($policy),
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        return $currentDecision;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        foreach ($this->policies as $policy) {
            try {
                $policy->afterScaling($decision);
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Policy afterScaling failed',
                    [
                        'policy' => get_class($policy),
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /** @return array<int, ScalingPolicy> */
    private function loadPolicies(): array
    {
        $policies = [];

        foreach (AutoscaleConfiguration::policyClasses() as $class) {
            $policy = app($class);

            if (! $policy instanceof ScalingPolicy) {
                Log::channel(AutoscaleConfiguration::logChannel())->warning(
                    'Configured scaling policy does not implement ScalingPolicy and was skipped',
                    ['policy' => $class]
                );

                continue;
            }

            $policies[] = $policy;
        }

        return $policies;
    }
}
