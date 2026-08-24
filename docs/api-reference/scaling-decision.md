---
title: "Scaling Decision"
description: "The decision object a strategy returns and a policy may adjust, including cluster scope"
weight: 30
---

## Scaling Decision

Returned by `ScalingEngine::evaluate()` and dispatched in `ScalingDecisionMade` / `SlaBreachPredicted` events.

```php
namespace Cbox\LaravelQueueAutoscale\Scaling;

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
    ) {}

    public function shouldScaleUp(): bool;
    public function shouldScaleDown(): bool;
    public function shouldHold(): bool;
    public function workersToAdd(): int;
    public function workersToRemove(): int;
    public function action(): string;           // 'scale_up' | 'scale_down' | 'hold'
    public function isSlaBreachRisk(): bool;    // predictedPickupTime > slaTarget
}
```

There is **no** `confidence` property on `ScalingDecision`, or anywhere else in the package.

Do not confuse `ScalingDecision::action()` (`'scale_up' | 'scale_down' | 'hold'`) with `WorkersScaled::$action`, which is always `'up'` or `'down'`.

### `CapacityCalculationResult`

`ScalingDecision::$capacity`, in `Cbox\LaravelQueueAutoscale\Scaling\DTOs`:

```php
readonly class CapacityCalculationResult
{
    public function __construct(
        public int $maxWorkersByCpu,
        public int $maxWorkersByMemory,
        public int $maxWorkersByConfig,
        public int $finalMaxWorkers,
        public string $limitingFactor,
        public array $details = [],
    ) {}

    public function isCpuLimited(): bool;
    public function isMemoryLimited(): bool;
    public function isConfigLimited(): bool;
    public function getSummary(): string;
    public function getFormattedDetails(): array;
}
```

`limitingFactor` values seen on a decision: `cpu`, `memory`, `balanced`, `config`, `strategy`, `fuse`, `system_metrics_unavailable`.
