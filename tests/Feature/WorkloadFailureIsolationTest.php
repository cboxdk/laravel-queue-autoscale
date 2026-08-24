<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Testing\FakeClusterStore;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\ThrowingProfile;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

/**
 * A spawner that reports success without touching the OS, except for the
 * named poison queues, where it throws the way the real spawner does when a
 * spawn fails. Lets a spec prove that one workload's failure cannot take the
 * rest of the evaluation cycle down with it.
 */
function spawnerThatFailsFor(array $poisonQueues, array $poisonGroups = []): void
{
    app()->instance(WorkerSpawner::class, new readonly class(app(SpawnLatencyTrackerContract::class), $poisonQueues, $poisonGroups) extends WorkerSpawner
    {
        /**
         * @param  array<int, string>  $poisonQueues
         * @param  array<int, string>  $poisonGroups
         */
        public function __construct(
            SpawnLatencyTrackerContract $tracker,
            private array $poisonQueues,
            private array $poisonGroups,
        ) {
            parent::__construct($tracker);
        }

        public function spawn(
            string $connection,
            string $queue,
            int $count,
            SpawnCompensationConfiguration $spawnConfig,
            ?string $group = null,
            ?WorkerConfiguration $workerConfig = null,
        ): Collection {
            $groupIsPoisoned = $group !== null && in_array($group, $this->poisonGroups, true);

            if ($groupIsPoisoned || in_array($queue, $this->poisonQueues, true)) {
                throw new RuntimeException("Simulated spawn failure for '{$connection}:{$queue}'");
            }

            $workers = new Collection;

            for ($i = 0; $i < $count; $i++) {
                $workers->push(new WorkerProcess(
                    new Process([PHP_BINARY, '-r', 'usleep(200000);']),
                    $connection,
                    $queue,
                    now(),
                    $group,
                ));
            }

            return $workers;
        }
    });

    app()->forgetInstance(AutoscaleManager::class);
}

/**
/**
/**
/**
 * A minimal manager heartbeat so the leader has somewhere to place workers.
 */
function isolationManagerState(string $id, int $maxWorkers = 8): ClusterManagerState
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
        cpuCores: 2.0,
        cpuUsableCores: 1.8,
        cpuReservedCores: 0.2,
        memoryPercent: 30.0,
        memoryTotalMb: 1024.0,
        memoryUsedMb: 307.2,
        memoryFreeMb: 716.8,
        queueCount: 1,
        groupCount: 0,
        packageVersion: '4.0.1',
        queueWorkers: [],
        groupWorkers: [],
    );
}

beforeEach(function (): void {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);
});

it('skips an empty-named queue in a cluster recommendation and still reconciles the rest', function (): void {
    spawnerThatFailsFor([]);
    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'queue:sqs:' => 1,
            'queue:redis:default' => 2,
        ],
    );

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, $recommendation);

    Event::assertNotDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->queue === '';
    });
    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->queue === 'default' && $event->to === 2 && $event->action === 'up';
    });
});

it('continues applying a cluster recommendation when one workload fails to reconcile', function (): void {
    spawnerThatFailsFor(['poison']);
    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'queue:redis:poison' => 1,
            'queue:redis:default' => 2,
        ],
    );

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, $recommendation);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->queue === 'default' && $event->to === 2 && $event->action === 'up';
    });
});

it('continues evaluating remaining queues when one queue fails to reconcile on a single host', function (): void {
    spawnerThatFailsFor(['poison']);
    Event::fake([WorkersScaled::class]);

    stubMetricsRecalculation();

    fakeDiscoveredQueues([
        'redis:poison' => rawDiscoveredMetrics('redis', 'poison'),
        'redis:default' => rawDiscoveredMetrics('redis', 'default'),
    ]);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'evaluateAndScale'))->invoke($manager);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->queue === 'default' && $event->action === 'up';
    });
});

