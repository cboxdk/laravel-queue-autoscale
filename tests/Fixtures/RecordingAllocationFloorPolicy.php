<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Fixtures;

use Cbox\LaravelQueueAutoscale\Contracts\AllocationFloorPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * An allocation-floor policy that claims a fixed floor for one named workload
 * and records every consultation, so a test can prove the manager consults it
 * with the right workload identity while the fair-share bounds are built.
 */
class RecordingAllocationFloorPolicy implements AllocationFloorPolicy
{
    /** @var list<string> */
    public static array $seen = [];

    public static ?string $floorForName = null;

    public static int $floor = 0;

    public static function reset(?string $floorForName = null, int $floor = 0): void
    {
        self::$seen = [];
        self::$floorForName = $floorForName;
        self::$floor = $floor;
    }

    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void {}

    public function allocationFloor(string $connection, string $name, bool $isGroup): ?int
    {
        self::$seen[] = ($isGroup ? 'group:' : 'queue:')."{$connection}:{$name}";

        return $name === self::$floorForName ? self::$floor : null;
    }
}
