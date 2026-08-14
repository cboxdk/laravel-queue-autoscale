<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;

/**
 * distributeClusterTarget caches the layout it chose, so a steady cluster is
 * not reshuffled every cycle. That cache is a leader's working memory.
 *
 * Losing the lease and winning it back means another manager placed workers in
 * between, so anything remembered from before describes a cluster that no
 * longer exists. The cache is only checked for feasibility, so a stale layout
 * that still sums to the right total was replayed wholesale — every host
 * churning its workers to match a ten-minute-old picture, for no change in
 * demand.
 *
 * These drive the real cycle rather than reimplementing the transition, so
 * they fail if the reset moves or is removed.
 */
function leaderCache(AutoscaleManager $manager): array
{
    return (new ReflectionProperty($manager, 'previousDistributions'))->getValue($manager);
}

function primeLeaderCache(AutoscaleManager $manager, bool $wasLeader): void
{
    (new ReflectionProperty($manager, 'previousDistributions'))
        ->setValue($manager, ['queue:redis:exports' => ['a' => 10, 'b' => 10]]);
    (new ReflectionProperty($manager, 'wasLeader'))->setValue($manager, $wasLeader);
}

function noteLeadership(AutoscaleManager $manager, bool $isLeader): void
{
    (new ReflectionMethod($manager, 'noteLeadership'))->invoke($manager, $isLeader);
}

test('regaining the lease discards the layout from before', function (): void {
    $manager = app(AutoscaleManager::class);

    // This manager did not lead last cycle, and now wins the lease.
    primeLeaderCache($manager, wasLeader: false);

    noteLeadership($manager, isLeader: true);

    expect(leaderCache($manager))->not->toHaveKey('queue:redis:exports');
});

test('an uninterrupted leader keeps its layout', function (): void {
    // The cache exists to stop a steady cluster reshuffling every cycle, so
    // clearing it on every leader cycle would defeat its purpose.
    $manager = app(AutoscaleManager::class);

    primeLeaderCache($manager, wasLeader: true);

    noteLeadership($manager, isLeader: true);

    expect(leaderCache($manager))->toHaveKey('queue:redis:exports');
});
