<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

readonly class ClusterRecommendation
{
    /**
     * @param  array<string, int>  $workloads
     */
    public function __construct(
        public string $managerId,
        public int $issuedAt,
        public array $workloads,
        public ?string $leaderId = null,
        public ?string $leaderToken = null,
    ) {}

    public static function queueWorkloadKey(string $connection, string $queue): string
    {
        return sprintf('queue:%s:%s', $connection, $queue);
    }

    public static function groupWorkloadKey(string $connection, string $group): string
    {
        return sprintf('group:%s:%s', $connection, $group);
    }

    /**
     * The leader's target for a workload, or null if it published none.
     *
     * Absent is NOT the same as zero. The leader emits a key for every
     * workload on every active manager, so a missing key means the leader
     * does not know about this workload — a rolling deploy where the leader's
     * config lacks a group, or a leader that failed group validation and
     * published nothing. Reading that as a target of zero made every follower
     * terminate all of its group workers, stopping those queues cluster-wide
     * with a single log line on the leader as the only signal.
     */
    public function targetForQueue(string $connection, string $queue): ?int
    {
        $key = self::queueWorkloadKey($connection, $queue);

        return isset($this->workloads[$key]) ? (int) $this->workloads[$key] : null;
    }

    public function targetForGroup(string $connection, string $group): ?int
    {
        $key = self::groupWorkloadKey($connection, $group);

        return isset($this->workloads[$key]) ? (int) $this->workloads[$key] : null;
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
            'leader_id' => $this->leaderId,
            'leader_token' => $this->leaderToken,
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
        $leaderId = $payload['leader_id'] ?? null;
        $leaderToken = $payload['leader_token'] ?? null;

        return new self(
            managerId: is_string($managerId) ? $managerId : '',
            issuedAt: is_numeric($payload['issued_at'] ?? null) ? (int) $payload['issued_at'] : 0,
            workloads: $workloads,
            leaderId: is_string($leaderId) && $leaderId !== '' ? $leaderId : null,
            leaderToken: is_string($leaderToken) && $leaderToken !== '' ? $leaderToken : null,
        );
    }
}
