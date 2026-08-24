<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Fixtures;

use Cbox\LaravelQueueAutoscale\Contracts\ClusterScopedPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingScope;

/**
 * A cluster-scope policy that caps the cluster-wide target and records every
 * consultation with the scope it saw.
 *
 * Demonstrates the intended shape of a global-budget policy: clamp once on
 * the Cluster-scoped decision the leader presents, leave the Host-scoped
 * decisions from the per-host apply path untouched.
 */
class RecordingClusterScopedPolicy implements ClusterScopedPolicy
{
    /** @var list<string> */
    public static array $seen = [];

    public static int $clusterCap = 3;

    public static function reset(int $clusterCap = 3): void
    {
        self::$seen = [];
        self::$clusterCap = $clusterCap;
    }

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        self::$seen[] = "before:{$decision->scope->value}:{$decision->queue}:{$decision->targetWorkers}";

        if ($decision->scope !== ScalingScope::Cluster) {
            return null;
        }

        return $decision->withTargetWorkers(
            min($decision->targetWorkers, self::$clusterCap),
            'policy:cluster-capped',
        );
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        self::$seen[] = "after:{$decision->scope->value}:{$decision->queue}:{$decision->targetWorkers}";
    }
}
