<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterScopedPolicy;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Log;

readonly class PolicyExecutor
{
    /** @var array<int, ScalingPolicy> */
    private array $policies;

    /** @var array<int, ClusterScopedPolicy> */
    private array $clusterScopedPolicies;

    public function __construct()
    {
        $this->policies = $this->loadPolicies();
        $this->clusterScopedPolicies = array_values(array_filter(
            $this->policies,
            static fn (ScalingPolicy $policy): bool => $policy instanceof ClusterScopedPolicy,
        ));
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
        return $this->runBeforeChain($this->policies, $decision);
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        $this->runAfterChain($this->policies, $decision);
    }

    /**
     * Consult only the policies that opted into cluster scope.
     *
     * The leader runs this against a Cluster-scoped decision carrying a
     * workload's cluster-wide counts. Policies without the marker interface
     * are deliberately absent: running every policy here would silently make
     * an existing per-host cap also clamp the cluster total.
     */
    public function beforeScalingClusterScoped(ScalingDecision $decision): ScalingDecision
    {
        return $this->runBeforeChain($this->clusterScopedPolicies, $decision);
    }

    public function afterScalingClusterScoped(ScalingDecision $decision): void
    {
        $this->runAfterChain($this->clusterScopedPolicies, $decision);
    }

    public function hasClusterScopedPolicies(): bool
    {
        return $this->clusterScopedPolicies !== [];
    }

    /**
     * @param  array<int, ScalingPolicy>  $policies
     */
    private function runBeforeChain(array $policies, ScalingDecision $decision): ScalingDecision
    {
        $currentDecision = $decision;

        foreach ($policies as $policy) {
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

    /**
     * @param  array<int, ScalingPolicy>  $policies
     */
    private function runAfterChain(array $policies, ScalingDecision $decision): void
    {
        foreach ($policies as $policy) {
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
