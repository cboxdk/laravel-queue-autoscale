<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Testing\FakeClusterStore;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\RecordingClusterScopedPolicy;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\RecordingScalingPolicy;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Illuminate\Support\Facades\Event;

/**
 * A minimal heartbeat with enough capacity that the fair-share allocator
 * passes demands through untouched, keeping the policy's answer visible.
 */
function scopedPolicyManagerState(string $id, int $maxWorkers = 16): ClusterManagerState
{
    return new ClusterManagerState(
        managerId: $id,
        host: "host-{$id}",
        lastSeenAt: (int) (microtime(true) * 1000),
        totalWorkers: 0,
        maxWorkers: $maxWorkers,
        availableWorkerCapacity: $maxWorkers,
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
        queueWorkers: [],
        groupWorkers: [],
    );
}

/**
 * The per-queue array shape QueueMetrics::getAllQueuesWithMetrics() yields.
 */
function scopedPolicyRawMetrics(string $connection, string $queue, int $pending = 50): array
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

/**
 * Point queue discovery and the metrics recalculation at fakes: the query
 * services are resolved from the container by the QueueMetrics facade, and
 * CalculateQueueMetricsAction is final, so it gets real but empty
 * repositories instead of a mock.
 */
function scopedPolicyDiscovery(array $queues): void
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

function runLeaderEvaluation(): FakeClusterStore
{
    $store = (new FakeClusterStore)
        ->withManager(scopedPolicyManagerState('mgr-1'))
        ->withLeader('mgr-1');

    app()->instance(ClusterStoreContract::class, $store);
    app()->forgetInstance(AutoscaleManager::class);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations'))->invoke($manager);

    return $store;
}

beforeEach(function (): void {
    RecordingScalingPolicy::reset();
    RecordingClusterScopedPolicy::reset();

    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    // Configured queues resolve against the default queue connection; align
    // it with the discovered metrics so 'exports' is one workload, not two.
    config()->set('queue.default', 'redis');

    // A min of 6 makes the cluster-wide demand deterministic (evaluateDemand
    // never goes below workers.min), so the cap of 3 is provably binding.
    config()->set('queue-autoscale.queues', [
        'exports' => ['workers' => ['min' => 6, 'max' => 20]],
    ]);

    // The strategy consults spawn latency through Redis; stub it out so the
    // leader evaluation runs without a Redis server.
    $tracker = Mockery::mock(SpawnLatencyTrackerContract::class);
    $tracker->shouldReceive('currentLatency')->andReturn(0.0);
    $tracker->shouldReceive('recordSpawn');
    $tracker->shouldReceive('recordFirstPickup');
    app()->instance(SpawnLatencyTrackerContract::class, $tracker);

    $pickupStore = Mockery::mock(PickupTimeStoreContract::class);
    $pickupStore->shouldReceive('recentSamples')->andReturn([]);
    $pickupStore->shouldReceive('record');
    app()->instance(PickupTimeStoreContract::class, $pickupStore);

    Event::fake();
});

function rebuildPolicyChain(array $policyClasses): void
{
    config()->set('queue-autoscale.policies', $policyClasses);

    // PolicyExecutor reads its list once in its constructor.
    app()->forgetInstance(PolicyExecutor::class);
    app()->forgetInstance(AutoscaleManager::class);
}

test('a cluster-scope policy caps the cluster-wide target once, before distribution', function (): void {
    rebuildPolicyChain([RecordingClusterScopedPolicy::class]);
    scopedPolicyDiscovery(['redis:exports' => scopedPolicyRawMetrics('redis', 'exports')]);

    $store = runLeaderEvaluation();

    $recommendation = $store->publishedRecommendations()['mgr-1'] ?? null;

    expect($recommendation)->not->toBeNull()
        ->and($recommendation->workloads['queue:redis:exports'] ?? null)->toBe(3)
        ->and(RecordingClusterScopedPolicy::$seen)->toContain('before:cluster:exports:6')
        ->and(RecordingClusterScopedPolicy::$seen)->toContain('after:cluster:exports:3');
});

test('a cluster-scope policy caps a group workload the same way', function (): void {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups.notifications', [
        'queues' => ['email', 'sms'],
        'connection' => 'redis',
        'overrides' => ['workers' => ['min' => 6, 'max' => 20]],
    ]);

    rebuildPolicyChain([RecordingClusterScopedPolicy::class]);
    scopedPolicyDiscovery([
        'redis:email' => scopedPolicyRawMetrics('redis', 'email'),
        'redis:sms' => scopedPolicyRawMetrics('redis', 'sms'),
    ]);

    $store = runLeaderEvaluation();

    $recommendation = $store->publishedRecommendations()['mgr-1'] ?? null;

    // The group's raw demand depends on strategy arithmetic over the
    // aggregated metrics; what is deterministic is that it is at least the
    // configured min of 6, well above the cap, so the cap is binding.
    $consultations = array_filter(
        RecordingClusterScopedPolicy::$seen,
        fn (string $entry): bool => str_starts_with($entry, 'before:cluster:notifications:'),
    );

    expect($recommendation)->not->toBeNull()
        ->and($recommendation->workloads['group:redis:notifications'] ?? null)->toBe(3)
        ->and($consultations)->not->toBeEmpty()
        ->and(RecordingClusterScopedPolicy::$seen)->toContain('after:cluster:notifications:3');
});

test('policies without the marker are never consulted by the leader', function (): void {
    rebuildPolicyChain([RecordingScalingPolicy::class]);
    scopedPolicyDiscovery(['redis:exports' => scopedPolicyRawMetrics('redis', 'exports')]);

    $store = runLeaderEvaluation();

    $recommendation = $store->publishedRecommendations()['mgr-1'] ?? null;

    // The plain policy would have capped to 2; the demand of 6 survives.
    expect($recommendation)->not->toBeNull()
        ->and($recommendation->workloads['queue:redis:exports'] ?? null)->toBe(6)
        ->and(RecordingScalingPolicy::$seen)->toBe([]);
});

test('the per-host apply path still runs every policy, with Host scope', function (): void {
    rebuildPolicyChain([RecordingScalingPolicy::class, RecordingClusterScopedPolicy::class]);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'reconcileQueueTarget'))
        ->invoke($manager, QueueConfiguration::fromConfig('redis', 'exports'), 5);

    // The plain policy fires exactly as before this feature existed.
    expect(RecordingScalingPolicy::$seen)->toContain('before:exports:5');

    // The cluster-scope policy sees the Host scope and leaves it alone: the
    // plain policy's cap of 2 is what survives the chain.
    expect(RecordingClusterScopedPolicy::$seen)->toContain('before:host:exports:2')
        ->and(RecordingClusterScopedPolicy::$seen)->toContain('after:host:exports:2');
});

test('with no cluster-scope policies the leader consults nothing and demand is unchanged', function (): void {
    rebuildPolicyChain([]);
    scopedPolicyDiscovery(['redis:exports' => scopedPolicyRawMetrics('redis', 'exports')]);

    $store = runLeaderEvaluation();

    $recommendation = $store->publishedRecommendations()['mgr-1'] ?? null;

    expect($recommendation)->not->toBeNull()
        ->and($recommendation->workloads['queue:redis:exports'] ?? null)->toBe(6);
});
