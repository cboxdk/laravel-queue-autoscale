<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * Detailed breakdown of capacity calculations
 *
 * Provides transparency into why a specific maximum worker
 * count was chosen and which factor is limiting scaling.
 *
 * This enables:
 * - Debugging capacity constraints
 * - Understanding resource bottlenecks
 * - Optimizing infrastructure allocation
 * - Transparent scaling decisions
 */
readonly class CapacityCalculationResult
{
    /**
     * @param  int  $maxWorkersByCpu  Maximum workers based on CPU constraints
     * @param  int  $maxWorkersByMemory  Maximum workers based on memory constraints
     * @param  int  $maxWorkersByConfig  Maximum workers based on configuration limit
     * @param  int  $finalMaxWorkers  Actual maximum (minimum of all constraints)
     * @param  LimitingFactor  $limitingFactor  Which constraint decided the final count
     * @param  array<string, mixed>  $details  Additional calculation details for debugging
     */
    public function __construct(
        public int $maxWorkersByCpu,
        public int $maxWorkersByMemory,
        public int $maxWorkersByConfig,
        public int $finalMaxWorkers,
        public LimitingFactor $limitingFactor,
        public array $details = [],
    ) {}

    /**
     * The CPU breakdown as a typed object.
     *
     * `$details` remains the documented array form — its keys are public API —
     * so this is an additive accessor for callers that would otherwise index
     * into nested `mixed`.
     */
    public function cpuBreakdown(): CpuBreakdown
    {
        return CpuBreakdown::fromDetails($this->nestedDetails('cpu_details'));
    }

    /**
     * The memory breakdown as a typed object. See cpuBreakdown().
     */
    public function memoryBreakdown(): MemoryBreakdown
    {
        return MemoryBreakdown::fromDetails($this->nestedDetails('memory_details'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function nestedDetails(string $key): array
    {
        $nested = $this->details[$key] ?? null;

        return is_array($nested) ? $nested : [];
    }

    /**
     * Check if CPU is the limiting factor
     */
    public function isCpuLimited(): bool
    {
        return $this->limitingFactor === LimitingFactor::Cpu;
    }

    /**
     * Check if memory is the limiting factor
     */
    public function isMemoryLimited(): bool
    {
        return $this->limitingFactor === LimitingFactor::Memory;
    }

    /**
     * Check if configuration is the limiting factor
     */
    public function isConfigLimited(): bool
    {
        return $this->limitingFactor === LimitingFactor::Config;
    }

    /**
     * Get a human-readable summary of the capacity calculation
     */
    public function getSummary(): string
    {
        return sprintf(
            'CPU: %d workers, Memory: %d workers, Config: %d workers → Final: %d workers (limited by: %s)',
            $this->maxWorkersByCpu,
            $this->maxWorkersByMemory,
            $this->maxWorkersByConfig,
            $this->finalMaxWorkers,
            $this->limitingFactor->value
        );
    }

    /**
     * Get formatted details for verbose output
     *
     * @return array<string, string>
     */
    public function getFormattedDetails(): array
    {
        $cpuExplanation = $this->getDetailString('cpu_explanation', 'no details');
        $memoryExplanation = $this->getDetailString('memory_explanation', 'no details');

        $formatted = [
            'CPU Limit' => sprintf(
                '%d workers (%s)',
                $this->maxWorkersByCpu,
                $cpuExplanation
            ),
            'Memory Limit' => sprintf(
                '%d workers (%s)',
                $this->maxWorkersByMemory,
                $memoryExplanation
            ),
            'Config Limit' => sprintf(
                '%d workers (max_workers setting)',
                $this->maxWorkersByConfig
            ),
            'Final Capacity' => sprintf(
                '%d workers (%s)',
                $this->finalMaxWorkers,
                $this->getFactorDescription()
            ),
        ];

        return $formatted;
    }

    /**
     * Get a human-readable description of the capacity factor
     */
    private function getFactorDescription(): string
    {
        return $this->limitingFactor->description();
    }

    /**
     * Get a string value from details array with type safety
     */
    private function getDetailString(string $key, string $default): string
    {
        $value = $this->details[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
