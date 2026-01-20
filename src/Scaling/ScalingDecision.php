<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;

final readonly class ScalingDecision
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
    ) {}

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
