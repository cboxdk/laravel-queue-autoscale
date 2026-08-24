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

    /**
     * The target each workload was last ALLOWED, which is not always the one
     * published: a hold publishes min(remembered, running) so it can never
     * answer a scale-down with a scale-up, while the memory keeps the
     * remembered figure so one transient dip cannot ratchet the hold down.
     *
     * @var array<string, int>
     */
    private array $lastAllowedTargets = [];

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
                // Hold, but never above what is already running. The remembered
                // value is the last target PUBLISHED, which the fleet may never
                // have reached — a host ceiling, max_total_workers, or a failed
                // spawn all leave current below it. Republishing it unclamped
                // would answer a scale-down request with a scale-up, which is
                // the opposite of damping. Single-host mode has no equivalent
                // hazard because it holds by declining to act; the cluster path
                // publishes a number that hosts actively converge toward.
                $remembered = max(0, $this->lastAllowedTargets[$workloadKey] ?? $currentWorkers);

                // The memory keeps the remembered target; only what is
                // PUBLISHED is clamped. Writing the clamped value back would
                // ratchet: one transient dip in reported workers — a crash, a
                // host leaving, a heartbeat lagging behind a spawn — would
                // lower the hold for the rest of the window and never recover.
                $this->lastAllowedTargets[$workloadKey] = $remembered;

                return new CooldownDecision(min($remembered, $currentWorkers), wasHeld: true);
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
        $this->lastAllowedTargets = array_intersect_key($this->lastAllowedTargets, $currentWorkloads);
    }

    /**
     * Discard everything. Directions and targets remembered from a previous
     * leadership lease describe a cluster that no longer exists.
     */
    public function reset(): void
    {
        $this->lastScaleTime = [];
        $this->lastScaleDirection = [];
        $this->lastAllowedTargets = [];
    }

    private function remember(string $workloadKey, string $direction, int $targetWorkers): void
    {
        if ($direction !== 'hold') {
            $this->lastScaleTime[$workloadKey] = now();
            $this->lastScaleDirection[$workloadKey] = $direction;
        }

        $this->lastAllowedTargets[$workloadKey] = $targetWorkers;
    }

    private function inCooldown(string $workloadKey, int $cooldownSeconds): bool
    {
        if (! isset($this->lastScaleTime[$workloadKey])) {
            return false;
        }

        return $this->lastScaleTime[$workloadKey]->diffInSeconds(now()) < $cooldownSeconds;
    }
}
