<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Testing\FakeClusterStore;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Illuminate\Support\Facades\Event;

function distributionOrderManagerState(string $id): ClusterManagerState
{
    return new ClusterManagerState(
        managerId: $id,
        host: "host-{$id}",
        lastSeenAt: (int) (microtime(true) * 1000),
        totalWorkers: 0,
        maxWorkers: 8,
        availableWorkerCapacity: 8,
        capacityLimiter: 'cpu',
        cpuPercent: 10.0,
        cpuCores: 4.0,
        cpuUsableCores: 3.6,
        cpuReservedCores: 0.4,
        memoryPercent: 30.0,
        memoryTotalMb: 2048.0,
        memoryUsedMb: 614.4,
        memoryFreeMb: 1433.6,
        queueCount: 2,
        groupCount: 0,
        packageVersion: '4.0.1',
        queueWorkers: [],
        groupWorkers: [],
    );
}

function distributionOrderRawMetrics(string $queue): array
{
    return [
        'connection' => 'redis',
        'queue' => $queue,
        'driver' => 'redis',
        'depth' => [
            'total' => 5,
            'pending' => 5,
            'scheduled' => 0,
            'reserved' => 0,
            'oldest_job_age_seconds' => 0,
            'oldest_job_age_status' => 'normal',
        ],
        'performance_60s' => [
            'throughput_per_minute' => 0.0,
            'avg_duration_ms' => 0.0,
            'window_seconds' => 60,
        ],
        'lifetime' => [
            'failure_rate_percent' => 0.0,
        ],
        'workers' => [
            'active_count' => 0,
            'current_busy_percent' => 0.0,
            'lifetime_busy_percent' => 0,
        ],
        'baseline' => null,
        'trends' => [],
        'timestamp' => now()->toIso8601String(),
    ];
}

/**
 * Run one leader evaluation on a fresh manager against the given discovery
 * order and return the published per-manager workload assignments.
 *
 * @return array<string, array<string, int>>
 */
function runLeaderWithDiscoveryOrder(array $queueNames): array
{
    $queues = [];
    foreach ($queueNames as $name) {
        $queues["redis:{$name}"] = distributionOrderRawMetrics($name);
    }

    $queueService = Mockery::mock();
    $queueService->shouldReceive('getAllQueuesWithMetrics')->andReturn($queues);
    $queueService->shouldReceive('getQueueDepth')->andReturnUsing(
        fn (string $connection, string $queue): QueueDepthData => new QueueDepthData(
            connection: $connection,
            queue: $queue,
            pendingJobs: 0,
            reservedJobs: 0,
            delayedJobs: 0,
            oldestPendingJobAge: null,
            oldestDelayedJobAge: null,
            measuredAt: now(),
        )
    );
    $queueService->shouldReceive('getQueueMetrics')->andReturnUsing(
        fn (string $connection, string $queue) => createMetrics(['connection' => $connection, 'queue' => $queue])
    );

    $jobService = Mockery::mock();
    $jobService->shouldReceive('getAllJobsWithMetrics')->andReturn([]);

    app()->instance(QueueMetricsQueryService::class, $queueService);
    app()->instance(JobMetricsQueryService::class, $jobService);

    $queueRepository = Mockery::mock(QueueMetricsRepository::class);
    $queueRepository->shouldReceive('listQueues')->andReturn([]);

    app()->instance(CalculateQueueMetricsAction::class, new CalculateQueueMetricsAction(
        Mockery::mock(JobMetricsRepository::class),
        $queueRepository,
    ));

    $store = (new FakeClusterStore)
        ->withManager(distributionOrderManagerState('mgr-a'))
        ->withManager(distributionOrderManagerState('mgr-b'))
        ->withLeader('mgr-a');
    app()->instance(ClusterStoreContract::class, $store);
    app()->forgetInstance(AutoscaleManager::class);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations'))->invoke($manager);

    return array_map(
        static fn ($recommendation): array => $recommendation->workloads,
        $store->publishedRecommendations(),
    );
}

beforeEach(function (): void {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.policies', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);
    config()->set('queue.default', 'redis');
    config()->set('queue-autoscale.queues', [
        'alpha' => ['workers' => ['min' => 1, 'max' => 1]],
        'beta' => ['workers' => ['min' => 1, 'max' => 1]],
    ]);

    $tracker = Mockery::mock(SpawnLatencyTrackerContract::class);
    $tracker->shouldReceive('currentLatency')->andReturn(0.0);
    app()->instance(SpawnLatencyTrackerContract::class, $tracker);

    $pickupStore = Mockery::mock(PickupTimeStoreContract::class);
    $pickupStore->shouldReceive('recentSamples')->andReturn([]);
    app()->instance(PickupTimeStoreContract::class, $pickupStore);

    Event::fake();
});

test('the published placement does not depend on metrics-discovery order', function (): void {
    $alphaFirst = runLeaderWithDiscoveryOrder(['alpha', 'beta']);
    $betaFirst = runLeaderWithDiscoveryOrder(['beta', 'alpha']);

    expect($betaFirst)->toBe($alphaFirst)
        ->and($alphaFirst['mgr-a']['queue:redis:alpha'] + $alphaFirst['mgr-b']['queue:redis:alpha'])->toBe(1)
        ->and($alphaFirst['mgr-a']['queue:redis:beta'] + $alphaFirst['mgr-b']['queue:redis:beta'])->toBe(1);
});
