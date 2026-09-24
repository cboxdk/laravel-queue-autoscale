<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Carbon\CarbonInterface;
use Symfony\Component\Process\Process;

class WorkerProcess
{
    private ?CarbonInterface $terminationRequestedAt = null;

    private ?CarbonInterface $terminationDeadline = null;

    /**
     * The OS PID, captured at spawn while the process is running.
     *
     * Symfony's Process::getPid() returns null once the child exits, but an
     * exited worker's PID is still needed to drain its final output and clear
     * its per-PID buffer. Every place that signals a worker guards on
     * isRunning() first, so retaining the PID after exit never risks killing a
     * process the OS has since recycled.
     */
    private ?int $pid;

    /**
     * @param  string  $queue  For per-queue workers this is the queue name; for group workers it is the
     *                         comma-separated queue list exactly as passed to `queue:work --queue=`.
     * @param  string|null  $group  Name of the group this worker belongs to, or null for per-queue workers.
     * @param  int|null  $pid  The PID read at spawn, when the caller already has it. Symfony's
     *                         getPid() answers null for a child that has been reaped, so deriving
     *                         it here instead loses the PID of a worker that died between the
     *                         spawner's liveness check and this constructor — and a pooled worker
     *                         with no PID can never be drained, signalled or reaped.
     */
    public function __construct(
        public readonly Process $process,
        public readonly string $connection,
        public readonly string $queue,
        public readonly CarbonInterface $spawnedAt,
        public readonly ?string $group = null,
        ?int $pid = null,
    ) {
        $this->pid = $pid ?? $process->getPid();
    }

    public function pid(): ?int
    {
        $livePid = $this->process->getPid();

        if ($livePid !== null) {
            $this->pid = $livePid;
        }

        return $this->pid;
    }

    /**
     * Whether the OS process is still alive.
     *
     * Impure by nature: the answer changes as the child exits, independently
     * of anything this program does. Without the annotation, static analysis
     * caches the first result and concludes that the SIGTERM-wait-SIGKILL loop
     * in WorkerTerminator is unreachable — which is how three suppressions for
     * a live code path ended up in a baseline.
     *
     * @phpstan-impure
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /** @phpstan-impure */
    public function isDead(): bool
    {
        return ! $this->process->isRunning();
    }

    public function isTerminating(): bool
    {
        return $this->terminationRequestedAt !== null;
    }

    public function markTerminationRequested(CarbonInterface $requestedAt, int $timeoutSeconds): void
    {
        $this->terminationRequestedAt = $requestedAt;
        $this->terminationDeadline = $requestedAt->copy()->addSeconds(max($timeoutSeconds, 0));
    }

    public function terminationDeadlinePassed(CarbonInterface $now): bool
    {
        return $this->terminationDeadline !== null && $now->greaterThanOrEqualTo($this->terminationDeadline);
    }

    public function uptimeSeconds(): int
    {
        return (int) $this->spawnedAt->diffInSeconds(now());
    }

    /**
     * Matches a per-queue worker. Group workers are matched via matchesGroup().
     */
    public function matches(string $connection, string $queue): bool
    {
        return $this->group === null
            && $this->connection === $connection
            && $this->queue === $queue;
    }

    public function matchesGroup(string $connection, string $group): bool
    {
        return $this->group === $group && $this->connection === $connection;
    }

    public function isGroupWorker(): bool
    {
        return $this->group !== null;
    }

    public function getIncrementalOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    public function getIncrementalErrorOutput(): string
    {
        return $this->process->getIncrementalErrorOutput();
    }

    /**
     * Drop the process's retained stdout history.
     *
     * Symfony Process keeps everything a child ever wrote for the life of
     * the process; clearing after each incremental read is what stops a
     * long-lived worker's output living on inside the manager.
     */
    public function clearOutput(): void
    {
        $this->process->clearOutput();
    }

    public function clearErrorOutput(): void
    {
        $this->process->clearErrorOutput();
    }
}
