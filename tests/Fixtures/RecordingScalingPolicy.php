<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Fixtures;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * A policy that records what it was asked and caps what it returns.
 *
 * State is static because the policy is constructed by the container, not by
 * the spec, so there is no instance for a test to hold on to. Every spec using
 * it must call reset() first — see the beforeEach in the cluster policy tests.
 *
 * Lives under tests/Fixtures so PHPStan analyses it: a test double that stands
 * in for a contract is worth type-checking against that contract, since a
 * signature drift here would otherwise surface as a confusing test failure
 * rather than an analysis error.
 */
class RecordingScalingPolicy implements ScalingPolicy
{
    /** @var list<string> */
    public static array $seen = [];

    public static int $capTo = 2;

    public static function reset(int $capTo = 2): void
    {
        self::$seen = [];
        self::$capTo = $capTo;
    }

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        self::$seen[] = "before:{$decision->queue}:{$decision->targetWorkers}";

        return new ScalingDecision(
            connection: $decision->connection,
            queue: $decision->queue,
            currentWorkers: $decision->currentWorkers,
            targetWorkers: min($decision->targetWorkers, self::$capTo),
            reason: 'policy:capped',
            spawnCompensation: $decision->spawnCompensation,
        );
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        self::$seen[] = "after:{$decision->queue}:{$decision->targetWorkers}";
    }
}
