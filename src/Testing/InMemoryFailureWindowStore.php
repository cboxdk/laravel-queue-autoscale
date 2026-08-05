<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Testing;

use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;

/**
 * Test double that lets a spec drive the fuse directly: seed a window, seed a
 * state (including a backdated changed_at to expire the cooldown without
 * sleeping), and assert on what the fuse wrote back.
 */
class InMemoryFailureWindowStore implements FailureWindowStoreContract
{
    /**
     * Counters and state are keyed by (connection, queue), exactly as the real
     * store is. An earlier version of this double ignored both arguments and
     * kept a single global counter — which meant the whole fuse suite passed
     * whether or not FailureFuse read the right key, and is how the fuse being
     * permanently inert for queue groups stayed invisible.
     *
     * @var array<string, array{total: int, failures: int}>
     */
    private array $windows = [];

    /** @var array<string, array{state: string, changed_at: float}> */
    private array $states = [];

    public int $resetCount = 0;

    /** @var list<array{connection: string, queue: string, failed: bool, window_seconds: int}> */
    public array $recorded = [];

    /**
     * Seeds the default (redis, default) window used by most specs. Pass a
     * connection and queue to seed a specific one.
     */
    public function seedWindow(int $total, int $failures, string $connection = 'redis', string $queue = 'default'): void
    {
        $this->windows[$this->key($connection, $queue)] = ['total' => $total, 'failures' => $failures];
    }

    public function seedState(string $state, float $changedAt, string $connection = 'redis', string $queue = 'default'): void
    {
        $this->states[$this->key($connection, $queue)] = ['state' => $state, 'changed_at' => $changedAt];
    }

    public function recordOutcome(string $connection, string $queue, bool $failed, int $windowSeconds): void
    {
        $this->recorded[] = [
            'connection' => $connection,
            'queue' => $queue,
            'failed' => $failed,
            'window_seconds' => $windowSeconds,
        ];

        $key = $this->key($connection, $queue);
        $window = $this->windows[$key] ?? ['total' => 0, 'failures' => 0];
        $window['total']++;

        if ($failed) {
            $window['failures']++;
        }

        $this->windows[$key] = $window;
    }

    public function currentWindow(string $connection, string $queue, int $windowSeconds): array
    {
        return $this->windows[$this->key($connection, $queue)] ?? ['total' => 0, 'failures' => 0];
    }

    public function resetWindow(string $connection, string $queue, int $windowSeconds): void
    {
        $this->resetCount++;
        unset($this->windows[$this->key($connection, $queue)]);
    }

    public function readState(string $connection, string $queue): ?array
    {
        return $this->states[$this->key($connection, $queue)] ?? null;
    }

    public function writeState(string $connection, string $queue, string $state, float $changedAt): void
    {
        $this->states[$this->key($connection, $queue)] = ['state' => $state, 'changed_at' => $changedAt];
    }

    private function key(string $connection, string $queue): string
    {
        return "{$connection}\0{$queue}";
    }
}
