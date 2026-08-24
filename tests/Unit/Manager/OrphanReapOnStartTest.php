<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Workers\OrphanedWorkerReaper;

function invokeOrphanReap(): void
{
    app()->forgetInstance(AutoscaleManager::class);
    $manager = app(AutoscaleManager::class);

    (new ReflectionMethod($manager, 'reapOrphanedWorkers'))->invoke($manager);
}

test('startup reaps orphans scoped to this manager id by default', function (): void {
    $reaper = Mockery::mock(OrphanedWorkerReaper::class);
    $reaper->shouldReceive('reap')
        ->once()
        ->with(AutoscaleConfiguration::managerId())
        ->andReturn(2);

    app()->instance(OrphanedWorkerReaper::class, $reaper);

    invokeOrphanReap();
});

test('the reap can be disabled by configuration', function (): void {
    config()->set('queue-autoscale.manager.reap_orphans_on_start', false);

    $reaper = Mockery::mock(OrphanedWorkerReaper::class);
    $reaper->shouldReceive('reap')->never();

    app()->instance(OrphanedWorkerReaper::class, $reaper);

    invokeOrphanReap();
});
