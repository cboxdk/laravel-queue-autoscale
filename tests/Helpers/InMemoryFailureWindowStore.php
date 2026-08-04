<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Helpers;

use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;

/**
 * Test double that lets a spec drive the fuse directly: seed a window, seed a
 * state (including a backdated changed_at to expire the cooldown without
 * sleeping), and assert on what the fuse wrote back.
 */
final class InMemoryFailureWindowStore implements FailureWindowStoreContract
{
    public int $total = 0;

    public int $failures = 0;

    /** @var array{state: string, changed_at: float}|null */
    public ?array $state = null;

    public int $resetCount = 0;

    /** @var list<array{connection: string, queue: string, failed: bool, window_seconds: int}> */
    public array $recorded = [];

    public function seedWindow(int $total, int $failures): void
    {
        $this->total = $total;
        $this->failures = $failures;
    }

    public function seedState(string $state, float $changedAt): void
    {
        $this->state = ['state' => $state, 'changed_at' => $changedAt];
    }

    public function recordOutcome(string $connection, string $queue, bool $failed, int $windowSeconds): void
    {
        $this->recorded[] = [
            'connection' => $connection,
            'queue' => $queue,
            'failed' => $failed,
            'window_seconds' => $windowSeconds,
        ];

        $this->total++;

        if ($failed) {
            $this->failures++;
        }
    }

    public function currentWindow(string $connection, string $queue, int $windowSeconds): array
    {
        return ['total' => $this->total, 'failures' => $this->failures];
    }

    public function resetWindow(string $connection, string $queue, int $windowSeconds): void
    {
        $this->resetCount++;
        $this->total = 0;
        $this->failures = 0;
    }

    public function readState(string $connection, string $queue): ?array
    {
        return $this->state;
    }

    public function writeState(string $connection, string $queue, string $state, float $changedAt): void
    {
        $this->state = ['state' => $state, 'changed_at' => $changedAt];
    }
}
