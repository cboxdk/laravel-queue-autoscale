<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

final readonly class ClusterRecommendation
{
    /**
     * @param  array<string, int>  $workloads
     */
    public function __construct(
        public string $managerId,
        public int $issuedAt,
        public array $workloads,
    ) {}

    public static function queueWorkloadKey(string $connection, string $queue): string
    {
        return sprintf('queue:%s:%s', $connection, $queue);
    }

    public static function groupWorkloadKey(string $connection, string $group): string
    {
        return sprintf('group:%s:%s', $connection, $group);
    }

    public function targetForQueue(string $connection, string $queue): int
    {
        return (int) ($this->workloads[self::queueWorkloadKey($connection, $queue)] ?? 0);
    }

    public function targetForGroup(string $connection, string $group): int
    {
        return (int) ($this->workloads[self::groupWorkloadKey($connection, $group)] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manager_id' => $this->managerId,
            'issued_at' => $this->issuedAt,
            'workloads' => $this->workloads,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<string, int> $workloads */
        $workloads = [];
        $workloadsPayload = $payload['workloads'] ?? [];

        if (is_array($workloadsPayload)) {
            foreach ($workloadsPayload as $key => $count) {
                if (is_string($key) && is_numeric($count)) {
                    $workloads[$key] = (int) $count;
                }
            }
        }

        $managerId = $payload['manager_id'] ?? null;

        return new self(
            managerId: is_string($managerId) ? $managerId : '',
            issuedAt: is_numeric($payload['issued_at'] ?? null) ? (int) $payload['issued_at'] : 0,
            workloads: $workloads,
        );
    }
}
