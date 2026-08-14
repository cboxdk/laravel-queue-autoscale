<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\RecordingScalingPolicy;

/**
 * superviseQueue was the last path that skipped the policy chain. A pinned
 * queue has min == max so a policy can rarely move it, but one that reports,
 * alerts or refuses on a queue's behalf should not fall silent just because
 * the queue happens to be pinned.
 */
beforeEach(function (): void {
    RecordingScalingPolicy::reset(capTo: 99);
    config()->set('queue-autoscale.policies', [RecordingScalingPolicy::class]);
    config()->set('queue-autoscale.queues.ledger', [
        'workers' => ['min' => 1, 'max' => 1, 'scalable' => false],
    ]);

    app()->forgetInstance(PolicyExecutor::class);
    app()->forgetInstance(AutoscaleManager::class);
});

test('a pinned queue passes through the policy chain', function (): void {
    $manager = app(AutoscaleManager::class);
    $method = new ReflectionMethod($manager, 'superviseQueue');

    $method->invoke(
        $manager,
        QueueConfiguration::fromConfig('redis', 'ledger'),
        QueueMetricsFactory::idle('ledger'),
        null,
    );

    expect(RecordingScalingPolicy::$seen)->toContain('before:ledger:1')
        ->and(RecordingScalingPolicy::$seen)->toContain('after:ledger:1');
});
