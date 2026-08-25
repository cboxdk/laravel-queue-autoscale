<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterCooldown;
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
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);
    $seconds = (int) config('queue-autoscale.scaling.cooldown_seconds', 60) ?: 60;

    return $cooldown->apply($key, $current, $target, $isBreaching, $seconds)->targetWorkers;
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

test('a quiet cycle neither opens nor refreshes the window', function (): void {
    // remember() drops a 'hold', so the cycle in the middle records nothing and
    // the withdrawal below is damped by the scale-up at the start — with the
    // window still measured from that scale-up, not from the quiet cycle.
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

test('a workload with no recorded scale time is never in cooldown', function (): void {
    // Nothing has been published for this workload, so there is no remembered
    // direction to reverse against and the first move must pass through
    // undamped however large it is.
    $decision = (new ClusterCooldown)->apply('queue:redis:never-seen', 8, 1, false, 60);

    expect($decision->targetWorkers)->toBe(1)
        ->and($decision->wasHeld)->toBeFalse()
        ->and($decision->breachOverride)->toBeFalse();
});

/*
 * The remembered value is the last target PUBLISHED, which the fleet may never
 * have reached — a host ceiling, max_total_workers, or a failed spawn all
 * leave the actual worker count below it. Republishing it unclamped answered a
 * scale-down request with a scale-up, which is the opposite of damping.
 */
test('a hold never publishes more workers than are actually running', function (): void {
    $cooldown = new ClusterCooldown;

    // Cycle 1: demand rises to 22 and is published, but only 15 ever start.
    $cooldown->apply('queue:redis:reports', 8, 22, false, 60);

    // Cycle 2: demand collapses. This is a scale-DOWN request.
    $decision = $cooldown->apply('queue:redis:reports', 15, 8, false, 60);

    expect($decision->wasHeld)->toBeTrue()
        ->and($decision->targetWorkers)->toBe(15)
        ->and($decision->targetWorkers)->toBeLessThanOrEqual(15, 'a hold must never scale up');
});

test('a hold still damps when the fleet did reach the published target', function (): void {
    $cooldown = new ClusterCooldown;

    $cooldown->apply('queue:redis:reports', 8, 22, false, 60);

    // The fleet converged: current now equals the published 22.
    $decision = $cooldown->apply('queue:redis:reports', 22, 4, false, 60);

    expect($decision->wasHeld)->toBeTrue()
        ->and($decision->targetWorkers)->toBe(22, 'the damping itself must be unchanged');
});

/*
 * The hold clamps what is PUBLISHED, not what is remembered. Writing the
 * clamped value back would ratchet: one transient dip in reported workers — a
 * crash, a host leaving, a heartbeat lagging behind a spawn — would lower the
 * hold for the rest of the window and never recover.
 */
test('a transient dip in running workers does not permanently lower the hold', function (): void {
    $cooldown = new ClusterCooldown;

    $cooldown->apply('queue:redis:exports', 0, 10, false, 60);

    $dip = $cooldown->apply('queue:redis:exports', 3, 1, false, 60);
    $recovered = $cooldown->apply('queue:redis:exports', 10, 1, false, 60);

    expect($dip->targetWorkers)->toBe(3, 'never publishes above what is running')
        ->and($recovered->targetWorkers)->toBe(10, 'the remembered target survives the dip');
});

/**
 * The damping is one-sided, and these are the specs that were missing.
 *
 * Every reversal spec above drives a scale-DOWN. The only rising reversal
 * covered passed `isBreaching: true`, so the case that actually mattered — a
 * scale-up held because the last move happened to be a scale-down, with no
 * breach yet — was never asserted. That untested branch is what let the guard
 * become the source of the oscillation: it deferred every rise on an
 * oscillating workload until the backlog breached, then released a target the
 * delay had itself inflated.
 */
test('a scale-up reversing a recent scale-down is never held', function (): void {
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 22, target: 8);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22))->toBe(22);
});

