<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;

readonly class ScalingDecision
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $currentWorkers,
        public int $targetWorkers,
        public string $reason,
        public ?float $predictedPickupTime = null,
        public int $slaTarget = 30,
        public ?CapacityCalculationResult $capacity = null,
        public ?SpawnCompensationConfiguration $spawnCompensation = null,
        public ScalingScope $scope = ScalingScope::Host,
    ) {}

    /**
     * A copy of this decision with a different worker target.
     *
     * The safe way for a policy to adjust a target: everything else,
     * including the scope, carries over unchanged.
     */
    public function withTargetWorkers(int $targetWorkers, ?string $reason = null): self
    {
        return new self(
            connection: $this->connection,
            queue: $this->queue,
            currentWorkers: $this->currentWorkers,
            targetWorkers: $targetWorkers,
            reason: $reason ?? $this->reason,
            predictedPickupTime: $this->predictedPickupTime,
            slaTarget: $this->slaTarget,
            capacity: $this->capacity,
            spawnCompensation: $this->spawnCompensation,
            scope: $this->scope,
        );
    }

    public function shouldScaleUp(): bool
    {
        return $this->targetWorkers > $this->currentWorkers;
    }

    public function shouldScaleDown(): bool
    {
        return $this->targetWorkers < $this->currentWorkers;
    }

    public function shouldHold(): bool
    {
        return $this->targetWorkers === $this->currentWorkers;
    }

    public function workersToAdd(): int
    {
        return max($this->targetWorkers - $this->currentWorkers, 0);
    }

    public function workersToRemove(): int
    {
        return max($this->currentWorkers - $this->targetWorkers, 0);
    }

    public function action(): string
    {
        return match (true) {
            $this->shouldScaleUp() => 'scale_up',
            $this->shouldScaleDown() => 'scale_down',
            default => 'hold',
        };
    }

    public function isSlaBreachRisk(): bool
    {
        if ($this->predictedPickupTime === null) {
            return false;
        }

        return $this->predictedPickupTime > $this->slaTarget;
    }
}
