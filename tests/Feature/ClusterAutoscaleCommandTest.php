<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Mockery\MockInterface;

it('reports when cluster mode is disabled', function () {
    config()->set('queue-autoscale.cluster.enabled', false);

    $this->artisan('queue:autoscale:cluster')
        ->expectsOutput('Cluster mode is disabled. Set QUEUE_AUTOSCALE_CLUSTER_ENABLED=true and restart queue:autoscale.')
        ->assertSuccessful();
});

it('reports when cluster mode is enabled but no summary is published yet', function () {
    config()->set('queue-autoscale.cluster.enabled', true);

    $this->mock(ClusterStore::class, function (MockInterface $mock): void {
        $mock->shouldReceive('summary')->once()->andReturn([]);
    });

    $this->artisan('queue:autoscale:cluster')
        ->expectsOutput('No cluster summary available yet. Start at least one autoscale manager with cluster mode enabled.')
        ->assertSuccessful();
});
