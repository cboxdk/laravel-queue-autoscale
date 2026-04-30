<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\EstimateSource;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;

it('produces different capacity for fast vs slow queues', function () {
    config()->set('queue-autoscale.limits.max_cpu_percent', 100);
    config()->set('queue-autoscale.limits.max_memory_percent', 100);
    config()->set('queue-autoscale.limits.reserve_cpu_cores', 0);
    config()->set('queue-autoscale.queues', []);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'fast', 0.05, 50.0, 1000, 1000);
    $resolver->setMeasured('redis', 'slow', 0.5, 2048.0, 100, 100);

    $calculator = new CapacityCalculator;

    $fastEstimate = $resolver->resolve('redis', 'fast');
    $slowEstimate = $resolver->resolve('redis', 'slow');

    $fastCapacity = $calculator->calculateMaxWorkers(0, $fastEstimate);
    $slowCapacity = $calculator->calculateMaxWorkers(0, $slowEstimate);

    // Fast queue should allow significantly more workers
    if ($fastCapacity->maxWorkersByCpu > 0 && $slowCapacity->maxWorkersByCpu > 0) {
        expect($fastCapacity->maxWorkersByCpu)->toBeGreaterThan($slowCapacity->maxWorkersByCpu);
    }

    if ($fastCapacity->maxWorkersByMemory > 0 && $slowCapacity->maxWorkersByMemory > 0) {
        expect($fastCapacity->maxWorkersByMemory)->toBeGreaterThan($slowCapacity->maxWorkersByMemory);
    }

    // Verify sources
    expect($fastEstimate->cpuSource)->toBe(EstimateSource::Measured)
        ->and($slowEstimate->cpuSource)->toBe(EstimateSource::Measured);
});

it('falls back to config then default in correct order', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', [
        'configured' => [
            'resources' => [
                'cpu_cores' => 0.4,
                'memory_mb' => 512,
            ],
        ],
    ]);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'measured', 0.1, 64.0, 500, 500);

    // Queue with measured data
    $measuredEstimate = $resolver->resolve('redis', 'measured');
    expect($measuredEstimate->cpuCoresPerWorker)->toBe(0.1)
        ->and($measuredEstimate->cpuSource)->toBe(EstimateSource::Measured);

    // Queue with config override
    $configEstimate = $resolver->resolve('redis', 'configured');
    expect($configEstimate->cpuCoresPerWorker)->toBe(0.4)
        ->and($configEstimate->cpuSource)->toBe(EstimateSource::Config);

    // Queue with no config or measured data
    $defaultEstimate = $resolver->resolve('redis', 'unconfigured');
    expect($defaultEstimate->cpuCoresPerWorker)->toBe(0.2)
        ->and($defaultEstimate->cpuSource)->toBe(EstimateSource::Default);
});

it('measured data overrides config when both exist', function () {
    config()->set('queue-autoscale.queues', [
        'heavy' => [
            'resources' => [
                'cpu_cores' => 0.5,
                'memory_mb' => 2048,
            ],
        ],
    ]);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'heavy', 0.3, 1024.0, 200, 200);

    $estimate = $resolver->resolve('redis', 'heavy');

    // Measured should win over config
    expect($estimate->cpuCoresPerWorker)->toBe(0.3)
        ->and($estimate->memoryMbPerWorker)->toBe(1024.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Measured)
        ->and($estimate->memorySource)->toBe(EstimateSource::Measured);
});
