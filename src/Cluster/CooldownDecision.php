<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

/**
 * What the anti-flapping cooldown decided for one workload this cycle.
 *
 * The cooldown itself does no logging — it reports what it did and the caller
 * decides how to announce it, which keeps the damping logic a pure function of
 * its inputs and its remembered state.
 */
readonly class CooldownDecision
{
    public function __construct(
        /** The target to publish: either the requested one, or the held one. */
        public int $targetWorkers,
        /** True when a direction reversal was refused and the previous target held. */
        public bool $wasHeld = false,
        /** True when an SLA breach let a scale-up through a cooldown that would otherwise have held it. */
        public bool $breachOverride = false,
    ) {}
}
