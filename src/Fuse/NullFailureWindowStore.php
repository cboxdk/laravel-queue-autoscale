<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Fuse;

use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;

/**
 * No-op store used when the fuse is disabled globally.
 *
 * Recording is dropped and the window always reads empty, so FailureFuse never
 * accumulates the min_samples it needs to trip and every queue stays Closed.
 */
final class NullFailureWindowStore implements FailureWindowStoreContract
{
    public function recordOutcome(string $connection, string $queue, bool $failed, int $windowSeconds): void {}

    public function currentWindow(string $connection, string $queue, int $windowSeconds): array
    {
        return ['total' => 0, 'failures' => 0];
    }

    public function resetWindow(string $connection, string $queue, int $windowSeconds): void {}

    public function readState(string $connection, string $queue): ?array
    {
        return null;
    }

    public function writeState(string $connection, string $queue, string $state, float $changedAt): void {}
}
