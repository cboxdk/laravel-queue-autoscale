<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

interface ScalingPolicy
{
    /**
     * Execute before scaling action
     *
     * Policies can optionally return a modified ScalingDecision to alter the scaling behavior.
     * If null is returned, the original decision is used.
     *
     * @return ScalingDecision|null Modified decision or null to use original
     */
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision;

    /**
     * Execute after scaling action
     *
     * Called after scaling has been performed. Cannot modify the decision at this point.
     */
    public function afterScaling(ScalingDecision $decision): void;
}
