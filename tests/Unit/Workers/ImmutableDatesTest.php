<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Scaling\WorkloadStateTracker;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Process\Process;

// Hosts commonly call Date::use(CarbonImmutable::class), which makes now()
// return CarbonImmutable. The manager hands now() to its workers and state
// tracker, so none of them may assume a mutable Carbon.
beforeEach(fn () => Date::use(CarbonImmutable::class));
afterEach(fn () => Date::useDefault());

test('spawns workers', function (): void {
    // A long-lived stand-in for queue:work, so the spawn does not depend on the
    // test host being able to boot a real worker.
    $spawner = new readonly class(app(SpawnLatencyTrackerContract::class)) extends WorkerSpawner
    {
        public function buildCommand(string $connection, string $queue, WorkerConfiguration $workerConfig): array
        {
            return ['sleep', '5'];
        }
    };

    $workers = $spawner
        ->spawn('redis', 'default', 1, new SpawnCompensationConfiguration(
            enabled: false,
            fallbackSeconds: 2.0,
            minSamples: 5,
            emaAlpha: 0.2,
        ));

    try {
        expect(now())->toBeInstanceOf(CarbonImmutable::class)
            ->and($workers)->toHaveCount(1)
            ->and($workers->first()?->spawnedAt)->toBeInstanceOf(CarbonImmutable::class);
    } finally {
        $workers->each(fn (WorkerProcess $worker) => $worker->process->stop(0));
    }
});

test('requests termination and tracks its deadline', function (): void {
    $process = new Process(['sleep', '5']);
    $process->start();

    $worker = new WorkerProcess(process: $process, connection: 'redis', queue: 'default', spawnedAt: now());

    try {
        expect((new WorkerTerminator)->requestTermination($worker))->toBeTrue()
            ->and($worker->isTerminating())->toBeTrue()
            ->and($worker->uptimeSeconds())->toBeGreaterThanOrEqual(0);

        expect($worker->terminationDeadlinePassed(now()))->toBeFalse();

        $this->travel(1)->hour();

        expect($worker->terminationDeadlinePassed(now()))->toBeTrue();
    } finally {
        $process->stop(0);
    }
});

test('forgets quiet workloads', function (): void {
    $tracker = new WorkloadStateTracker;
    $tracker->recordScale('redis:default', 'up');
    $tracker->setBreaching('redis:emails', true);

    $this->travel(2)->minutes();
    $tracker->setBreaching('redis:emails', true);
    $tracker->forgetQuietSince(now()->subMinute());

    expect($tracker->lastDirection('redis:default'))->toBeNull()
        ->and($tracker->wasBreaching('redis:emails'))->toBeTrue();
});