it('does not publish cluster recommendations for unsafe workload names', function (): void {
    Event::fake();

    $store = (new FakeClusterStore)
        ->withManager(isolationManagerState('mgr-1'))
        ->withLeader('mgr-1');

    app()->instance(ClusterStoreContract::class, $store);
    app()->forgetInstance(AutoscaleManager::class);

    stubMetricsRecalculation();

    fakeDiscoveredQueues([
        'sqs:' => rawDiscoveredMetrics('sqs', ''),
        'redis:default' => rawDiscoveredMetrics('redis', 'default'),
    ]);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations'))->invoke($manager);

    $recommendation = $store->publishedRecommendations()['mgr-1'] ?? null;

    expect($recommendation)->not->toBeNull()
        ->and($recommendation->workloads)->toHaveKey('queue:redis:default')
        ->and($recommendation->workloads)->not->toHaveKey('queue:sqs:');
});

it('supervises a pinned queue from a cluster recommendation inside the isolation guard', function (): void {
    config()->set('queue-autoscale.queues', ['exclusive-queue' => ExclusiveProfile::class]);

    spawnerThatFailsFor([]);
    Event::fake([WorkersScaled::class]);
    fakeDiscoveredQueues([]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'queue:redis:exclusive-queue' => 1,
        ],
    );

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, $recommendation);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->queue === 'exclusive-queue' && $event->to === 1 && $event->action === 'up';
    });
});

it('continues applying a cluster recommendation when one group fails to reconcile', function (): void {
    config()->set('queue-autoscale.groups.poison-group', ['queues' => ['email', 'sms'], 'connection' => 'redis']);
    config()->set('queue-autoscale.groups.healthy-group', ['queues' => ['push'], 'connection' => 'redis']);

    spawnerThatFailsFor([], ['poison-group']);
    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: [
            'group:redis:poison-group' => 1,
            'group:redis:healthy-group' => 2,
        ],
    );

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, $recommendation);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->queue === 'healthy-group' && $event->to === 2 && $event->action === 'up';
    });
});

it('continues evaluating remaining groups when one group fails to reconcile on a single host', function (): void {
    config()->set('queue-autoscale.groups.poison-group', ['queues' => ['email', 'sms'], 'connection' => 'redis']);
    config()->set('queue-autoscale.groups.healthy-group', ['queues' => ['push'], 'connection' => 'redis']);

    spawnerThatFailsFor([], ['poison-group']);
    Event::fake([WorkersScaled::class]);

    stubMetricsRecalculation();

    fakeDiscoveredQueues([
        'redis:email' => rawDiscoveredMetrics('redis', 'email'),
        'redis:sms' => rawDiscoveredMetrics('redis', 'sms'),
        'redis:push' => rawDiscoveredMetrics('redis', 'push'),
    ]);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'evaluateAndScale'))->invoke($manager);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->queue === 'healthy-group' && $event->action === 'up';
    });
});

/*
 * #53 isolated the apply path and the single-host loop, but the leader's own
 * demand-collection loop was left unguarded. A throw there unwinds to the run
 * loop, so NO recommendation is published for ANY host that cycle and every
 * manager holds against a stale one — the widest blast radius of the three
 * paths, and the one that was missed.
 */
it('publishes for the remaining workloads when one throws during leader evaluation', function (): void {
    Event::fake();

    $store = (new FakeClusterStore)
        ->withManager(isolationManagerState('mgr-1'))
        ->withLeader('mgr-1');

    app()->instance(ClusterStoreContract::class, $store);
    app()->forgetInstance(AutoscaleManager::class);

    stubMetricsRecalculation();

    fakeDiscoveredQueues([
        'redis:poison' => rawDiscoveredMetrics('redis', 'poison'),
        'redis:healthy' => rawDiscoveredMetrics('redis', 'healthy'),
    ]);

    // A profile that throws only for the poison queue, reached from inside
    // Phase A via QueueConfiguration::fromConfig().
    config()->set('queue-autoscale.queues.poison.profile', ThrowingProfile::class);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations'))->invoke($manager);

    $recommendation = $store->publishedRecommendations()['mgr-1'] ?? null;

    expect($recommendation)->not->toBeNull('the cycle must still publish')
        ->and($recommendation->workloads)->toHaveKey('queue:redis:healthy')
        ->and($recommendation->workloads)->not->toHaveKey('queue:redis:poison');
});