test('a rise is published in full on every cycle of an oscillating demand', function (): void {
    $manager = app(AutoscaleManager::class);
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);

    $current = 12;
    $rises = 0;
    $heldRises = [];

    // Alternating demand well inside the cooldown window: every change is a
    // reversal, which is precisely the shape that used to breach. It has to
    // OPEN on the scale-down — a held scale-down pins `current` at the high
    // figure, so the demand that follows is no longer a rise and the branch
    // under test is never reached.
    foreach ([4, 12, 4, 12, 4, 12] as $index => $target) {
        Carbon::setTestNow(now()->addSeconds(5));

        $isRise = $target > $current;
        $decision = $cooldown->apply('queue:redis:exports', $current, $target, false, 60);

        if ($isRise) {
            $rises++;

            if ($decision->targetWorkers !== $target) {
                $heldRises[] = $index;
            }
        }

        $current = $decision->targetWorkers;
    }

    expect($rises)->toBeGreaterThan(0)
        ->and($heldRises)->toBe([]);
});

test('a scale-down is still damped after a scale-up', function (): void {
    // The other half of the asymmetry: withdrawing capacity stays damped,
    // because a held scale-down only costs money and is fully recoverable.
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 8))->toBe(22);
});

test('a breaching scale-up is still reported as a breach override', function (): void {
    // The signal operators see is unchanged; the scale-up simply no longer
    // needs an exception to get through.
    $manager = app(AutoscaleManager::class);
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);

    $cooldown->apply('queue:redis:exports', 22, 8, false, 60);

    Carbon::setTestNow(now()->addSeconds(5));

    $decision = $cooldown->apply('queue:redis:exports', 8, 22, true, 60);

    expect($decision->breachOverride)->toBeTrue()
        ->and($decision->wasHeld)->toBeFalse();
});

test('a reversing scale-up with no breach reports no override', function (): void {
    // The combination that separates the flag's meaning from "any reversal":
    // the scale-up passes either way, so only this case shows that the flag
    // still tracks the breach rather than the reversal.
    $manager = app(AutoscaleManager::class);
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);

    $cooldown->apply('queue:redis:exports', 22, 8, false, 60);

    Carbon::setTestNow(now()->addSeconds(5));

    $decision = $cooldown->apply('queue:redis:exports', 8, 22, false, 60);

    expect($decision->targetWorkers)->toBe(22)
        ->and($decision->breachOverride)->toBeFalse()
        ->and($decision->wasHeld)->toBeFalse();
});

test('a rise arriving mid-drain is not answered by cutting the fleet', function (): void {
    // The sharpest form of the old behaviour. A scale-down is allowed and the
    // fleet starts draining; before it lands, demand turns around. The hold
    // republished the remembered scale-down target clamped to what was
    // running, so a request for MORE workers was published as fewer: 8 running,
    // 15 wanted, 5 published.
    $manager = app(AutoscaleManager::class);
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);

    $cooldown->apply('queue:redis:exports', 12, 5, false, 60);

    Carbon::setTestNow(now()->addSeconds(5));

    expect($cooldown->apply('queue:redis:exports', 8, 15, false, 60)->targetWorkers)->toBe(15);
});

test('a scale-up with no breach and no reversal reports no override', function (): void {
    $manager = app(AutoscaleManager::class);
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);

    $decision = $cooldown->apply('queue:redis:exports', 8, 22, false, 60);

    expect($decision->breachOverride)->toBeFalse()
        ->and($decision->wasHeld)->toBeFalse();
});

test('consecutive withdrawals are never delayed', function (): void {
    // The other half of the rule: damping applies to the FIRST scale-down, so a
    // fleet that is genuinely draining is not made to wait a window per step.
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 22, target: 16);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 16, target: 9))->toBe(9);

    Carbon::setTestNow(now()->addSeconds(5));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 9, target: 4))->toBe(4);
});

test('a quiet stretch longer than the window releases the next withdrawal', function (): void {
    // The consequence of remember() dropping a hold: quiet cycles do not keep
    // the window alive, so a workload that has been steady for longer than
    // cooldown_seconds withdraws immediately.
    $manager = app(AutoscaleManager::class);

    applyCooldown($manager, 'queue:redis:exports', current: 8, target: 22);

    Carbon::setTestNow(now()->addSeconds(30));
    applyCooldown($manager, 'queue:redis:exports', current: 22, target: 22);

    Carbon::setTestNow(now()->addSeconds(31));

    expect(applyCooldown($manager, 'queue:redis:exports', current: 22, target: 10))->toBe(10);
});

