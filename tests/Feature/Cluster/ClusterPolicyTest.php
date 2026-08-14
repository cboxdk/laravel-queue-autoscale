<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Policies were executed only on the single-host evaluation paths.
 *
 * In cluster mode the leader's recommendation is applied by
 * reconcileQueueTarget()/reconcileGroupTarget(), which built a decision and
 * acted on it without ever consulting the policy chain. A policy written to
 * cap workers was therefore inert the moment cluster mode was enabled — the
 * autoscaler scaled past the limit the policy existed to impose, with no error
 * and no log line saying a policy had been skipped.
 *
 * Reported against v4.0.0 by a consumer whose own cap was ignored.
 */
class RecordingPolicy implements ScalingPolicy
{
    /** @var list<string> */
    public static array $seen = [];

    public static int $capTo = 2;

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

function reconcileInCluster(string $queue, int $target): void
{
    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'reconcileQueueTarget');

    $method->invoke($manager, QueueConfiguration::fromConfig('redis', $queue), $target);
}

beforeEach(function (): void {
    RecordingPolicy::$seen = [];
    RecordingPolicy::$capTo = 2;

    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.policies', [RecordingPolicy::class]);

    // PolicyExecutor is a singleton that reads its list once in its
    // constructor, so it has to be rebuilt after the config is set.
    app()->forgetInstance(PolicyExecutor::class);
    app()->forgetInstance(AutoscaleManager::class);
});

test('a cluster recommendation passes through the policy chain', function (): void {
    reconcileInCluster('exports', 5);

    expect(RecordingPolicy::$seen)->not->toBeEmpty()
        ->and(RecordingPolicy::$seen[0])->toBe('before:exports:5');
});

test('a policy can lower a cluster recommendation', function (): void {
    // The reported symptom: a policy that caps workers was ignored, so more
    // workers were spawned than it allowed.
    RecordingPolicy::$capTo = 2;

    reconcileInCluster('exports', 9);

    expect(RecordingPolicy::$seen)->toContain('after:exports:2');
});

test('afterScaling runs on the cluster path too', function (): void {
    reconcileInCluster('exports', 4);

    expect(array_filter(RecordingPolicy::$seen, fn (string $e): bool => str_starts_with($e, 'after:')))
        ->not->toBeEmpty();
});
