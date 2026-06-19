<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

it('applies cluster recommendation for discovered queues not in explicit config', function () {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    expect(AutoscaleConfiguration::configuredQueues())->toBeEmpty();

    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'queue:redis:default' => 2,
        ],
    );

    $manager = app(AutoscaleManager::class);

    $method = new ReflectionMethod($manager, 'applyClusterRecommendation');
    $method->invoke($manager, $recommendation);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->connection === 'redis'
            && $event->queue === 'default'
            && $event->from === 0
            && $event->to === 2
            && $event->action === 'up';
    });
});

it('skips group workloads in cluster recommendation queue loop', function () {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'group:redis:emails' => 3,
        ],
    );

    $manager = app(AutoscaleManager::class);

    $method = new ReflectionMethod($manager, 'applyClusterRecommendation');
    $method->invoke($manager, $recommendation);

    Event::assertNotDispatched(WorkersScaled::class);
});

it('skips excluded queues in cluster recommendation', function () {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', ['ignored']);

    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'queue:redis:ignored' => 2,
        ],
    );

    $manager = app(AutoscaleManager::class);

    $method = new ReflectionMethod($manager, 'applyClusterRecommendation');
    $method->invoke($manager, $recommendation);

    Event::assertNotDispatched(WorkersScaled::class);
});

it('ignores stale recommendations from a previous leader', function () {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    Event::fake([WorkersScaled::class]);

    $this->mock(ClusterStore::class, function (MockInterface $mock): void {
        $mock->shouldReceive('leaderId')
            ->once()
            ->andReturn('current-leader');
    });

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'queue:redis:default' => 2,
        ],
        leaderId: 'previous-leader',
    );

    $manager = app(AutoscaleManager::class);

    $method = new ReflectionMethod($manager, 'applyClusterRecommendation');
    $method->invoke($manager, $recommendation);

    Event::assertNotDispatched(WorkersScaled::class);
});

it('ignores stale recommendations from a previous lease held by the same leader id', function () {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    Event::fake([WorkersScaled::class]);

    $this->mock(ClusterStore::class, function (MockInterface $mock): void {
        $mock->shouldReceive('leaderId')
            ->once()
            ->andReturn('leader-a');
        $mock->shouldReceive('leaderToken')
            ->once()
            ->andReturn('current-lease-token');
    });

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'queue:redis:default' => 2,
        ],
        leaderId: 'leader-a',
        leaderToken: 'previous-lease-token',
    );

    $manager = app(AutoscaleManager::class);

    $method = new ReflectionMethod($manager, 'applyClusterRecommendation');
    $method->invoke($manager, $recommendation);

    Event::assertNotDispatched(WorkersScaled::class);
});
