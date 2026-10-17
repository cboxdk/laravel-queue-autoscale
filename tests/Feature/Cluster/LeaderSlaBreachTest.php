<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\CooldownDecision;
use Cbox\LaravelQueueAutoscale\Cluster\EvaluatedWorkload;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignal;
use Cbox\LaravelQueueAutoscale\Scaling\SlaSignalSource;
use Cbox\LaravelQueueAutoscale\Testing\FakeClusterStore;
use Cbox\LaravelQueueAutoscale\Testing\InteractsWithAutoscaling;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * The cluster leader judges breach on the same signal as a single host.
 *
 * The leader builds each workload from metrics it discovers itself — an
 * EvaluatedWorkload is never read back off the wire — so the signal reaches it
 * through the engine's demand assessment. What does cross the wire is the
 * published summary, and a host on this version may read one written by a
 * leader that predates signals; those specs are at the bottom.
 */
uses(InteractsWithAutoscaling::class);

function leaderBreachManagerState(string $id): ClusterManagerState
{
    return new ClusterManagerState(
        managerId: $id,
        host: "host-{$id}",
        lastSeenAt: (int) (microtime(true) * 1000),
        totalWorkers: 0,
        maxWorkers: 16,
        availableWorkerCapacity: 16,
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
        packageVersion: '4.5.0',
        queueWorkers: [],
        groupWorkers: [],
    );
}

/**
 * Raw discovery metrics for an SQS queue: a depth, and an oldest-job age the
 * driver cannot report.
 *
 * @return array<string, mixed>
 */
function leaderSqsRawMetrics(string $queue, int $pending, int $oldestJobAge = 0): array
{
    return [
        'connection' => 'sqs',
        'queue' => $queue,
        'driver' => 'sqs',
        'depth' => [
            'total' => $pending,
            'pending' => $pending,
            'scheduled' => 0,
            'reserved' => 0,
            'oldest_job_age_seconds' => $oldestJobAge,
            'oldest_job_age_status' => 'normal',
        ],
        'performance_60s' => ['throughput_per_minute' => 0.0, 'avg_duration_ms' => 0.0, 'window_seconds' => 60],
        'lifetime' => ['failure_rate_percent' => 0.0],
        'workers' => ['active_count' => 0, 'current_busy_percent' => 0.0, 'lifetime_busy_percent' => 0],
        'baseline' => null,
        'trends' => [],
        'timestamp' => now()->toIso8601String(),
    ];
}

/**
 * @param  array<string, array<string, mixed>>  $queues
 */
function leaderBreachDiscovery(array $queues): void
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
        fn (string $connection, string $queue) => QueueMetricsFactory::make(['connection' => $connection, 'queue' => $queue])
    );

    $jobService = Mockery::mock();
    $jobService->shouldReceive('getAllJobsWithMetrics')->andReturn([]);

    app()->instance(QueueMetricsQueryService::class, $queueService);
    app()->instance(JobMetricsQueryService::class, $jobService);

    $queueRepository = Mockery::mock(QueueMetricsRepository::class);
    $queueRepository->shouldReceive('listQueues')->andReturn([]);

    $jobRepository = Mockery::mock(JobMetricsRepository::class);
    $jobRepository->shouldReceive('listJobs')->andReturn([]);

    app()->instance(CalculateQueueMetricsAction::class, new CalculateQueueMetricsAction(
        $jobRepository,
        $queueRepository,
    ));
}

/**
 * @return array<string, mixed>
 */
function leaderSummaryRow(FakeClusterStore $store, string $name): array
{
    $workloads = $store->summary()['workloads'] ?? [];

    foreach (is_array($workloads) ? $workloads : [] as $workload) {
        if (is_array($workload) && ($workload['name'] ?? null) === $name) {
            return $workload;
        }
    }

    throw new RuntimeException("No summary row for {$name}");
}

