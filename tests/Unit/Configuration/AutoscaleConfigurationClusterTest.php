<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

it('reads cluster configuration values', function () {
    config()->set('app.name', 'Orderscale');
    config()->set('app.env', 'production');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
    ]);
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.cluster.heartbeat_ttl_seconds', 22);
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 11);
    config()->set('queue-autoscale.cluster.recommendation_ttl_seconds', 44);
    config()->set('queue-autoscale.cluster.summary_ttl_seconds', 55);

    expect(AutoscaleConfiguration::clusterEnabled())->toBeTrue()
        ->and(AutoscaleConfiguration::clusterAppId())->toStartWith('orderscale-production-')
        ->and(AutoscaleConfiguration::clusterHeartbeatTtlSeconds())->toBe(22)
        ->and(AutoscaleConfiguration::clusterLeaderLeaseSeconds())->toBe(11)
        ->and(AutoscaleConfiguration::clusterRecommendationTtlSeconds())->toBe(44)
        ->and(AutoscaleConfiguration::clusterSummaryTtlSeconds())->toBe(55);
});

it('uses an explicit manager id override when configured', function () {
    config()->set('queue-autoscale.manager_id', 'autoscale-node-1');

    expect(AutoscaleConfiguration::managerId())->toBe('autoscale-node-1');
});

it('builds a stable application scope id without queue config entropy', function () {
    config()->set('app.name', 'Orderscale');
    config()->set('app.env', 'production');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
    ]);

    $initial = AutoscaleConfiguration::applicationScopeId();

    config()->set('queue.connections.redis.queue', 'high');
    config()->set('queue.connections.redis.retry_after', 120);

    expect(AutoscaleConfiguration::applicationScopeId())->toBe($initial);
});