test('the leader carries its fair-share credits from one cycle to the next', function (): void {
    // The banked entitlement that stops a workload being starved forever lives
    // in the allocator, so it only accumulates if the instance outlives the
    // evaluation. This was built with `new FairShareAllocator` INSIDE the
    // cluster evaluation — which runs every cycle — so every balance reset to
    // zero each time and the guarantee was a no-op in production, while its own
    // unit test passed because that test reuses one instance across cycles.
    config()->set('queue.default', 'redis');
    // Floors that cannot all fit: the single manager offers 32 workers of
    // capacity and these ask for 42, which is the only situation the banked
    // entitlement exists for.
    // Seven, not eight: eight floors of six divide the 32 available workers
    // exactly, leaving no remainder to hand out and nothing to bank, so the
    // spec would pass while measuring nothing.
    $queues = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf'];
    $rules = $discovered = [];

    foreach ($queues as $queue) {
        $rules[$queue] = ['workers' => ['min' => 6, 'max' => 20]];
        $discovered["redis:{$queue}"] = cooldownRawMetrics('redis', $queue, pending: 200);
    }

    config()->set('queue-autoscale.queues', $rules);

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
    $allocator = (new ReflectionProperty($manager, 'allocator'))->getValue($manager);
    $credits = new ReflectionProperty($allocator, 'credits');

    cooldownDiscovery($discovered);

    $evaluate->invoke($manager);
    $afterFirst = $credits->getValue($allocator);

    Carbon::setTestNow(now()->addSeconds(5));
    $evaluate->invoke($manager);

    $afterSecond = $credits->getValue($allocator);

    expect($afterFirst)->not->toBe([])
        ->and($afterSecond)->not->toBe($afterFirst);
});

test('the leader opens the fairness ledger from what the cluster is running', function (): void {
    // The allocator can only seed from an observation the leader passes it.
    // Without that argument a manager taking the lease starts every balance at
    // zero, the ordering falls back to the key, and leadership that keeps
    // moving re-starves the same alphabetically-first workloads — which is the
    // whole failure the seeding exists to prevent.
    config()->set('queue.default', 'redis');

    $queues = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf'];
    $rules = $discovered = [];

    foreach ($queues as $queue) {
        $rules[$queue] = ['workers' => ['min' => 6, 'max' => 20]];
        $discovered["redis:{$queue}"] = cooldownRawMetrics('redis', $queue, pending: 200);
    }

    config()->set('queue-autoscale.queues', $rules);

    $tracker = Mockery::mock(SpawnLatencyTrackerContract::class);
    $tracker->shouldReceive('currentLatency')->andReturn(0.0);
    app()->instance(SpawnLatencyTrackerContract::class, $tracker);

    $pickupStore = Mockery::mock(PickupTimeStoreContract::class);
    $pickupStore->shouldReceive('recentSamples')->andReturn([]);
    app()->instance(PickupTimeStoreContract::class, $pickupStore);

    Event::fake();

    // One workload is already running workers and the rest are at nothing —
    // a lopsided cluster the seeding must be able to see.
    $store = (new FakeClusterStore)
        ->withManager(cooldownManagerState('mgr-1', totalWorkers: 9, queueWorkers: ['redis:alpha' => 9]))
        ->withLeader('mgr-1');
    app()->instance(ClusterStoreContract::class, $store);
    app()->forgetInstance(AutoscaleManager::class);

    $manager = app(AutoscaleManager::class);
    $allocator = (new ReflectionProperty($manager, 'allocator'))->getValue($manager);

    cooldownDiscovery($discovered);
    (new ReflectionMethod($manager, 'evaluateAndPublishClusterRecommendations'))->invoke($manager);

    $ledger = (new ReflectionProperty($allocator, 'credits'))->getValue($allocator);

    // alpha holds nine workers against a share of a few, so it opens in debit
    // while everything else opens in credit. All-equal balances would mean the
    // observation never arrived.
    expect($ledger)->toHaveKey('queue:redis:alpha')
        ->and($ledger['queue:redis:alpha'])->toBeLessThan(min(array_diff_key($ledger, ['queue:redis:alpha' => true])));
});
