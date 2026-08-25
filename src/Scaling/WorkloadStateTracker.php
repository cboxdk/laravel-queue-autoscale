<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Illuminate\Support\Carbon;

/**
 * Per-workload memory for the single-host scaling paths: when each workload
 * last moved, which way, and whether it was breaching its SLA.
 *
 * The cluster leader has its own equivalent in Cluster\ClusterCooldown, damping
 * the fleet-wide target before it is distributed. This one damps each host's
 * own decisions, and the two are deliberately separate — a host must keep
 * absorbing reversals whether or not it currently holds the lease.
 *
 * Entries are dropped once a workload has not been SEEN for longer than the
 * retention window — seen meaning anything was recorded about it, not that it
 * was scaled.
 *
 * The distinction is the whole point. The sweep used to be driven by the last
 * scaling action, which bounds memory only on a host that actually scales. A
 * cluster leader records breach state for every workload it discovers and never
 * calls recordScale(), because the scaling happens on the followers — so on a
 * leader nothing was ever swept, and an application minting a queue name per
 * tenant grew one permanent entry per tenant in a process that runs for weeks.
 */
class WorkloadStateTracker
{
    /** @var array<string, Carbon> */
    private array $lastScaleTime = [];

    /** @var array<string, string> */
    private array $lastScaleDirection = [];

    /** @var array<string, bool> */
    private array $breachState = [];

    /**
     * When anything was last recorded about each workload.
     *
     * Kept separately from lastScaleTime because the two answer different
     * questions: one is "when did this last move", which the cooldown needs,
     * and this one is "is this workload still a thing", which is what bounds
     * the memory. A workload can be evaluated on every cycle for hours without
     * ever moving.
     *
     * @var array<string, Carbon>
     */
    private array $lastSeen = [];

    public function inCooldown(string $key, int $cooldownSeconds): bool
    {
        if (! isset($this->lastScaleTime[$key])) {
            return false;
        }

        return $this->lastScaleTime[$key]->diffInSeconds(now()) < $cooldownSeconds;
    }

    public function cooldownRemaining(string $key, int $cooldownSeconds): int
    {
        if (! isset($this->lastScaleTime[$key])) {
            return 0;
        }

        $elapsed = $this->lastScaleTime[$key]->diffInSeconds(now());

        return (int) max(0, $cooldownSeconds - $elapsed);
    }

    public function lastDirection(string $key): ?string
    {
        return $this->lastScaleDirection[$key] ?? null;
    }

    /**
     * Drop a remembered direction that has outlived its cooldown window, so a
     * hold-then-reverse is not blocked by a move from minutes ago.
     */
    public function forgetDirection(string $key): void
    {
        unset($this->lastScaleDirection[$key]);
    }

    /**
     * Whether a move must be damped as a direction reversal.
     *
     * Damping is ONE-SIDED: a scale-up is never held. Holding one instead lets
     * the backlog grow until it breaches, and the breach then releases a target
     * the delay itself inflated — the guard becomes the source of the
     * oscillation it exists to absorb.
     *
     * A scale-down is held only while the window opened by a scale-up is still
     * running. Consecutive withdrawals are never delayed, and a quiet stretch
     * longer than the window releases the next one.
     *
     * Stated as `=== 'up'` rather than "not a scale-down" so it matches
     * Cluster\ClusterCooldown exactly. That class cannot store a 'hold' at all
     * — its remember() drops them — while recordScale() here will store
     * whatever it is given, so the looser form would have made the two
     * implementations of one rule disagree on an input only this one can see.
     * ClusterCooldown carries the measurements behind both halves; the
     * predicate lives here so the two single-host call sites cannot drift
     * apart from each other.
     *
     * Clears a direction that has outlived its window as a side effect, so a
     * quiet workload is not blocked by a move from minutes ago.
     *
     * @param  string  $direction  'up', 'down' or 'hold'
     */
    public function holdsReversal(string $key, string $direction, int $cooldownSeconds): bool
    {
        if ($this->lastDirection($key) !== null && ! $this->inCooldown($key, $cooldownSeconds)) {
            $this->forgetDirection($key);
        }

        $lastDirection = $this->lastDirection($key);

        return $direction === 'down' && $lastDirection === 'up';
    }

    public function recordScale(string $key, string $direction): void
    {
        $this->lastScaleTime[$key] = now();
        $this->lastScaleDirection[$key] = $direction;
        $this->lastSeen[$key] = now();
    }

    public function wasBreaching(string $key): bool
    {
        return $this->breachState[$key] ?? false;
    }

    public function setBreaching(string $key, bool $isBreaching): void
    {
        $this->breachState[$key] = $isBreaching;
        $this->lastSeen[$key] = now();
    }

    /**
     * Forget every workload nothing has been recorded about since the cutoff.
     *
     * Driven by last-seen rather than last-scaled so it bounds a leader too,
     * and so a workload that is still being evaluated keeps the memory that
     * describes it. Sweeping a live workload would reset the breach edge that
     * decides whether SlaBreached has already been reported, which is how a
     * queue breaching quietly for an hour would announce itself twice.
     */
    public function forgetQuietSince(Carbon $cutoff): void
    {
        foreach ($this->lastSeen as $key => $at) {
            if ($at->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            unset(
                $this->lastSeen[$key],
                $this->lastScaleTime[$key],
                $this->lastScaleDirection[$key],
                $this->breachState[$key],
            );
        }
    }
}
