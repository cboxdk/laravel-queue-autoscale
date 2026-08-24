<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

/**
 * Places a workload's cluster-wide worker target across the active managers.
 *
 * This is leader working memory: the cached placement exists only to keep a
 * stable split stable, and it is discarded on leadership change because
 * another leader has been publishing in between.
 */
class WorkerDistributor
{
    /**
     * The placement each workload received last cycle, so an unchanged target
     * is not re-derived into a different split.
     *
     * @var array<string, array<string, int>>
     */
    private array $previousDistributions = [];

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     * @param  array<string, int>  $assignedTotals
     * @return array<string, int>
     */
    public function distribute(
        array $activeManagers,
        string $workloadKey,
        int $targetWorkers,
        array &$assignedTotals,
    ): array {
        $targets = [];

        foreach ($activeManagers as $state) {
            $targets[$state->managerId] = 0;
        }

        if ($targetWorkers <= 0 || $activeManagers === []) {
            $this->previousDistributions[$workloadKey] = $targets;

            return $targets;
        }

        // Reuse previous distribution when target is unchanged, all cached
        // assignments still fit within each host's remaining capacity, and the
        // cached split is still balanced by host capacity.
        // This prevents worker pool thrashing caused by sort-order instability
        // when reported current counts fluctuate between heartbeats.
        $cached = $this->previousDistributions[$workloadKey] ?? null;

        if ($cached !== null && array_sum($cached) === $targetWorkers) {
            $activeManagerIds = array_map(
                static fn (ClusterManagerState $state): string => $state->managerId,
                $activeManagers,
            );
            sort($activeManagerIds);

            $cachedManagerIds = array_keys($cached);
            sort($cachedManagerIds);

            if ($activeManagerIds === $cachedManagerIds) {
                $maxWorkersMap = [];
                foreach ($activeManagers as $state) {
                    $maxWorkersMap[$state->managerId] = $state->maxWorkers;
                }

                $feasible = true;
                foreach ($cached as $managerId => $cachedCount) {
                    $available = $maxWorkersMap[$managerId] - ($assignedTotals[$managerId] ?? 0);
                    if ($cachedCount > $available) {
                        $feasible = false;
                        break;
                    }
                }

                if ($feasible && $this->isCapacityBalanced($activeManagers, $cached, $assignedTotals)) {
                    foreach ($cached as $managerId => $cachedCount) {
                        $targets[$managerId] = $cachedCount;
                        $assignedTotals[$managerId] += $cachedCount;
                    }

                    return $targets;
                }
            }
        }

        [$type, $connection, $name] = explode(':', $workloadKey, 3);
        $currentCounts = [];

        foreach ($activeManagers as $state) {
            $counts = $type === 'group' ? $state->groupWorkers : $state->queueWorkers;
            $currentCounts[$state->managerId] = (int) ($counts["{$connection}:{$name}"] ?? 0);
        }

        $remaining = $targetWorkers;

        while ($remaining > 0) {
            $candidates = array_values(array_filter(
                $activeManagers,
                fn (ClusterManagerState $state): bool => ($assignedTotals[$state->managerId] + $targets[$state->managerId]) < $state->maxWorkers,
            ));

            if ($candidates === []) {
                break;
            }

            usort(
                $candidates,
                fn (ClusterManagerState $a, ClusterManagerState $b): int => $this->projectedUtilizationAfterAssignment($a, $targets, $assignedTotals) <=> $this->projectedUtilizationAfterAssignment($b, $targets, $assignedTotals)
                    ?: (($currentCounts[$b->managerId] - $targets[$b->managerId]) <=> ($currentCounts[$a->managerId] - $targets[$a->managerId]))
                    ?: strcmp($a->managerId, $b->managerId),
            );

            $chosen = $candidates[0];

            $targets[$chosen->managerId]++;
            $remaining--;
        }

        foreach ($targets as $managerId => $target) {
            $assignedTotals[$managerId] += $target;
        }

        $this->previousDistributions[$workloadKey] = $targets;

        return $targets;
    }

    /**
     * Forget cached placements for workloads that are no longer present.
     *
     * @param  array<string, mixed>  $currentWorkloads  keyed by workload key
     */
    public function pruneTo(array $currentWorkloads): void
    {
        $this->previousDistributions = array_intersect_key($this->previousDistributions, $currentWorkloads);
    }

    /**
     * Discard everything. Called when this manager gains leadership, because a
     * placement remembered from a previous lease describes a cluster that no
     * longer exists.
     */
    public function reset(): void
    {
        $this->previousDistributions = [];
    }

