<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

/**
 * Builds the cluster-wide picture an operator reads: per-workload totals, the
 * fleet's capacity headroom, and whether the cluster as a whole wants more
 * hosts.
 *
 * Purely descriptive — nothing here changes a worker count. It is separate
 * from the leader's decision path so that a change to how the cluster is
 * reported can never alter how it is scaled.
 */
class ClusterSummaryBuilder
{
    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     * @param  array<int, array<string, int|float|string|list<string>>>  $workloads
     * @param  array<int, array<string, mixed>>  $scalingDecisions
     * @return array<string, mixed>
     */
    public function build(array $activeManagers, array $workloads, array $scalingDecisions = []): array
    {
        $workloadSortKey = static function (array $workload): string {
            $type = is_string($workload['type'] ?? null) ? $workload['type'] : '';
            $connection = is_string($workload['connection'] ?? null) ? $workload['connection'] : '';
            $name = is_string($workload['name'] ?? null) ? $workload['name'] : '';

            return "{$type}:{$connection}:{$name}";
        };

        usort(
            $workloads,
            static fn (array $a, array $b): int => strcmp($workloadSortKey($a), $workloadSortKey($b)),
        );

        $currentHosts = count($activeManagers);
        $totalWorkerCapacity = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->maxWorkers, $activeManagers));
        $requiredWorkers = array_sum(array_map(static fn (array $workload): int => (int) $workload['demand'], $workloads));
        $totalWorkers = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->totalWorkers, $activeManagers));
        $recommendedHosts = $this->recommendedHostCount($activeManagers, $requiredWorkers);
        $signal = $this->clusterScaleSignal($currentHosts, $recommendedHosts, $requiredWorkers, $totalWorkerCapacity, $totalWorkers, $workloads);
        $generatedAt = now();
        $generatedAtMs = $this->currentTimestamp();
        $leaderLeaseTtlSeconds = AutoscaleConfiguration::clusterLeaderLeaseSeconds();
        $leaderExpiresAt = $generatedAt->copy()->addSeconds($leaderLeaseTtlSeconds);

        $managers = array_map(function (ClusterManagerState $state): array {
            return [
                'manager_id' => $state->managerId,
                'host' => $state->host,
                'is_leader' => $state->managerId === AutoscaleConfiguration::managerId(),
                'last_seen_at' => $state->lastSeenAt,
                'last_seen_human' => now()->setTimestamp((int) floor($state->lastSeenAt / 1000))->diffForHumans(),
                'total_workers' => $state->totalWorkers,
                'max_workers' => $state->maxWorkers,
                'available_worker_capacity' => $state->availableWorkerCapacity,
                'capacity_limiter' => $state->capacityLimiter,
                'cpu_percent' => round($state->cpuPercent, 1),
                'cpu_cores' => $state->cpuCores,
                'cpu_usable_cores' => $state->cpuUsableCores,
                'cpu_reserved_cores' => $state->cpuReservedCores,
                'memory_percent' => round($state->memoryPercent, 1),
                'memory_total_mb' => round($state->memoryTotalMb, 1),
                'memory_used_mb' => round($state->memoryUsedMb, 1),
                'memory_free_mb' => round($state->memoryFreeMb, 1),
                'queue_count' => $state->queueCount,
                'group_count' => $state->groupCount,
                'package_version' => $state->packageVersion,
                'queue_workers' => $state->queueWorkers,
                'group_workers' => $state->groupWorkers,
            ];
        }, $activeManagers);

        return [
            'cluster_id' => AutoscaleConfiguration::clusterAppId(),
            'generated_at' => $generatedAt->toIso8601String(),
            'generated_at_unix_ms' => $generatedAtMs,
            'leader_id' => AutoscaleConfiguration::managerId(),
            'leader_renewed_at' => $generatedAt->toIso8601String(),
            'leader_renewed_at_unix_ms' => $generatedAtMs,
            'leader_lease_ttl_seconds' => $leaderLeaseTtlSeconds,
            'leader_expires_at' => $leaderExpiresAt->toIso8601String(),
            'manager_count' => $currentHosts,
            'total_workers' => $totalWorkers,
            'required_workers' => $requiredWorkers,
            'total_worker_capacity' => $totalWorkerCapacity,
            'utilization_percent' => $totalWorkerCapacity > 0 ? round(($requiredWorkers / $totalWorkerCapacity) * 100, 1) : 0.0,
            'scale_signal' => $signal,
            'managers' => $managers,
            'workloads' => array_map(function (array $workload): array {
                $workload['action'] = match ((int) $workload['action']) {
                    1 => 'scale_up',
                    -1 => 'scale_down',
                    default => 'hold',
                };

                return $workload;
            }, $workloads),
            'scaling_decisions' => $scalingDecisions,
        ];
    }

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     */
    private function recommendedHostCount(array $activeManagers, int $requiredWorkers): int
    {
        if ($activeManagers === []) {
            return 0;
        }

        if ($requiredWorkers <= 0) {
            return 1;
        }

        $capacities = array_map(static fn (ClusterManagerState $state): int => max($state->maxWorkers, 1), $activeManagers);
        rsort($capacities);

        $accumulated = 0;
        foreach ($capacities as $index => $capacity) {
            $accumulated += $capacity;

            if ($accumulated >= $requiredWorkers) {
                return $index + 1;
            }
        }

        $currentHosts = count($capacities);
        $averageCapacity = max((int) floor(array_sum($capacities) / max($currentHosts, 1)), 1);
        $remaining = max($requiredWorkers - $accumulated, 0);

        return $currentHosts + (int) ceil($remaining / $averageCapacity);
    }

    /**
     * @param  array<int, array<string, mixed>>  $workloads
     * @return array<string, int|string>
     */
    private function clusterScaleSignal(
        int $currentHosts,
        int $recommendedHosts,
        int $requiredWorkers,
        int $totalWorkerCapacity,
        int $totalWorkers,
        array $workloads,
    ): array {
        if ($requiredWorkers > $totalWorkerCapacity) {
            return [
                'action' => 'scale_up',
                'reason' => 'required workers exceed observed cluster capacity',
                'current_hosts' => $currentHosts,
                'recommended_hosts' => max($recommendedHosts, $currentHosts + 1),
            ];
        }

        if ($recommendedHosts < $currentHosts) {
            // Do not recommend scale-down when the cluster is under pressure.
            $utilizationPercent = $totalWorkerCapacity > 0
                ? ($totalWorkers / $totalWorkerCapacity) * 100
                : 0.0;

            $hasScaleUpPressure = false;
            foreach ($workloads as $workload) {
                $target = is_numeric($workload['target_workers'] ?? null) ? (int) $workload['target_workers'] : 0;
                $current = is_numeric($workload['current_workers'] ?? null) ? (int) $workload['current_workers'] : 0;
                $pending = is_numeric($workload['pending'] ?? null) ? (int) $workload['pending'] : 0;

                if ($target > $current || $pending > 0) {
                    $hasScaleUpPressure = true;

                    break;
                }
            }

            if ($utilizationPercent >= 80.0 || $hasScaleUpPressure) {
                return [
                    'action' => 'hold',
                    'reason' => $utilizationPercent >= 80.0
                        ? sprintf('high utilization (%.0f%%) prevents scale-down', $utilizationPercent)
                        : 'pending workload prevents scale-down',
                    'current_hosts' => $currentHosts,
                    'recommended_hosts' => $currentHosts,
                ];
            }

            return [
                'action' => 'scale_down',
                'reason' => 'required workers fit on fewer hosts',
                'current_hosts' => $currentHosts,
                'recommended_hosts' => max($recommendedHosts, 1),
            ];
        }

        return [
            'action' => 'hold',
            'reason' => 'current host count matches required worker capacity',
            'current_hosts' => $currentHosts,
            'recommended_hosts' => max($recommendedHosts, 1),
        ];
    }

    private function currentTimestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
