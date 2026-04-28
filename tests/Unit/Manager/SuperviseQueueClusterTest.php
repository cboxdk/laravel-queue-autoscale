<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ExclusiveProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

/**
 * Create a WorkerProcess backed by a Mockery Process stub.
 *
 * The stub reports isRunning=true and getPid=$pid so the WorkerPool
 * counts it as alive. The PID is deliberately non-existent to avoid
 * signalling real OS processes during terminator calls.
 */
function createStubWorkerProcess(string $connection, string $queue, int $pid): WorkerProcess
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('getPid')->andReturn($pid);
    $process->shouldReceive('isRunning')->andReturn(true);

    return new WorkerProcess(
        process: $process,
        connection: $connection,
        queue: $queue,
        spawnedAt: now(),
    );
}

/**
 * Invoke the private superviseQueue method via reflection.
 */
function callSuperviseQueue(
    AutoscaleManager $manager,
    QueueConfiguration $config,
    QueueMetricsData $metrics,
    ?int $clusterTarget = null,
): void {
    $method = new ReflectionMethod($manager, 'superviseQueue');
    $method->invoke($manager, $config, $metrics, $clusterTarget);
}

/**
 * Get the WorkerPool from the manager via reflection.
 */
function getPool(AutoscaleManager $manager): WorkerPool
{
    $prop = new ReflectionProperty($manager, 'pool');

    return $prop->getValue($manager);
}

test('cluster target 0 does not spawn workers on empty pool', function (): void {
    config()->set('queue-autoscale.queues', [
        'exclusive-queue' => ExclusiveProfile::class,
    ]);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    Event::fake([WorkersScaled::class]);

    $manager = app(AutoscaleManager::class);
    $config = QueueConfiguration::fromConfig('redis', 'exclusive-queue');
    $metrics = createMetrics(['connection' => 'redis', 'queue' => 'exclusive-queue']);

    callSuperviseQueue($manager, $config, $metrics, clusterTarget: 0);

    $pool = getPool($manager);
    expect($pool->count('redis', 'exclusive-queue'))->toBe(0);
    Event::assertNotDispatched(WorkersScaled::class);
});

test('cluster target 0 terminates existing worker', function (): void {
    config()->set('queue-autoscale.queues', [
        'exclusive-queue' => ExclusiveProfile::class,
    ]);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    Event::fake([WorkersScaled::class]);

    $manager = app(AutoscaleManager::class);
    $pool = getPool($manager);

    $existingWorker = createStubWorkerProcess('redis', 'exclusive-queue', 999991);
    $pool->add($existingWorker);

    $config = QueueConfiguration::fromConfig('redis', 'exclusive-queue');
    $metrics = createMetrics(['connection' => 'redis', 'queue' => 'exclusive-queue']);

    callSuperviseQueue($manager, $config, $metrics, clusterTarget: 0);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->action === 'down'
            && $event->from === 1
            && $event->to === 0
            && $event->reason === 'supervisor:trim';
    });
});

test('cluster target 1 spawns worker on empty pool', function (): void {
    config()->set('queue-autoscale.queues', [
        'exclusive-queue' => ExclusiveProfile::class,
    ]);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    Event::fake([WorkersScaled::class]);

    $manager = app(AutoscaleManager::class);
    $config = QueueConfiguration::fromConfig('redis', 'exclusive-queue');
    $metrics = createMetrics(['connection' => 'redis', 'queue' => 'exclusive-queue']);

    callSuperviseQueue($manager, $config, $metrics, clusterTarget: 1);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->action === 'up'
            && $event->from === 0
            && $event->to === 1
            && $event->reason === 'supervisor:respawn';
    });
});

test('null cluster target falls back to pinnedCount', function (): void {
    config()->set('queue-autoscale.queues', [
        'exclusive-queue' => ExclusiveProfile::class,
    ]);
    config()->set('queue-autoscale.groups', []);
    config()->set('queue-autoscale.excluded', []);

    Event::fake([WorkersScaled::class]);

    $manager = app(AutoscaleManager::class);
    $config = QueueConfiguration::fromConfig('redis', 'exclusive-queue');
    $metrics = createMetrics(['connection' => 'redis', 'queue' => 'exclusive-queue']);

    callSuperviseQueue($manager, $config, $metrics, clusterTarget: null);

    Event::assertDispatched(WorkersScaled::class, function (WorkersScaled $event): bool {
        return $event->action === 'up' && $event->to === 1;
    });
});