    /**
     * The placements currently cached, for diagnostics and for tests that need
     * to observe what the cache retained across a leadership change.
     *
     * @return array<string, array<string, int>>
     */
    public function cachedPlacements(): array
    {
        return $this->previousDistributions;
    }

    /**
     * Seed the cache directly. Intended for tests that need to pin a specific
     * starting placement; production code reaches this only through
     * distribute().
     *
     * @param  array<string, array<string, int>>  $distributions
     */
    public function seed(array $distributions): void
    {
        $this->previousDistributions = $distributions;
    }

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     * @param  array<string, int>  $distribution
     * @param  array<string, int>  $assignedTotals
     */
    private function isCapacityBalanced(array $activeManagers, array $distribution, array $assignedTotals): bool
    {
        $currentSpread = $this->utilizationSpread($activeManagers, $distribution, $assignedTotals);
        $threshold = $this->rebalanceSpreadThreshold($activeManagers);

        foreach ($activeManagers as $donor) {
            $donorId = $donor->managerId;

            if (($distribution[$donorId] ?? 0) <= 0) {
                continue;
            }

            foreach ($activeManagers as $recipient) {
                $recipientId = $recipient->managerId;

                if ($recipientId === $donorId) {
                    continue;
                }

                if ((($assignedTotals[$recipientId] ?? 0) + ($distribution[$recipientId] ?? 0)) >= $recipient->maxWorkers) {
                    continue;
                }

                $candidate = $distribution;
                $candidate[$donorId]--;
                $candidate[$recipientId]++;

                if ($this->utilizationSpread($activeManagers, $candidate, $assignedTotals) + $threshold < $currentSpread) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The spread improvement a single-worker move must achieve before the
     * cached distribution is abandoned.
     *
     * The spread's inputs jitter by construction: each host's maxWorkers is
     * recomputed from live CPU and memory readings every heartbeat, and
     * worker churn itself moves both. Against denominators like that, the
     * previous 0.000001 epsilon let nearly every cycle find a hair-thin
     * "improvement", so with two or more managers the cache was discarded
     * and workers reshuffled between hosts on almost every evaluation, each
     * move a graceful SIGTERM plus a full framework boot elsewhere.
     * Requiring at least one worker's worth of utilization keeps the cache
     * until the skew is worth a real worker.
     *
     * Measured on the LARGEST host, not the smallest. The gate weighs a
     * single-worker move, so the threshold has to live on a single worker's
     * scale — and one worker on the biggest host is the finest step any move
     * can produce. Deriving it from the smallest host breaks on a
     * heterogeneous fleet: a host reporting maxWorkers of 0 or 1, which the
     * capacity calculator produces under memory pressure since it floors at
     * zero, yields a threshold of 1.0. Utilization is workers/maxWorkers and
     * the distributor never assigns above maxWorkers, so the spread is itself
     * bounded by 1.0 — the gate becomes unsatisfiable for any skew whatsoever,
     * and the hysteresis silently turns into "never rebalance".
     *
     * @param  array<int, ClusterManagerState>  $activeManagers
     */
    private function rebalanceSpreadThreshold(array $activeManagers): float
    {
        $largestMaxWorkers = null;

        foreach ($activeManagers as $state) {
            $largestMaxWorkers = $largestMaxWorkers === null
                ? $state->maxWorkers
                : max($largestMaxWorkers, $state->maxWorkers);
        }

        return 1.0 / max($largestMaxWorkers ?? 1, 1);
    }

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     * @param  array<string, int>  $distribution
     * @param  array<string, int>  $assignedTotals
     */
    private function utilizationSpread(array $activeManagers, array $distribution, array $assignedTotals): float
    {
        $min = null;
        $max = null;

        foreach ($activeManagers as $state) {
            $utilization = $this->utilizationFor($state, ($assignedTotals[$state->managerId] ?? 0) + ($distribution[$state->managerId] ?? 0));
            $min = $min === null ? $utilization : min($min, $utilization);
            $max = $max === null ? $utilization : max($max, $utilization);
        }

        return ($max ?? 0.0) - ($min ?? 0.0);
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $assignedTotals
     */
    private function projectedUtilizationAfterAssignment(ClusterManagerState $state, array $targets, array $assignedTotals): float
    {
        return $this->utilizationFor($state, ($assignedTotals[$state->managerId] ?? 0) + ($targets[$state->managerId] ?? 0) + 1);
    }

    private function utilizationFor(ClusterManagerState $state, int $workers): float
    {
        return $workers / max($state->maxWorkers, 1);
    }
}
