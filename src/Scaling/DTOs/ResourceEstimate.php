<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

final readonly class ResourceEstimate
{
    public function __construct(
        public float $cpuCoresPerWorker,
        public float $memoryMbPerWorker,
        public EstimateSource $cpuSource,
        public EstimateSource $memorySource,
        public ?int $cpuSampleCount = null,
        public ?int $memorySampleCount = null,
    ) {}

    public static function globalDefault(): self
    {
        return new self(
            cpuCoresPerWorker: AutoscaleConfiguration::workerCpuCoreEstimate(),
            memoryMbPerWorker: (float) AutoscaleConfiguration::workerMemoryMbEstimate(),
            cpuSource: EstimateSource::Default,
            memorySource: EstimateSource::Default,
        );
    }

    public static function fromConfig(float $cpuCoresPerWorker, float $memoryMbPerWorker): self
    {
        return new self(
            cpuCoresPerWorker: $cpuCoresPerWorker,
            memoryMbPerWorker: $memoryMbPerWorker,
            cpuSource: EstimateSource::Config,
            memorySource: EstimateSource::Config,
        );
    }

    public static function measured(
        float $cpuCoresPerWorker,
        float $memoryMbPerWorker,
        int $cpuSampleCount,
        int $memorySampleCount,
    ): self {
        return new self(
            cpuCoresPerWorker: $cpuCoresPerWorker,
            memoryMbPerWorker: $memoryMbPerWorker,
            cpuSource: EstimateSource::Measured,
            memorySource: EstimateSource::Measured,
            cpuSampleCount: $cpuSampleCount,
            memorySampleCount: $memorySampleCount,
        );
    }
}
