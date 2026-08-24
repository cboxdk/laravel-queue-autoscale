<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

use Illuminate\Support\Carbon;

/**
 * Damps scaling direction reversals on the cluster-wide target, mirroring the
 * guard the single-host paths have always had.
 *
 * The leader recomputes each workload's demand every evaluation interval, so an
 * oscillating signal would otherwise be executed as a real spawn on one cycle
 * and a kill on the next, cluster-wide. Only a genuine reversal is held; the
 * remembered direction goes stale once the window elapses, same-direction
 * changes are never damped, and a scale-up during an SLA breach always passes.
 *
 * Like the placement cache this is leader working memory, discarded on
 * leadership change: the cost of a failover is one undamped cycle.
 */
class ClusterCooldown
{
    /** @var array<string, Carbon> */
    private array $lastScaleTime = [];

    /** @var array<string, string> */
    private array $lastScaleDirection = [];

    /** @var array<string, int> */
    private array $lastPublishedTargets = [];

    public function apply(
        string $workloadKey,
        int $currentWorkers,
        int $targetWorkers,
        bool $isBreaching,
        int $cooldownSeconds,
    ): CooldownDecision {
        $currentDirection = $targetWorkers > $currentWorkers ? 'up' : ($targetWorkers < $currentWorkers ? 'down' : 'hold');
        $lastDirection = $this->lastScaleDirection[$workloadKey] ?? null;

        if ($lastDirection !== null && ! $this->inCooldown($workloadKey, $cooldownSeconds)) {
            unset($this->lastScaleDirection[$workloadKey]);
            $lastDirection = null;
        }

        if ($currentDirection !== 'hold' && $lastDirection !== null && $currentDirection !== $lastDirection) {
            $isBreachScaleUp = $currentDirection === 'up' && $isBreaching;

            if (! $isBreachScaleUp && $this->inCooldown($workloadKey, $cooldownSeconds)) {
                $held = max(0, $this->lastPublishedTargets[$workloadKey] ?? $currentWorkers);
                $this->lastPublishedTargets[$workloadKey] = $held;

                return new CooldownDecision($held, wasHeld: true);
            }

            if ($isBreachScaleUp) {
                $this->remember($workloadKey, $currentDirection, $targetWorkers);

                return new CooldownDecision($targetWorkers, breachOverride: true);
            }
        }

        $this->remember($workloadKey, $currentDirection, $targetWorkers);

        return new CooldownDecision($targetWorkers);
    }

    /**
     * Forget damping state for workloads that are no longer present.
     *
     * @param  array<string, mixed>  $currentWorkloads  keyed by workload key
     */
    public function pruneTo(array $currentWorkloads): void
    {
        $this->lastScaleTime = array_intersect_key($this->lastScaleTime, $currentWorkloads);
        $this->lastScaleDirection = array_intersect_key($this->lastScaleDirection, $currentWorkloads);
        $this->lastPublishedTargets = array_intersect_key($this->lastPublishedTargets, $currentWorkloads);
    }

    /**
     * Discard everything. Directions and targets remembered from a previous
     * leadership lease describe a cluster that no longer exists.
     */
    public function reset(): void
    {
        $this->lastScaleTime = [];
        $this->lastScaleDirection = [];
        $this->lastPublishedTargets = [];
    }

    private function remember(string $workloadKey, string $direction, int $targetWorkers): void
    {
        if ($direction !== 'hold') {
            $this->lastScaleTime[$workloadKey] = now();
            $this->lastScaleDirection[$workloadKey] = $direction;
        }

        $this->lastPublishedTargets[$workloadKey] = $targetWorkers;
    }

    private function inCooldown(string $workloadKey, int $cooldownSeconds): bool
    {
        if (! isset($this->lastScaleTime[$workloadKey])) {
            return false;
        }

        return $this->lastScaleTime[$workloadKey]->diffInSeconds(now()) < $cooldownSeconds;
    }
}
