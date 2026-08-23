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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

function applyCooldown(AutoscaleManager $manager, string $key, int $current, int $target, bool $isBreaching = false): int
{
    return (new ReflectionMethod($manager, 'applyClusterCooldown'))
        ->invoke($manager, $key, $current, $target, $isBreaching);
}

function cooldownManagerState(string $id, int $totalWorkers = 0, array $queueWorkers = []): ClusterManagerState
{
    return new ClusterManagerState(
        managerId: $id,
        host: "host-{$id}",
        lastSeenAt: (int) (microtime(true) * 1000),
        totalWorkers: $totalWorkers,
        maxWorkers: 32,
        availableWorkerCapacity: 32 - $totalWorkers,
        capacityLimiter: 'cpu',
        cpuPercent: 10.0,
        cpuCores: 4.0,
        cpuUsableCores: 3.6,
        cpuReservedCores: 0.4,
        memoryPercent: 30.0,
        memoryTotalMb: 2048.0,
        memoryUsedMb: 614.4,
        memoryFreeMb: 1433.6,
        queueCount: 1,
        groupCount: 0,
        packageVersion: '4.0.1',
        queueWorkers: $queueWorkers,
        groupWorkers: [],
    );
}

function cooldownRawMetrics(string $connection, string $queue, int $pending): array
{
    return [
        'connection' => $connection,
        'queue' => $queue,
        'driver' => 'redis',
        'depth' => [
            'total' => $pending,
            'pending' => $pending,
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

function cooldownDiscovery(array $queues): void
{
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
}

beforeEach(function (): void {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.policies', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);
    config()->set('queue-autoscale.scaling.cooldown_seconds', 60);

    Carbon::setTestNow(Carbon::parse('2026-08-22 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a direction reversal inside the cooldown holds the previously published target', function (): void {
    $manager = app(AutoscaleManager::class);

    expect(applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22))->toBe(22);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 8))->toBe(22);
});

test('the reversal is allowed once the cooldown has elapsed', function (): void {
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22);

    Carbon::setTestNow(now()->addSeconds(61));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 8))->toBe(8);
});

test('same-direction changes are never damped', function (): void {
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 30))->toBe(30);
});

test('a hold keeps the last direction from being overwritten', function (): void {
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 22))->toBe(22);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 10))->toBe(22);
});

test('an SLA breach always allows scale-up through the cooldown', function (): void {
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 22, target: 8);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22, isBreaching: true))->toBe(22);
});

test('workloads are damped independently of each other', function (): void {
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:imports', current: 22, target: 8))->toBe(8);
});

test('regaining leadership discards the damping memory', function (): void {
    $manager = app(AutoscaleManager::class);
    $noteLeadership = new ReflectionMethod($manager, 'noteLeadership');

    $noteLeadership->invoke($manager, true);
    applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22);

    $noteLeadership->invoke($manager, false);
    $noteLeadership->invoke($manager, true);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 8))->toBe(8);
});

test('the leader publishes the held target while an oscillating demand reverses inside the cooldown', function (): void {
    config()->set('queue.default', 'redis');
    config()->set('queue-autoscale.queues', [
        'exports' => ['workers' => ['min' => 1, 'max' => 20]],
    ]);

    $tracker = Mockery::mock(SpawnLatencyTrackerContract::class);
    $tracker->shouldReceive('currentLatency')->andReturn(0.0);
    app()->instance(SpawnLatencyTrackerContract::class, $tracker);

    $pickupStore = Mockery::mock(PickupTimeStoreContract::class);
    $pickupStore->shouldReceive('recentSamples')->andReturn([]);
    app()->instance(PickupTimeStoreContract::class, $pickupStore);

    Event::fake();

    $store = (new FakeClusterStore)
        ->withManager(cooldownManagerState('mgr-1'))
        ->withLeader('mgr-1');
    app()->instance(ClusterStoreContract::class, $store);
    app()->forgetInstance(AutoscaleManager::class);

    $manager = app(AutoscaleManager::class);
    $evaluate = new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations');

    // Cycle 1: a deep backlog scales the workload up from zero workers.
    cooldownDiscovery(['redis:exports' => cooldownRawMetrics('redis', 'exports', pending: 50)]);
    $evaluate->invoke($manager);

    $published = $store->publishedRecommendations()['mgr-1']->workloads['queue:redis:exports'] ?? null;
    expect($published)->toBeGreaterThan(1);

    // Cycle 2, five seconds later: the backlog momentarily drains, so raw
    // demand collapses toward workers.min, a reversal inside the cooldown.
    Carbon::setTestNow(now()->addSeconds(5));
    cooldownDiscovery(['redis:exports' => cooldownRawMetrics('redis', 'exports', pending: 0)]);
    $store->withManager(cooldownManagerState('mgr-1', totalWorkers: $published, queueWorkers: ['redis:exports' => $published]));
    $evaluate->invoke($manager);

    expect($store->publishedRecommendations()['mgr-1']->workloads['queue:redis:exports'] ?? null)->toBe($published);
});
