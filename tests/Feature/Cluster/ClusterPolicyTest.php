<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\RecordingScalingPolicy;

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
function reconcileInCluster(string $queue, int $target): void
{
    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'reconcileQueueTarget');

    $method->invoke($manager, QueueConfiguration::fromConfig('redis', $queue), $target);
}

beforeEach(function (): void {
    RecordingScalingPolicy::reset();

    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.policies', [RecordingScalingPolicy::class]);

    // PolicyExecutor is a singleton that reads its list once in its
    // constructor, so it has to be rebuilt after the config is set.
    app()->forgetInstance(PolicyExecutor::class);
    app()->forgetInstance(AutoscaleManager::class);
});

test('a cluster recommendation passes through the policy chain', function (): void {
    reconcileInCluster('exports', 5);

    expect(RecordingScalingPolicy::$seen)->not->toBeEmpty()
        ->and(RecordingScalingPolicy::$seen[0])->toBe('before:exports:5');
});

test('a policy can lower a cluster recommendation', function (): void {
    // The reported symptom: a policy that caps workers was ignored, so more
    // workers were spawned than it allowed.
    RecordingScalingPolicy::reset(capTo: 2);

    reconcileInCluster('exports', 9);

    expect(RecordingScalingPolicy::$seen)->toContain('after:exports:2');
});

test('afterScaling runs on the cluster path too', function (): void {
    reconcileInCluster('exports', 4);

    expect(array_filter(RecordingScalingPolicy::$seen, fn (string $e): bool => str_starts_with($e, 'after:')))
        ->not->toBeEmpty();
});

test('a policy sees a hold, and can raise it', function (): void {
    // On a single host the chain runs every cycle including holds, so a policy
    // can raise a target the strategy left alone. Returning early when the
    // target already matched would take that away in cluster mode — the same
    // asymmetry as the original defect, one level down.
    RecordingScalingPolicy::reset(capTo: 99);

    // Nothing is running and the leader recommends nothing: a hold at zero.
    reconcileInCluster('exports', 0);

    expect(RecordingScalingPolicy::$seen)->toContain('before:exports:0')
        ->and(RecordingScalingPolicy::$seen)->toContain('after:exports:0');
});

test('the group cluster path runs policies too', function (): void {
    // reconcileGroupTarget was changed by this fix and no test invoked it.
    config()->set('queue-autoscale.groups.notifications', [
        'queues' => ['email', 'sms'],
        'connection' => 'redis',
    ]);

    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'reconcileGroupTarget');
    $group = GroupConfiguration::allFromConfig()['notifications'];

    $method->invoke($manager, $group, 6);

    expect(RecordingScalingPolicy::$seen)->toContain('before:notifications:6')
        ->and(RecordingScalingPolicy::$seen)->toContain('after:notifications:2');
});
