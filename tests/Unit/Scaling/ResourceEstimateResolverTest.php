<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\EstimateSource;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;

it('returns global default when no config or measured data exists', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', []);

    $resolver = new ResourceEstimateResolver;
    $estimate = $resolver->resolve('redis', 'default');

    expect($estimate->cpuCoresPerWorker)->toBe(0.2)
        ->and($estimate->memoryMbPerWorker)->toBe(128.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Default)
        ->and($estimate->memorySource)->toBe(EstimateSource::Default);
});

it('returns per-queue config when configured, overriding global default', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', [
        'slow' => [
            'resources' => [
                'cpu_cores' => 0.5,
                'memory_mb' => 2048,
            ],
        ],
    ]);

    $resolver = new ResourceEstimateResolver;
    $estimate = $resolver->resolve('redis', 'slow');

    expect($estimate->cpuCoresPerWorker)->toBe(0.5)
        ->and($estimate->memoryMbPerWorker)->toBe(2048.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Config)
        ->and($estimate->memorySource)->toBe(EstimateSource::Config);
});

it('uses partial config: cpu from config, memory from global default', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', [
        'mixed' => [
            'resources' => [
                'cpu_cores' => 0.8,
            ],
        ],
    ]);

    $resolver = new ResourceEstimateResolver;
    $estimate = $resolver->resolve('redis', 'mixed');

    expect($estimate->cpuCoresPerWorker)->toBe(0.8)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Config)
        ->and($estimate->memoryMbPerWorker)->toBe(128.0)
        ->and($estimate->memorySource)->toBe(EstimateSource::Default);
});

it('returns measured data when available, overriding config', function () {
    config()->set('queue-autoscale.queues', [
        'fast' => [
            'resources' => [
                'cpu_cores' => 0.3,
                'memory_mb' => 256,
            ],
        ],
    ]);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'fast', 0.05, 48.0, 4089, 4089);

    $estimate = $resolver->resolve('redis', 'fast');

    expect($estimate->cpuCoresPerWorker)->toBe(0.05)
        ->and($estimate->memoryMbPerWorker)->toBe(48.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Measured)
        ->and($estimate->memorySource)->toBe(EstimateSource::Measured)
        ->and($estimate->cpuSampleCount)->toBe(4089)
        ->and($estimate->memorySampleCount)->toBe(4089);
});

it('uses measured cpu but config memory when only cpu is measured', function () {
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', [
        'partial' => [
            'resources' => [
                'memory_mb' => 512,
            ],
        ],
    ]);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasuredCpu('redis', 'partial', 0.1, 200);

    $estimate = $resolver->resolve('redis', 'partial');

    expect($estimate->cpuCoresPerWorker)->toBe(0.1)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Measured)
        ->and($estimate->cpuSampleCount)->toBe(200)
        ->and($estimate->memoryMbPerWorker)->toBe(512.0)
        ->and($estimate->memorySource)->toBe(EstimateSource::Config);
});

it('uses measured memory but global default cpu when only memory is measured', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    config()->set('queue-autoscale.queues', []);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasuredMemory('redis', 'heavy', 2048.0, 100);

    $estimate = $resolver->resolve('redis', 'heavy');

    expect($estimate->cpuCoresPerWorker)->toBe(0.2)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Default)
        ->and($estimate->memoryMbPerWorker)->toBe(2048.0)
        ->and($estimate->memorySource)->toBe(EstimateSource::Measured)
        ->and($estimate->memorySampleCount)->toBe(100);
});

it('keeps queues independent — setting measured on one does not affect another', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', []);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'fast', 0.05, 48.0, 1000, 1000);

    $fastEstimate = $resolver->resolve('redis', 'fast');
    $slowEstimate = $resolver->resolve('redis', 'slow');

    expect($fastEstimate->cpuCoresPerWorker)->toBe(0.05)
        ->and($fastEstimate->cpuSource)->toBe(EstimateSource::Measured)
        ->and($slowEstimate->cpuCoresPerWorker)->toBe(0.2)
        ->and($slowEstimate->cpuSource)->toBe(EstimateSource::Default);
});

it('resets all measured data', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.2);
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 128);
    config()->set('queue-autoscale.queues', []);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'fast', 0.05, 48.0, 1000, 1000);
    $resolver->reset();

    $estimate = $resolver->resolve('redis', 'fast');

    expect($estimate->cpuSource)->toBe(EstimateSource::Default)
        ->and($estimate->memorySource)->toBe(EstimateSource::Default);
});

it('clamps cpu estimate to minimum of 0.01 cores', function () {
    config()->set('queue-autoscale.queues', []);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'tiny', 0.001, 48.0, 100, 100);

    $estimate = $resolver->resolve('redis', 'tiny');

    expect($estimate->cpuCoresPerWorker)->toBe(0.01);
});

it('clamps memory estimate to minimum of 16 MB', function () {
    config()->set('queue-autoscale.queues', []);

    $resolver = new ResourceEstimateResolver;
    $resolver->setMeasured('redis', 'light', 0.05, 5.0, 100, 100);

    $estimate = $resolver->resolve('redis', 'light');

    expect($estimate->memoryMbPerWorker)->toBe(16.0);
});
