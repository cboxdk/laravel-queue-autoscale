<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

it('writes a restart signal for the autoscale manager', function () {
    $signal = app(RestartSignal::class);

    Cache::forget($signal->cacheKey());
    Cache::forget($signal->managerCacheKey());

    $this->artisan('queue:autoscale:restart')
        ->expectsOutput('Broadcasting queue autoscale restart signal.')
        ->assertSuccessful();

    expect(Cache::get($signal->cacheKey()))
        ->toBeInt()
        ->and(Cache::get($signal->managerCacheKey()))
        ->toBeInt();
});

it('stops the autoscale manager and drains workers when restart is requested', function () {
    Event::fake([AutoscaleManagerStopped::class]);

    $signal = app(RestartSignal::class);
    Cache::forever($signal->cacheKey(), PHP_INT_MAX);

    $manager = app(AutoscaleManager::class);

    $poolProperty = new ReflectionProperty($manager, 'pool');
    /** @var WorkerPool $pool */
    $pool = $poolProperty->getValue($manager);

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('getPid')->andReturn(null);
    $process->shouldReceive('isRunning')->andReturn(true);

    $pool->add(new WorkerProcess(
        process: $process,
        connection: 'redis',
        queue: 'default',
        spawnedAt: now(),
    ));

    try {
        $result = $manager->run();
    } finally {
        Cache::forget($signal->cacheKey());
    }

    expect($result)->toBe(0);

    Event::assertDispatched(AutoscaleManagerStopped::class, function (AutoscaleManagerStopped $event): bool {
        return $event->reason === 'restart_signal'
            && $event->workerCount === 1;
    });
});
