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
 * remembered direction goes stale once the window elapses, and same-direction
 * changes are never damped.
 *
 * The damping is ONE-SIDED: a scale-up is never held. The two costs are not
 * symmetric. A held scale-down wastes money for the rest of the window and is
 * fully recoverable; a held scale-up accumulates backlog that still has to be
 * worked off, so the SLA is already broken by the time anything releases it.
 *
 * Precisely: a scale-down is held only while the window opened by a scale-up is
 * still running. Consecutive withdrawals are never delayed, and remember()
 * drops a 'hold' — a quiet cycle neither opens the window nor refreshes it — so
 * a workload that has been steady for longer than the window withdraws at once.
 *
 * Damping both directions made the manager the source of the oscillation it
 * exists to absorb. On demand with a period near the cooldown window every
 * change is a reversal, so every scale-up was held until the backlog breached;
 * the breach then released a target the delay had itself inflated, and the
 * scale-down back off that spike was held in turn. Worse, a hold republishes
 * the last allowed target clamped to what is running, so a rise arriving
 * mid-drain was answered by CUTTING the fleet.
 *
 * Reproducible from CooldownResonanceSimulationTest, a 120s sine against the
 * real engine at workers.max = 20: symmetric damping pinned the fleet to the
 * 20-worker ceiling, averaged 9.2 workers and spent 109 of 3600 ticks
 * breaching; one-sided peaks at 8, averages 6.5 and never breaches. At a 90s
 * period symmetric holds the SLA but still pins to the ceiling at a mean of
 * 9.8, against 6.4 and a peak of 8. The scenarios the guard was written for —
 * noise around a constant mean, a sustained step, a periodic burst — came out
 * the same or better on every measure.
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

        // A stale direction is cleared above, so a non-null $lastDirection
        // already implies the window is still open.
        $reversesDirection = $lastDirection !== null && $currentDirection !== $lastDirection;

        if ($currentDirection === 'down' && $reversesDirection) {
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

        $this->remember($workloadKey, $currentDirection, $targetWorkers);

        // Reported unchanged: a reversing scale-up under an active breach is
        // still the case operators are told about, it simply no longer needs
        // an exception to get through.
        return new CooldownDecision(
            $targetWorkers,
            breachOverride: $currentDirection === 'up' && $isBreaching && $reversesDirection,
        );
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

        // Absolute: diffInSeconds is signed, so a clock that steps BACKWARDS —
        // an NTP correction, a VM resuming from a snapshot — makes the elapsed
        // time negative and the window appear to have barely started. Measured
        // with a 30-minute backward step: a scale-down stayed held for 31
        // minutes instead of 60 seconds.
        return abs($this->lastScaleTime[$workloadKey]->diffInSeconds(now())) < $cooldownSeconds;
    }
}
