<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Testing;

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;

/**
 * An in-memory cluster store, so cluster behaviour can be tested without Redis.
 *
 * The real store needs Redis for a reason — leader election depends on atomic
 * compare-and-set and a reliable TTL. This one does not attempt either: it
 * hands leadership to whoever asks first and keeps it there until told
 * otherwise. That is a deliberate simplification, and it means this fake can
 * verify what a manager *does* with cluster state, never whether the election
 * itself is sound under contention.
 */
class FakeClusterStore implements ClusterStoreContract
{
    /** @var array<string, ClusterManagerState> */
    private array $managers = [];

    private ?string $leaderId = null;

    private ?string $leaderToken = null;

    /** @var array<string, ClusterRecommendation> */
    private array $recommendations = [];

    /** @var array<string, mixed> */
    private array $summary = [];

    /** @var array<int, array<string, mixed>> */
    private array $decisions = [];

    private int $tokenCounter = 0;

    /**
     * Place a manager in the cluster without it having to heartbeat first.
     */
    public function withManager(ClusterManagerState $state): self
    {
        $this->managers[$state->managerId] = $state;

        return $this;
    }

    /**
     * Decide who leads, rather than letting first-come-first-served decide.
     */
    public function withLeader(?string $managerId): self
    {
        $this->leaderId = $managerId;
        $this->leaderToken = $managerId === null ? null : 'token-'.(++$this->tokenCounter);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function withSummary(array $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * Recommendations published during the test, keyed by manager.
     *
     * @return array<string, ClusterRecommendation>
     */
    public function publishedRecommendations(): array
    {
        return $this->recommendations;
    }

    public function heartbeat(ClusterManagerState $state): void
    {
        $this->managers[$state->managerId] = $state;
    }

    public function deregister(string $managerId): void
    {
        unset($this->managers[$managerId], $this->recommendations[$managerId]);

        if ($this->leaderId === $managerId) {
            $this->leaderId = null;
            $this->leaderToken = null;
        }
    }

    public function activeManagers(): array
    {
        return array_values($this->managers);
    }

    public function leaderId(): ?string
    {
        return $this->leaderId;
    }

    public function leaderToken(): ?string
    {
        return $this->leaderToken;
    }

    public function isLeader(string $managerId): bool
    {
        if ($this->leaderId === null) {
            $this->withLeader($managerId);
        }

        return $this->leaderId === $managerId;
    }

    public function publishRecommendation(ClusterRecommendation $recommendation): void
    {
        $this->recommendations[$recommendation->managerId] = $recommendation;
    }

    public function recommendationFor(string $managerId): ?ClusterRecommendation
    {
        return $this->recommendations[$managerId] ?? null;
    }

    public function publishSummary(array $summary): void
    {
        $this->summary = $summary;
    }

    public function summary(): array
    {
        return $this->summary;
    }

    public function ping(): mixed
    {
        return true;
    }

    public function recordDecision(array $decision): void
    {
        $this->decisions[] = $decision;
    }

    public function recentDecisions(int $seconds): array
    {
        return $this->decisions;
    }
}
