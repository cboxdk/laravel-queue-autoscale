<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;

/**
 * Where cluster members find each other and agree who is in charge.
 *
 * Behind a contract for two reasons. Every other capability in this package
 * already is, so the cluster layer was the one place a consumer could not
 * substitute an implementation. And the concrete store reaches for the Redis
 * facade directly, which made everything that reads cluster state — the
 * telemetry snapshot, the facade's metric list, the manager's whole leader
 * path — untestable without live Redis.
 *
 * Redis remains the only shipped implementation. This is not an invitation to
 * write a database-backed one: leader election needs atomic compare-and-set
 * and a reliable TTL, and a store that fakes either will hand two managers the
 * same lease.
 */
interface ClusterStoreContract
{
    /**
     * Record that this manager is alive, with its current capacity.
     */
    public function heartbeat(ClusterManagerState $state): void;

    /**
     * Remove a manager from the registry, releasing leadership if it held it.
     */
    public function deregister(string $managerId): void;

    /**
     * Managers whose heartbeat has not yet expired.
     *
     * @return array<int, ClusterManagerState>
     */
    public function activeManagers(): array;

    public function leaderId(): ?string;

    /**
     * The fencing token of the current lease.
     *
     * A manager that stalls long enough to lose leadership and then wakes up
     * still believes it leads. The token changes on every election, so work
     * issued under an old one can be rejected on arrival.
     */
    public function leaderToken(): ?string;

    /**
     * Claim or renew leadership, returning whether this manager holds it.
     */
    public function isLeader(string $managerId): bool;

    public function publishRecommendation(ClusterRecommendation $recommendation): void;

    public function recommendationFor(string $managerId): ?ClusterRecommendation;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function publishSummary(array $summary): void;

    /**
     * @return array<string, mixed>
     */
    public function summary(): array;

    /**
     * Whether the backing store is reachable.
     */
    public function ping(): mixed;

    /**
     * @param  array<string, mixed>  $decision
     */
    public function recordDecision(array $decision): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentDecisions(int $seconds): array;
}
