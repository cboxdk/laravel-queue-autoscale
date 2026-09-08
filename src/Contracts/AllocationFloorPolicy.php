<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

/**
 * Opt-in marker for scaling policies that can claim a worker floor in
 * fair-share allocation, not merely adjust a workload's demand.
 *
 * A {@see ScalingPolicy} (including a {@see ClusterScopedPolicy}) can rewrite a
 * workload's target, but that target becomes the workload's demand input to the
 * allocator. Under contention the allocator shares capacity in proportion to
 * demand and pays floors from exactly one source — the configured workers.min.
 * So a policy can ask for workers but cannot claim them: a workload with
 * workers.min 0 and small demand rounds to zero against a large competitor,
 * even while a policy is actively trying to hold it up.
 *
 * An AllocationFloorPolicy instead contributes a floor that the allocator pays
 * before proportional sharing, merged as max() with workers.min. Unlike a
 * static workers.min the claim can be conditional on application state — an
 * integration with an active sync run — and released the moment that clears.
 * The floor is still bounded by the workload's ceiling (its demand and
 * workers.max), so a floor the workload cannot use is never paid; the failure
 * fuse continues to release it by driving demand below the floor.
 *
 * Policies that do not implement this interface are never consulted for a
 * floor, so existing behavior is unchanged unless a policy explicitly opts in.
 *
 * This is a cluster-mode contract. The floor is consulted only on the leader,
 * while it builds the fair-share bounds — the one place proportional sharing
 * can starve a workload. With cluster.enabled false there is no allocator and
 * no contention, so allocationFloor() is never called; hold a single-host
 * workload up by raising the target from beforeScaling() instead, where
 * nothing competes it away.
 */
interface AllocationFloorPolicy extends ScalingPolicy
{
    /**
     * The worker floor this workload may claim in fair-share allocation, or
     * null to make no claim. Merged as max() with the configured workers.min.
     *
     * @param  string  $connection  The queue connection.
     * @param  string  $name  The queue name, or the group name when $isGroup is true.
     * @param  bool  $isGroup  Whether the workload is a queue group rather than a single queue.
     */
    public function allocationFloor(string $connection, string $name, bool $isGroup): ?int;
}
