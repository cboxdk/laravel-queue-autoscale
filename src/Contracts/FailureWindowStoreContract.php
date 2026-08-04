<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

/**
 * Shared storage for the failure fuse.
 *
 * Two distinct concerns live here because they have different writers:
 * job outcomes are incremented by every worker process, while fuse state is
 * only ever transitioned by the manager process during an evaluation cycle.
 */
interface FailureWindowStoreContract
{
    /**
     * Record a single job outcome into the current window bucket.
     */
    public function recordOutcome(string $connection, string $queue, bool $failed, int $windowSeconds): void;

    /**
     * Outcome counts over the recent window.
     *
     * @return array{total: int, failures: int}
     */
    public function currentWindow(string $connection, string $queue, int $windowSeconds): array;

    /**
     * Discard recorded outcomes so the next evaluation judges fresh evidence.
     *
     * Called on every fuse state transition — without it, the failures that
     * tripped the fuse would immediately re-trip it after the probe.
     */
    public function resetWindow(string $connection, string $queue, int $windowSeconds): void;

    /**
     * @return array{state: string, changed_at: float}|null Null when no state has been persisted yet.
     */
    public function readState(string $connection, string $queue): ?array;

    public function writeState(string $connection, string $queue, string $state, float $changedAt): void;
}