beforeEach(function (): void {
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.policies', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);
    config()->set('queue-autoscale.queues', [
        'orders' => [
            'sla' => ['target_seconds' => 30, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 5],
            'workers' => ['min' => 0, 'max' => 10],
        ],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));

    $tracker = Mockery::mock(SpawnLatencyTrackerContract::class);
    $tracker->shouldReceive('currentLatency')->andReturn(0.0);
    app()->instance(SpawnLatencyTrackerContract::class, $tracker);

    $this->pickups = $this->fakePickupTimes();

    $this->store = (new FakeClusterStore)
        ->withManager(leaderBreachManagerState('mgr-1'))
        ->withLeader('mgr-1');
    app()->instance(ClusterStoreContract::class, $this->store);
    app()->forgetInstance(AutoscaleManager::class);

    Event::fake([SlaBreached::class, SlaRecovered::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function runLeaderCycle(): void
{
    $manager = app(AutoscaleManager::class);

    (new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations'))->invoke($manager);
}

test('the leader opens an SQS breach from the p95 pickup time, once', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    leaderBreachDiscovery(['sqs:orders' => leaderSqsRawMetrics('orders', pending: 200)]);

    runLeaderCycle();
    Carbon::setTestNow(now()->addSeconds(5));
    runLeaderCycle();

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->connection === 'sqs'
        && $event->queue === 'orders'
        && $event->oldestJobAge === 0
        && $event->slaSignal?->source === SlaSignalSource::PickupPercentile
        && $event->slaSignal->seconds === 45.0);

    $row = leaderSummaryRow($this->store, 'orders');

    expect($row['sla_status'])->toBe('breached')
        ->and($row['sla_signal_source'])->toBe('pickup_percentile')
        ->and($row['sla_signal_seconds'])->toBe(45.0)
        ->and($row['oldest_job_age'])->toBe(0);
});

test('the leader recovers the breach once the window p95 is back under the target', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    leaderBreachDiscovery(['sqs:orders' => leaderSqsRawMetrics('orders', pending: 200)]);
    runLeaderCycle();

    Carbon::setTestNow(now()->addSeconds(301));
    $this->pickups->seedRepeated(20, 4.0, 'sqs', 'orders');
    leaderBreachDiscovery(['sqs:orders' => leaderSqsRawMetrics('orders', pending: 20)]);
    runLeaderCycle();

    Event::assertDispatchedTimes(SlaRecovered::class, 1);
    Event::assertDispatched(SlaRecovered::class, fn (SlaRecovered $event): bool => $event->slaSignal?->seconds === 4.0);
    expect(leaderSummaryRow($this->store, 'orders')['sla_status'])->toBe('ok');
});

test('the leader holds an open breach through a pickup window that ran short while jobs wait', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    leaderBreachDiscovery(['sqs:orders' => leaderSqsRawMetrics('orders', pending: 200)]);
    runLeaderCycle();

    $this->pickups->clear('sqs', 'orders');
    runLeaderCycle();

    Event::assertNotDispatched(SlaRecovered::class);

    $row = leaderSummaryRow($this->store, 'orders');

    expect($row['sla_status'])->toBe('breached')
        ->and($row['sla_signal_source'])->toBe('oldest_job_age_fallback');
});

test('the leader still judges a queue without pickup samples on the oldest job age', function (): void {
    leaderBreachDiscovery(['sqs:orders' => leaderSqsRawMetrics('orders', pending: 50, oldestJobAge: 31)]);
    runLeaderCycle();

    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->oldestJobAge === 31
        && $event->slaSignal?->source === SlaSignalSource::OldestJobAgeFallback);
});

test('the leader judges a group on the p95 pooled across its members', function (): void {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', [
        'notifications' => [
            'connection' => 'sqs',
            'queues' => ['mail', 'sms'],
            'overrides' => [
                'sla' => ['target_seconds' => 30, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 5],
                'workers' => ['min' => 0, 'max' => 10],
            ],
        ],
    ]);

    $this->pickups->seedRepeated(3, 40.0, 'sqs', 'mail');
    $this->pickups->seedRepeated(3, 40.0, 'sqs', 'sms');
    leaderBreachDiscovery([
        'sqs:mail' => leaderSqsRawMetrics('mail', pending: 60),
        'sqs:sms' => leaderSqsRawMetrics('sms', pending: 40),
    ]);

    runLeaderCycle();

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->queue === 'notifications'
        && $event->slaSignal?->source === SlaSignalSource::PickupPercentile);
    expect(leaderSummaryRow($this->store, 'notifications')['sla_status'])->toBe('breached');
});

test('the leader measures a pinned workload directly, since no strategy runs for it', function (): void {
    config()->set('queue-autoscale.queues', [
        'ledger' => [
            'sla' => ['target_seconds' => 30, 'percentile' => 95, 'window_seconds' => 300, 'min_samples' => 5],
            'workers' => ['min' => 1, 'max' => 1, 'scalable' => false],
        ],
    ]);

    $this->pickups->seedRepeated(10, 75.0, 'sqs', 'ledger');
    leaderBreachDiscovery(['sqs:ledger' => leaderSqsRawMetrics('ledger', pending: 12)]);

    runLeaderCycle();

    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->queue === 'ledger'
        && $event->slaSignal?->seconds === 75.0);
});

/**
 * A cluster target that reverses a scale-down made five seconds ago. A
 * reversing scale-up is never held; the damper reports it as a breach
 * override when the workload is in breach.
 *
 * @return array{0: AutoscaleManager, 1: Closure(SlaSignal, ?bool): CooldownDecision}
 */
