<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Manager\SignalHandler;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;

final class RecordingEvaluationCapacityCalculator extends CapacityCalculator
{
    public int $invalidations = 0;

    public function invalidateCache(): void
    {
        $this->invalidations++;

        parent::invalidateCache();
    }
}

function makeManagerWithCapacity(CapacityCalculator $capacity): AutoscaleManager
{
    return new AutoscaleManager(
        engine: app(ScalingEngine::class),
        spawner: app(WorkerSpawner::class),
        terminator: app(WorkerTerminator::class),
        policies: app(PolicyExecutor::class),
        signals: app(SignalHandler::class),
        restartSignal: app(RestartSignal::class),
        clusterStore: app(ClusterStore::class),
        capacity: $capacity,
        resolver: app(ResourceEstimateResolver::class),
    );
}

function invokeBeginEvaluationCycle(AutoscaleManager $manager): void
{
    $method = new ReflectionMethod($manager, 'beginEvaluationCycle');
    $method->invoke($manager);
}

it('starts evaluation cycles with a fresh capacity snapshot', function (): void {
    $capacity = new RecordingEvaluationCapacityCalculator;
    $manager = makeManagerWithCapacity($capacity);

    invokeBeginEvaluationCycle($manager);
    invokeBeginEvaluationCycle($manager);

    expect($capacity->invalidations)->toBe(2);
});
