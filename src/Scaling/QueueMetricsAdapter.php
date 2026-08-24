<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Support\Coerce;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

/**
 * Translates between the metrics package's array payloads and the typed
 * QueueMetricsData the scaling strategies consume.
 *
 * This is the package's serialization boundary with laravel-queue-metrics:
 * arrays are legitimate on this side of it, and nothing past this class should
 * be threading raw metric arrays around.
 */
class QueueMetricsAdapter
{
    /**
     * Read one queue's metrics directly, bypassing discovery.
     *
     * Used for queues that are configured but have no history yet, so a newly
     * declared queue is supervised from the first cycle rather than after the
     * metrics layer has seen traffic on it.
     *
     * @return array<string, mixed> in the shape getAllQueuesWithMetrics() returns
     */
    public function forQueue(string $connection, string $queue): array
    {
        $depth = QueueMetrics::getQueueDepth($connection, $queue);
        $queueMetrics = QueueMetrics::getQueueMetrics($connection, $queue);

        $oldestJobAgeSeconds = 0;
        if ($depth->oldestPendingJobAge !== null) {
            $oldestJobAgeSeconds = (int) $depth->oldestPendingJobAge->diffInSeconds(now());
        }

        $total = $depth->pendingJobs + $depth->delayedJobs + $depth->reservedJobs;

        return [
            'connection' => $connection,
            'queue' => $queue,
            'driver' => Coerce::toString(config("queue.connections.{$connection}.driver", 'unknown'), 'unknown'),
            'depth' => [
                'total' => $total,
                'pending' => $depth->pendingJobs,
                'scheduled' => $depth->delayedJobs,
                'reserved' => $depth->reservedJobs,
                'oldest_job_age_seconds' => $oldestJobAgeSeconds,
                'oldest_job_age_status' => $queueMetrics->ageStatus,
            ],
            'performance_60s' => [
                'throughput_per_minute' => $queueMetrics->throughputPerMinute,
                'avg_duration_ms' => $queueMetrics->avgDuration, // Already in ms from metrics package
                'window_seconds' => 60,
            ],
            'lifetime' => [
                'failure_rate_percent' => $queueMetrics->failureRate,
            ],
            'workers' => [
                'active_count' => $queueMetrics->activeWorkers,
                'current_busy_percent' => $queueMetrics->utilizationRate,
                'lifetime_busy_percent' => 0,
            ],
            'baseline' => null,
            'trends' => [],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Map field names from getAllQueuesWithMetrics() to QueueMetricsData::fromArray() format.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapFields(array $data): array
    {
        // Merge baseline and trends data into health array
        // These will be passed through to HealthStats::fromArray() but ignored by it
        // We'll access them as raw array data in the strategy
        $healthBase = $data['health'] ?? [];
        $healthData = array_merge(
            is_array($healthBase) ? $healthBase : [],
            [
                'baseline' => $data['baseline'] ?? null,
                'trend' => $data['trends'] ?? null,
                'percentiles' => $data['percentiles'] ?? null,
            ]
        );

        // Extract nested depth data
        /** @var array<string, mixed>|int $depthData */
        $depthData = $data['depth'] ?? [];
        $depth = is_array($depthData) ? Coerce::toInt($depthData['total'] ?? 0) : Coerce::toInt($depthData);
        $pending = is_array($depthData) ? Coerce::toInt($depthData['pending'] ?? 0) : 0;
        $scheduled = is_array($depthData) ? Coerce::toInt($depthData['scheduled'] ?? 0) : 0;
        $reserved = is_array($depthData) ? Coerce::toInt($depthData['reserved'] ?? 0) : 0;
        $oldestJobAge = is_array($depthData) ? Coerce::toInt($depthData['oldest_job_age_seconds'] ?? 0) : 0;

        // Extract nested performance data
        /** @var array<string, mixed> $perfData */
        $perfData = is_array($data['performance_60s'] ?? null) ? $data['performance_60s'] : [];
        $throughput = Coerce::toFloat($perfData['throughput_per_minute'] ?? 0.0);
        $avgDurationMs = Coerce::toFloat($perfData['avg_duration_ms'] ?? 0.0);

        // Extract nested lifetime data
        /** @var array<string, mixed> $lifetimeData */
        $lifetimeData = is_array($data['lifetime'] ?? null) ? $data['lifetime'] : [];
        $failureRate = Coerce::toFloat($lifetimeData['failure_rate_percent'] ?? 0.0);

        // Extract nested workers data
        /** @var array<string, mixed> $workersData */
        $workersData = is_array($data['workers'] ?? null) ? $data['workers'] : [];
        $activeWorkers = Coerce::toInt($workersData['active_count'] ?? 0);
        $utilizationRate = Coerce::toFloat($workersData['current_busy_percent'] ?? 0.0);

        return [
            'connection' => Coerce::toString($data['connection'] ?? null, 'default'),
            'queue' => Coerce::toString($data['queue'] ?? null, 'default'),
            'depth' => $depth,
            'pending' => $pending,
            'scheduled' => $scheduled,
            'reserved' => $reserved,
            'oldest_job_age' => $oldestJobAge,
            'age_status' => Coerce::toString($depthData['oldest_job_age_status'] ?? null, 'normal'),
            'throughput_per_minute' => $throughput,
            'avg_duration' => $avgDurationMs / 1000.0, // Convert ms to seconds
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilizationRate,
            'active_workers' => $activeWorkers,
            'driver' => Coerce::toString($data['driver'] ?? null, 'unknown'),
            'health' => $healthData,
            'calculated_at' => $data['timestamp'] ?? now()->toIso8601String(),
        ];
    }

    /**
     * Aggregate a group's member queues into one synthetic workload.
     *
     * - depth fields: SUM across members
     * - oldestJobAge: MAX (the group's SLA is breached by its worst queue)
     * - throughput: SUM
     * - avgDuration: throughput-weighted mean, falling back to a plain mean
     * - utilizationRate: MAX
     * - activeWorkers: SUM (informational — ours is derived from pool count)
     * - failureRate: MAX across members
     *
     * @param  array<string, QueueMetricsData>  $metricsByKey
     */
    public function aggregateGroup(GroupConfiguration $group, array $metricsByKey): QueueMetricsData
    {
        $pending = 0;
        $scheduled = 0;
        $reserved = 0;
        $oldestJobAge = 0;
        $throughput = 0.0;
        $weightedDurationNumer = 0.0;
        $weightedDurationDenom = 0.0;
        $rawDurations = [];
        $utilization = 0.0;
        $activeWorkers = 0;
        $failureRate = 0.0;
        $driver = 'unknown';

        foreach ($group->queues as $queue) {
            $k = "{$group->connection}:{$queue}";

            if (! isset($metricsByKey[$k])) {
                continue;
            }

            $m = $metricsByKey[$k];

            $pending += $m->pending;
            $scheduled += $m->scheduled;
            $reserved += $m->reserved;
            $oldestJobAge = max($oldestJobAge, $m->oldestJobAge);
            $throughput += $m->throughputPerMinute;
            $utilization = max($utilization, $m->utilizationRate);
            $activeWorkers += $m->activeWorkers;
            $failureRate = max($failureRate, $m->failureRate);

            if ($driver === 'unknown') {
                $driver = $m->driver;
            }

            if ($m->avgDuration > 0.0) {
                $rawDurations[] = $m->avgDuration;

                if ($m->throughputPerMinute > 0.0) {
                    $weightedDurationNumer += $m->avgDuration * $m->throughputPerMinute;
                    $weightedDurationDenom += $m->throughputPerMinute;
                }
            }
        }

        $avgDuration = 0.0;

        if ($weightedDurationDenom > 0.0) {
            $avgDuration = $weightedDurationNumer / $weightedDurationDenom;
        } elseif ($rawDurations !== []) {
            $avgDuration = array_sum($rawDurations) / count($rawDurations);
        }

        $depth = $pending + $scheduled + $reserved;
        $ageStatus = $oldestJobAge > $group->sla->targetSeconds ? 'breached'
            : ($oldestJobAge > $group->sla->targetSeconds * 0.8 ? 'warning' : 'normal');

        return QueueMetricsData::fromArray([
            'connection' => $group->connection,
            'queue' => $group->name,
            'depth' => $depth,
            'pending' => $pending,
            'scheduled' => $scheduled,
            'reserved' => $reserved,
            'oldest_job_age' => $oldestJobAge,
            'age_status' => $ageStatus,
            'throughput_per_minute' => $throughput,
            'avg_duration' => $avgDuration,
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilization,
            'active_workers' => $activeWorkers,
            'driver' => $driver,
            'health' => [],
            'calculated_at' => now()->toIso8601String(),
        ]);
    }
}
