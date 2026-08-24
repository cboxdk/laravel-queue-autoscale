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
 * Entries are dropped once a workload has been quiet for longer than the
 * retention window, which bounds memory on an installation that discovers
 * queues per tenant.
 */
class WorkloadStateTracker
{
    /** @var array<string, Carbon> */
    private array $lastScaleTime = [];

    /** @var array<string, string> */
    private array $lastScaleDirection = [];

    /** @var array<string, bool> */
    private array $breachState = [];

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

    public function recordScale(string $key, string $direction): void
    {
        $this->lastScaleTime[$key] = now();
        $this->lastScaleDirection[$key] = $direction;
    }

    public function wasBreaching(string $key): bool
    {
        return $this->breachState[$key] ?? false;
    }

    public function setBreaching(string $key, bool $isBreaching): void
    {
        $this->breachState[$key] = $isBreaching;
    }

    /**
     * Forget every workload whose last scaling action predates the cutoff.
     */
    public function forgetQuietSince(Carbon $cutoff): void
    {
        foreach ($this->lastScaleTime as $key => $at) {
            if ($at->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            unset(
                $this->lastScaleTime[$key],
                $this->lastScaleDirection[$key],
                $this->breachState[$key],
            );
        }
    }
}
