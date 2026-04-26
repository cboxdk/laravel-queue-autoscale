<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\LaravelQueueAutoscale;

it('exposes cluster metrics from the published cluster summary', function () {
    config()->set('queue-autoscale.cluster.enabled', true);

    $store = Mockery::mock(ClusterStore::class);
    $store->shouldReceive('summary')->once()->andReturn([
        'cluster_id' => 'orderscale-prod',
        'manager_count' => 2,
        'total_workers' => 4,
        'required_workers' => 6,
        'total_worker_capacity' => 12,
        'scale_signal' => ['recommended_hosts' => 2],
        'managers' => [
            [
                'manager_id' => 'manager-a',
                'host' => 'orderscale-0',
                'is_leader' => true,
                'total_workers' => 3,
                'max_workers' => 6,
                'cpu_percent' => 41.5,
                'memory_percent' => 55.0,
            ],
        ],
        'workloads' => [
            [
                'type' => 'queue',
                'connection' => 'redis',
                'name' => 'default',
                'current_workers' => 4,
                'target_workers' => 6,
                'pending' => 120,
                'oldest_job_age' => 14,
            ],
        ],
    ]);

    $service = new LaravelQueueAutoscale($store);
    $metrics = $service->clusterMetrics();

    expect($metrics)->toBeArray()
        ->and(collect($metrics)->contains(fn (array $metric): bool => $metric['name'] === 'queue_autoscale_cluster_workers_required' && $metric['value'] === 6))->toBeTrue()
        ->and(collect($metrics)->contains(fn (array $metric): bool => $metric['name'] === 'queue_autoscale_manager_cpu_percent' && $metric['value'] === 41.5))->toBeTrue()
        ->and(collect($metrics)->contains(fn (array $metric): bool => $metric['name'] === 'queue_autoscale_workload_pending_jobs' && $metric['value'] === 120))->toBeTrue();
});
