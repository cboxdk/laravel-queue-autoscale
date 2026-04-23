<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale;

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

final readonly class LaravelQueueAutoscale
{
    public function __construct(
        private ClusterStore $clusterStore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function cluster(): array
    {
        if (! AutoscaleConfiguration::clusterEnabled()) {
            return [];
        }

        return $this->clusterStore->summary();
    }

    /**
     * @return array<int, array{name: string, value: int|float, labels: array<string, scalar|null>}>
     */
    public function clusterMetrics(): array
    {
        $summary = $this->cluster();

        if ($summary === []) {
            return [];
        }

        $scaleSignal = is_array($summary['scale_signal'] ?? null) ? $summary['scale_signal'] : [];
        $metrics = [
            [
                'name' => 'queue_autoscale_cluster_managers',
                'value' => $this->metricInt($summary['manager_count'] ?? 0),
                'labels' => ['cluster' => $this->metricLabel($summary['cluster_id'] ?? null)],
            ],
            [
                'name' => 'queue_autoscale_cluster_workers_current',
                'value' => $this->metricInt($summary['total_workers'] ?? 0),
                'labels' => ['cluster' => $this->metricLabel($summary['cluster_id'] ?? null)],
            ],
            [
                'name' => 'queue_autoscale_cluster_workers_required',
                'value' => $this->metricInt($summary['required_workers'] ?? 0),
                'labels' => ['cluster' => $this->metricLabel($summary['cluster_id'] ?? null)],
            ],
            [
                'name' => 'queue_autoscale_cluster_worker_capacity',
                'value' => $this->metricInt($summary['total_worker_capacity'] ?? 0),
                'labels' => ['cluster' => $this->metricLabel($summary['cluster_id'] ?? null)],
            ],
            [
                'name' => 'queue_autoscale_cluster_hosts_recommended',
                'value' => $this->metricInt($scaleSignal['recommended_hosts'] ?? 0),
                'labels' => ['cluster' => $this->metricLabel($summary['cluster_id'] ?? null)],
            ],
        ];

        $managers = is_iterable($summary['managers'] ?? null) ? $summary['managers'] : [];

        foreach ($managers as $manager) {
            if (! is_array($manager)) {
                continue;
            }

            $labels = [
                'cluster' => $this->metricLabel($summary['cluster_id'] ?? null),
                'manager_id' => $this->metricLabel($manager['manager_id'] ?? null),
                'host' => $this->metricLabel($manager['host'] ?? null),
                'leader' => (bool) ($manager['is_leader'] ?? false) ? 'true' : 'false',
            ];

            $metrics[] = [
                'name' => 'queue_autoscale_manager_workers',
                'value' => $this->metricInt($manager['total_workers'] ?? 0),
                'labels' => $labels,
            ];
            $metrics[] = [
                'name' => 'queue_autoscale_manager_capacity',
                'value' => $this->metricInt($manager['max_workers'] ?? 0),
                'labels' => $labels,
            ];
            $metrics[] = [
                'name' => 'queue_autoscale_manager_cpu_percent',
                'value' => $this->metricFloat($manager['cpu_percent'] ?? 0.0),
                'labels' => $labels,
            ];
            $metrics[] = [
                'name' => 'queue_autoscale_manager_memory_percent',
                'value' => $this->metricFloat($manager['memory_percent'] ?? 0.0),
                'labels' => $labels,
            ];
        }

        $workloads = is_iterable($summary['workloads'] ?? null) ? $summary['workloads'] : [];

        foreach ($workloads as $workload) {
            if (! is_array($workload)) {
                continue;
            }

            $labels = [
                'cluster' => $this->metricLabel($summary['cluster_id'] ?? null),
                'type' => $this->metricLabel($workload['type'] ?? null),
                'connection' => $this->metricLabel($workload['connection'] ?? null),
                'name' => $this->metricLabel($workload['name'] ?? null),
            ];

            $metrics[] = [
                'name' => 'queue_autoscale_workload_workers_current',
                'value' => $this->metricInt($workload['current_workers'] ?? 0),
                'labels' => $labels,
            ];
            $metrics[] = [
                'name' => 'queue_autoscale_workload_workers_target',
                'value' => $this->metricInt($workload['target_workers'] ?? 0),
                'labels' => $labels,
            ];
            $metrics[] = [
                'name' => 'queue_autoscale_workload_pending_jobs',
                'value' => $this->metricInt($workload['pending'] ?? 0),
                'labels' => $labels,
            ];
            $metrics[] = [
                'name' => 'queue_autoscale_workload_oldest_job_age_seconds',
                'value' => $this->metricInt($workload['oldest_job_age'] ?? 0),
                'labels' => $labels,
            ];
        }

        return $metrics;
    }

    private function metricLabel(mixed $value): bool|float|int|string|null
    {
        if (is_bool($value) || is_float($value) || is_int($value) || is_string($value) || $value === null) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    private function metricInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function metricFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
