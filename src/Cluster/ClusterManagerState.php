<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

final readonly class ClusterManagerState
{
    /**
     * @param  array<string, int>  $queueWorkers
     * @param  array<string, int>  $groupWorkers
     */
    public function __construct(
        public string $managerId,
        public string $host,
        public int $lastSeenAt,
        public int $totalWorkers,
        public int $maxWorkers,
        public int $availableWorkerCapacity,
        public string $capacityLimiter,
        public float $cpuPercent,
        public float $memoryPercent,
        public float $memoryTotalMb,
        public float $memoryUsedMb,
        public float $memoryFreeMb,
        public int $queueCount,
        public int $groupCount,
        public string $packageVersion,
        public array $queueWorkers,
        public array $groupWorkers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manager_id' => $this->managerId,
            'host' => $this->host,
            'last_seen_at' => $this->lastSeenAt,
            'total_workers' => $this->totalWorkers,
            'max_workers' => $this->maxWorkers,
            'available_worker_capacity' => $this->availableWorkerCapacity,
            'capacity_limiter' => $this->capacityLimiter,
            'cpu_percent' => $this->cpuPercent,
            'memory_percent' => $this->memoryPercent,
            'memory_total_mb' => $this->memoryTotalMb,
            'memory_used_mb' => $this->memoryUsedMb,
            'memory_free_mb' => $this->memoryFreeMb,
            'queue_count' => $this->queueCount,
            'group_count' => $this->groupCount,
            'package_version' => $this->packageVersion,
            'queue_workers' => $this->queueWorkers,
            'group_workers' => $this->groupWorkers,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<string, int> $queueWorkers */
        $queueWorkers = [];
        $queueWorkersPayload = $payload['queue_workers'] ?? [];
        if (is_array($queueWorkersPayload)) {
            foreach ($queueWorkersPayload as $key => $count) {
                if (is_string($key) && is_numeric($count)) {
                    $queueWorkers[$key] = (int) $count;
                }
            }
        }

        /** @var array<string, int> $groupWorkers */
        $groupWorkers = [];
        $groupWorkersPayload = $payload['group_workers'] ?? [];
        if (is_array($groupWorkersPayload)) {
            foreach ($groupWorkersPayload as $key => $count) {
                if (is_string($key) && is_numeric($count)) {
                    $groupWorkers[$key] = (int) $count;
                }
            }
        }

        $managerId = $payload['manager_id'] ?? null;
        $host = $payload['host'] ?? null;

        return new self(
            managerId: is_string($managerId) ? $managerId : '',
            host: is_string($host) ? $host : '',
            lastSeenAt: is_numeric($payload['last_seen_at'] ?? null) ? (int) $payload['last_seen_at'] : 0,
            totalWorkers: is_numeric($payload['total_workers'] ?? null) ? (int) $payload['total_workers'] : 0,
            maxWorkers: is_numeric($payload['max_workers'] ?? null) ? (int) $payload['max_workers'] : 0,
            availableWorkerCapacity: is_numeric($payload['available_worker_capacity'] ?? null) ? (int) $payload['available_worker_capacity'] : 0,
            capacityLimiter: is_string($payload['capacity_limiter'] ?? null) ? $payload['capacity_limiter'] : 'unknown',
            cpuPercent: is_numeric($payload['cpu_percent'] ?? null) ? (float) $payload['cpu_percent'] : 0.0,
            memoryPercent: is_numeric($payload['memory_percent'] ?? null) ? (float) $payload['memory_percent'] : 0.0,
            memoryTotalMb: is_numeric($payload['memory_total_mb'] ?? null) ? (float) $payload['memory_total_mb'] : 0.0,
            memoryUsedMb: is_numeric($payload['memory_used_mb'] ?? null) ? (float) $payload['memory_used_mb'] : 0.0,
            memoryFreeMb: is_numeric($payload['memory_free_mb'] ?? null) ? (float) $payload['memory_free_mb'] : 0.0,
            queueCount: is_numeric($payload['queue_count'] ?? null) ? (int) $payload['queue_count'] : 0,
            groupCount: is_numeric($payload['group_count'] ?? null) ? (int) $payload['group_count'] : 0,
            packageVersion: is_string($payload['package_version'] ?? null) ? $payload['package_version'] : 'unknown',
            queueWorkers: $queueWorkers,
            groupWorkers: $groupWorkers,
        );
    }
}