function reversingLeader(): array
{
    $manager = app(AutoscaleManager::class);
    $config = QueueConfiguration::fromConfig('sqs', 'orders');

    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);
    $cooldown->apply('queue:sqs:orders', 6, 2, false, 60);
    Carbon::setTestNow(now()->addSeconds(5));

    $damp = function (SlaSignal $signal, ?bool $breaching) use ($manager, $config): CooldownDecision {
        $workload = new EvaluatedWorkload(
            isGroup: false,
            connection: 'sqs',
            name: 'orders',
            driver: 'sqs',
            config: $config,
            currentWorkers: 2,
            metrics: QueueMetricsFactory::onSqs(200, 'orders'),
            memberQueues: ['orders'],
            slaSignal: $signal,
        );

        $arguments = [['queue:sqs:orders' => 8], ['queue:sqs:orders' => $workload], 16];

        if ($breaching !== null) {
            $arguments[] = ['queue:sqs:orders' => $breaching];
        }

        return (new ReflectionMethod($manager, 'dampClusterTargets'))->invoke($manager, ...$arguments)['queue:sqs:orders'];
    };

    return [$manager, $damp];
}

test('the damper reports a breach override from the signal, not just the oldest age', function (): void {
    [, $damp] = reversingLeader();

    $decision = $damp(SlaSignal::fromPickupPercentile(45.0, 95), null);

    expect($decision->breachOverride)->toBeTrue()
        ->and($decision->targetWorkers)->toBe(8);
});

test('the damper overrides for a breach the leader holds through an inconclusive reading', function (): void {
    [, $damp] = reversingLeader();

    // The window drained, so the signal alone reads 0 — but the leader judged
    // the breach still open, and that judgement is what the damper must use.
    $decision = $damp(SlaSignal::fromOldestJobAgeFallback(0), true);

    expect($decision->breachOverride)->toBeTrue()
        ->and($decision->targetWorkers)->toBe(8);
});

test('the damper follows the leader judgement over the signal when they disagree', function (): void {
    [, $damp] = reversingLeader();

    $decision = $damp(SlaSignal::fromPickupPercentile(45.0, 95), false);

    expect($decision->breachOverride)->toBeFalse()
        ->and($decision->targetWorkers)->toBe(8);
});

test('a leader cycle holding an SQS breach through a drained window keeps overriding the damper', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    leaderBreachDiscovery(['sqs:orders' => leaderSqsRawMetrics('orders', 200)]);
    $manager = app(AutoscaleManager::class);
    $cycle = new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations');
    $cycle->invoke($manager);

    // A scale-down a moment ago, then the window drains with jobs still waiting.
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);
    $cooldown->apply('queue:sqs:orders', 6, 2, false, 60);
    Carbon::setTestNow(now()->addSeconds(5));
    $this->pickups->clear('sqs', 'orders');
    $cycle->invoke($manager);

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertNotDispatched(SlaRecovered::class);
    expect(leaderSummaryRow($this->store, 'orders'))
        ->sla_status->toBe('breached')
        ->sla_signal_source->toBe('oldest_job_age_fallback');
});

test('a summary from a leader that predates SLA signals is read on the oldest job age', function (): void {
    // A mixed-version cluster: this host reads what an older leader wrote,
    // which has no sla_* fields. That leader judged breach on the oldest
    // job's age, so that is what the exported numbers fall back to.
    $this->store->withSummary([
        'cluster_id' => 'test-cluster',
        'workloads' => [
            ['type' => 'queue', 'connection' => 'redis', 'name' => 'late', 'oldest_job_age' => 45, 'sla_target_seconds' => 30],
            ['type' => 'queue', 'connection' => 'redis', 'name' => 'fine', 'oldest_job_age' => 5, 'sla_target_seconds' => 30],
            ['type' => 'queue', 'connection' => 'sqs', 'name' => 'blind', 'oldest_job_age' => 0, 'sla_target_seconds' => 30],
        ],
    ]);

    $metrics = collect(LaravelQueueAutoscale::clusterMetrics());
    $value = fn (string $metric, string $name): int|float => $metrics
        ->first(fn (array $m): bool => $m['name'] === $metric && $m['labels']['name'] === $name)['value'];

    expect($value('queue_autoscale_workload_sla_signal_seconds', 'late'))->toBe(45.0)
        ->and($value('queue_autoscale_workload_sla_breaching', 'late'))->toBe(1)
        ->and($value('queue_autoscale_workload_sla_signal_seconds', 'fine'))->toBe(5.0)
        ->and($value('queue_autoscale_workload_sla_breaching', 'fine'))->toBe(0)
        ->and($value('queue_autoscale_workload_sla_breaching', 'blind'))->toBe(0);
});

test('a summary from a current leader exports the signal and breach state it published', function (): void {
    $this->pickups->seedRepeated(20, 45.0, 'sqs', 'orders');
    leaderBreachDiscovery(['sqs:orders' => leaderSqsRawMetrics('orders', pending: 200)]);
    runLeaderCycle();

    $metrics = collect(LaravelQueueAutoscale::clusterMetrics());
    $value = fn (string $metric): int|float => $metrics
        ->first(fn (array $m): bool => $m['name'] === $metric && $m['labels']['name'] === 'orders')['value'];

    expect($value('queue_autoscale_workload_oldest_job_age_seconds'))->toBe(0)
        ->and($value('queue_autoscale_workload_sla_signal_seconds'))->toBe(45.0)
        ->and($value('queue_autoscale_workload_sla_breaching'))->toBe(1);
});
