<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

class ClusterStore
{
    public function heartbeat(ClusterManagerState $state): void
    {
        $redis = $this->redis();

        $redis->setex(
            $this->managerStateKey($state->managerId),
            AutoscaleConfiguration::clusterHeartbeatTtlSeconds(),
            $this->encode($state->toArray()),
        );

        $redis->sadd($this->managersRegistryKey(), $state->managerId);
    }

    /**
     * @return array<int, ClusterManagerState>
     */
    public function activeManagers(): array
    {
        $redis = $this->redis();
        $ids = $redis->smembers($this->managersRegistryKey());
        $active = [];
        $stale = [];

        foreach ($ids as $id) {
            if ($id === '') {
                continue;
            }

            $payload = $redis->get($this->managerStateKey($id));
            $decoded = $this->decode($payload);

            if (! is_array($decoded)) {
                $stale[] = $id;

                continue;
            }

            $state = ClusterManagerState::fromArray($decoded);

            if ($state->managerId === '') {
                $stale[] = $id;

                continue;
            }

            $active[] = $state;
        }

        if ($stale !== []) {
            foreach ($stale as $managerId) {
                $redis->srem($this->managersRegistryKey(), $managerId);
            }
        }

        usort(
            $active,
            static fn (ClusterManagerState $a, ClusterManagerState $b): int => strcmp($a->managerId, $b->managerId),
        );

        return $active;
    }

    public function leaderId(): ?string
    {
        $payload = $this->decode($this->redis()->get($this->leaderKey()));

        if (! is_array($payload)) {
            return null;
        }

        $managerId = $payload['manager_id'] ?? null;

        return is_string($managerId) && $managerId !== '' ? $managerId : null;
    }

    public function isLeader(string $managerId): bool
    {
        $redis = $this->redis();
        $current = $this->leaderId();
        $payload = [
            'manager_id' => $managerId,
            'renewed_at' => $this->currentTimestamp(),
        ];

        if ($current === $managerId) {
            $redis->setex(
                $this->leaderKey(),
                AutoscaleConfiguration::clusterLeaderLeaseSeconds(),
                $this->encode($payload),
            );

            return true;
        }

        $script = <<<'LUA'
if redis.call('exists', KEYS[1]) == 0 then
    redis.call('setex', KEYS[1], ARGV[2], ARGV[1])
    return 1
end

return 0
LUA;
        $leaderKey = $this->leaderKey();
        $encodedPayload = $this->encode($payload);
        $ttl = AutoscaleConfiguration::clusterLeaderLeaseSeconds();

        $acquired = $redis instanceof PhpRedisConnection
            ? $redis->command('eval', [$script, [$leaderKey, $encodedPayload, $ttl], 1])
            : $redis->command('eval', [$script, 1, $leaderKey, $encodedPayload, $ttl]);

        return $acquired === true || $acquired === 1 || $acquired === '1';
    }

    public function publishRecommendation(ClusterRecommendation $recommendation): void
    {
        $this->redis()->setex(
            $this->recommendationKey($recommendation->managerId),
            AutoscaleConfiguration::clusterRecommendationTtlSeconds(),
            $this->encode($recommendation->toArray()),
        );
    }

    public function recommendationFor(string $managerId): ?ClusterRecommendation
    {
        $payload = $this->decode($this->redis()->get($this->recommendationKey($managerId)));

        if (! is_array($payload)) {
            return null;
        }

        return ClusterRecommendation::fromArray($payload);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function publishSummary(array $summary): void
    {
        $this->redis()->setex(
            $this->summaryKey(),
            AutoscaleConfiguration::clusterSummaryTtlSeconds(),
            $this->encode($summary),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $payload = $this->decode($this->redis()->get($this->summaryKey()));

        return is_array($payload) ? $payload : [];
    }

    public function ping(): mixed
    {
        return $this->redis()->ping();
    }

    /**
     * Persist a scaling decision to the rolling history sorted set.
     *
     * Uses a Lua script to atomically add, time-prune, and count-prune
     * in a single round-trip.
     *
     * @param  array<string, mixed>  $decision
     */
    public function recordDecision(array $decision): void
    {
        $redis = $this->redis();
        $now = microtime(true);

        $decision['recorded_at'] = $now;

        $key = $this->decisionsHistoryKey();
        $member = json_encode($decision, JSON_THROW_ON_ERROR);
        $score = (string) $now;
        $historySeconds = AutoscaleConfiguration::decisionHistorySeconds();
        $cutoff = (string) ($now - $historySeconds);
        $rankStop = (string) -(AutoscaleConfiguration::decisionHistoryMax() + 1);
        $ttl = (string) $historySeconds;

        $script = <<<'LUA'
redis.call('zadd', KEYS[1], ARGV[1], ARGV[2])
redis.call('zremrangebyscore', KEYS[1], '-inf', ARGV[3])
redis.call('zremrangebyrank', KEYS[1], 0, tonumber(ARGV[4]))
redis.call('expire', KEYS[1], tonumber(ARGV[5]))
return 1
LUA;

        if ($redis instanceof PhpRedisConnection) {
            $redis->command('eval', [$script, [$key, $score, $member, $cutoff, $rankStop, $ttl], 1]);
        } else {
            $redis->command('eval', [$script, 1, $key, $score, $member, $cutoff, $rankStop, $ttl]);
        }
    }

    /**
     * Retrieve recent decisions within the given time window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentDecisions(int $seconds): array
    {
        $members = $this->redis()->zrangebyscore(
            $this->decisionsHistoryKey(),
            (string) (microtime(true) - $seconds),
            '+inf',
        );

        if (! is_array($members)) {
            return [];
        }

        $decisions = [];

        foreach ($members as $json) {
            if (! is_string($json) || $json === '') {
                continue;
            }

            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                $decisions[] = $decoded;
            }
        }

        return $decisions;
    }

    private function currentTimestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function managersRegistryKey(): string
    {
        return $this->key('managers');
    }

    private function managerStateKey(string $managerId): string
    {
        return $this->key("manager:{$managerId}:state");
    }

    private function leaderKey(): string
    {
        return $this->key('leader');
    }

    private function recommendationKey(string $managerId): string
    {
        return $this->key("manager:{$managerId}:recommendation");
    }

    private function summaryKey(): string
    {
        return $this->key('summary');
    }

    private function decisionsHistoryKey(): string
    {
        return $this->key('decisions:history');
    }

    private function key(string $suffix): string
    {
        return sprintf('queue-autoscale:cluster:%s:%s', AutoscaleConfiguration::clusterAppId(), $suffix);
    }

    private function redis(): Connection
    {
        return Redis::connection();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $payload): ?array
    {
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $normalized = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
