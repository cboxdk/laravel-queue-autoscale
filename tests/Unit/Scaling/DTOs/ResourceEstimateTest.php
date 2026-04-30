<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\EstimateSource;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;

it('constructs with all parameters', function () {
    $estimate = new ResourceEstimate(
        cpuCoresPerWorker: 0.15,
        memoryMbPerWorker: 256.0,
        cpuSource: EstimateSource::Measured,
        memorySource: EstimateSource::Config,
        cpuSampleCount: 500,
        memorySampleCount: null,
    );

    expect($estimate->cpuCoresPerWorker)->toBe(0.15)
        ->and($estimate->memoryMbPerWorker)->toBe(256.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Measured)
        ->and($estimate->memorySource)->toBe(EstimateSource::Config)
        ->and($estimate->cpuSampleCount)->toBe(500)
        ->and($estimate->memorySampleCount)->toBeNull();
});

it('creates global default from config values', function () {
    config()->set('queue-autoscale.limits.worker_cpu_core_estimate', 0.3);
    config()->set('queue-autoscale.limits.worker_memory_mb_estimate', 256);

    $estimate = ResourceEstimate::globalDefault();

    expect($estimate->cpuCoresPerWorker)->toBe(0.3)
        ->and($estimate->memoryMbPerWorker)->toBe(256.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Default)
        ->and($estimate->memorySource)->toBe(EstimateSource::Default)
        ->and($estimate->cpuSampleCount)->toBeNull()
        ->and($estimate->memorySampleCount)->toBeNull();
});

it('creates from per-queue config values', function () {
    $estimate = ResourceEstimate::fromConfig(0.5, 2048.0);

    expect($estimate->cpuCoresPerWorker)->toBe(0.5)
        ->and($estimate->memoryMbPerWorker)->toBe(2048.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Config)
        ->and($estimate->memorySource)->toBe(EstimateSource::Config)
        ->and($estimate->cpuSampleCount)->toBeNull()
        ->and($estimate->memorySampleCount)->toBeNull();
});

it('creates from measured values with sample counts', function () {
    $estimate = ResourceEstimate::measured(
        cpuCoresPerWorker: 0.05,
        memoryMbPerWorker: 48.0,
        cpuSampleCount: 4089,
        memorySampleCount: 4089,
    );

    expect($estimate->cpuCoresPerWorker)->toBe(0.05)
        ->and($estimate->memoryMbPerWorker)->toBe(48.0)
        ->and($estimate->cpuSource)->toBe(EstimateSource::Measured)
        ->and($estimate->memorySource)->toBe(EstimateSource::Measured)
        ->and($estimate->cpuSampleCount)->toBe(4089)
        ->and($estimate->memorySampleCount)->toBe(4089);
});

it('uses default config values when not explicitly configured', function () {
    config()->offsetUnset('queue-autoscale.limits.worker_cpu_core_estimate');
    config()->offsetUnset('queue-autoscale.limits.worker_memory_mb_estimate');

    $estimate = ResourceEstimate::globalDefault();

    expect($estimate->cpuCoresPerWorker)->toBe(0.2)
        ->and($estimate->memoryMbPerWorker)->toBe(128.0);
});
