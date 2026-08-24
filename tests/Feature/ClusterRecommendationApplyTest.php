<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

/**
 * Scaling events now report the workers that ACTUALLY started rather than the
 * number requested, so a spec that asserts on the event must not also depend
 * on this machine being able to fork a real `queue:work` process. This spawner
 * reports success without touching the OS.
 */
function fakeSpawner(): void
{
    app()->instance(WorkerSpawner::class, new readonly class(app(SpawnLatencyTrackerContract::class)) extends WorkerSpawner
    {
        public function spawn(
            string $connection,
            string $queue,
            int $count,
            SpawnCompensationConfiguration $spawnConfig,
            ?string $group = null,
            ?WorkerConfiguration $workerConfig = null,
        ): Collection {
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

it('applies cluster recommendation for discovered queues not in explicit config', function () {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    expect(AutoscaleConfiguration::configuredQueues())->toBeEmpty();

    fakeSpawner();
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

/*
 * A follower applies whatever integer the leader published. That is fine while
 * the leader is correct — it already applied workers.max and fair-share — and
 * stops being fine the moment it is not: a bug, a version mismatch during a
 * rolling deploy, or anything with write access to the coordination key. The
 * host's own configuration is the last thing that should be overridable from
 * outside the process.
 */
it('clamps a recommendation above this host configured maximum', function () {
    config()->set('queue-autoscale.queues', ['exports' => ['workers' => ['min' => 0, 'max' => 10]]]);
    config()->set('queue-autoscale.groups', []);

    fakeSpawner();
    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: ['queue:redis:exports' => 5000],
    );

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, $recommendation);

    Event::assertDispatched(WorkersScaled::class, fn (WorkersScaled $e): bool => $e->queue === 'exports' && $e->to === 10);
});

it('applies a recommendation within the configured maximum untouched', function () {
    config()->set('queue-autoscale.queues', ['exports' => ['workers' => ['min' => 0, 'max' => 10]]]);
    config()->set('queue-autoscale.groups', []);

    fakeSpawner();
    Event::fake([WorkersScaled::class]);

    $recommendation = new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: ['queue:redis:exports' => 4],
    );

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, $recommendation);

    Event::assertDispatched(WorkersScaled::class, fn (WorkersScaled $e): bool => $e->queue === 'exports' && $e->to === 4);
});

/*
 * The clamp has to cover all three apply paths. A pinned queue's max IS its
 * pinned count, and clampToHostCeiling is the only other bound — which returns
 * the request unchanged when limits.max_total_workers is unset, as it ships.
 * So the pinned path was the least bounded of the three.
 *
 * Asserted as a bound rather than an exact count: what matters is that a
 * recommendation of five thousand cannot produce five thousand workers, not
 * which of the permitted counts this cycle settles on.
 */
it('never exceeds the local maximum for a pinned queue', function () {
    config()->set('queue-autoscale.queues', ['exclusive' => ExclusiveProfile::class]);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.limits.max_total_workers', null);

    fakeSpawner();
    Event::fake([WorkersScaled::class]);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: ['queue:redis:exclusive' => 5000],
    ));

    $pinned = QueueConfiguration::fromConfig('redis', 'exclusive')->workers->max;
    $pool = (new ReflectionProperty($manager, 'pool'))->getValue($manager);

    expect($pool->count('redis', 'exclusive'))->toBeLessThanOrEqual($pinned);
});

it('never exceeds the local maximum for a group', function () {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.groups', [
        'reports' => [
            'connection' => 'redis',
            'queues' => ['a', 'b'],
            'profile' => BalancedProfile::class,
        ],
    ]);
    config()->set('queue-autoscale.limits.max_total_workers', null);

    fakeSpawner();
    Event::fake([WorkersScaled::class]);

    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'applyClusterRecommendation'))->invoke($manager, new ClusterRecommendation(
        managerId: 'test-mgr',
        issuedAt: now()->timestamp,
        workloads: ['group:redis:reports' => 5000],
    ));

    $groups = GroupConfiguration::allFromConfig();
    $max = reset($groups)->workers->max;
    $pool = (new ReflectionProperty($manager, 'pool'))->getValue($manager);

    expect($pool->countGroup('redis', 'reports'))->toBeLessThanOrEqual($max)
        ->and($max)->toBeLessThan(5000);
});
