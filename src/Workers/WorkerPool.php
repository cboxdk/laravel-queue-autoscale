<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Illuminate\Support\Collection;

class WorkerPool
{
    /** @var Collection<int, WorkerProcess> */
    private Collection $workers;

    public function __construct()
    {
        $this->workers = new Collection;
    }

    public function add(WorkerProcess $worker): void
    {
        $this->workers->push($worker);
    }

    /**
     * @param  Collection<int, WorkerProcess>  $workers
     */
    public function addMany(Collection $workers): void
    {
        $this->workers = $this->workers->merge($workers);
    }

    public function removeWorker(WorkerProcess $worker): void
    {
        $this->workers = $this->workers->reject(
            fn (WorkerProcess $w) => $w->pid() === $worker->pid()
        );
    }

    /**
     * Remove N workers for a specific connection/queue
     *
     * @return Collection<int, WorkerProcess> Removed workers
     */
    public function remove(string $connection, string $queue, int $count): Collection
    {
        $matching = $this->workers->filter(
            fn (WorkerProcess $w) => $w->matches($connection, $queue)
        );

        $toRemove = $matching->take($count);
        $removePids = $this->pidSet($toRemove);

        $this->workers = $this->workers->reject(
            fn (WorkerProcess $w) => isset($removePids[(string) $w->pid()])
        );

        return $toRemove;
    }

    /**
     * Remove N workers belonging to a specific group.
     *
     * @return Collection<int, WorkerProcess> Removed workers
     */
    public function removeFromGroup(string $connection, string $group, int $count): Collection
    {
        $matching = $this->workers->filter(
            fn (WorkerProcess $w) => $w->matchesGroup($connection, $group)
        );

        $toRemove = $matching->take($count);
        $removePids = $this->pidSet($toRemove);

        $this->workers = $this->workers->reject(
            fn (WorkerProcess $w) => isset($removePids[(string) $w->pid()])
        );

        return $toRemove;
    }

    /**
     * Build a PID lookup set for fast containment checks.
     *
     * Collections of domain objects cannot be compared via contains() on
     * Mockery-wrapped test doubles without triggering recursive equality.
     *
     * @param  Collection<int, WorkerProcess>  $workers
     * @return array<array-key, true>
     */
    private function pidSet(Collection $workers): array
    {
        /** @var array<array-key, true> $set */
        $set = [];

        foreach ($workers as $w) {
            $set[(string) $w->pid()] = true;
        }

        return $set;
    }

    public function count(string $connection, string $queue): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matches($connection, $queue) && $w->isRunning() && ! $w->isTerminating()
        )->count();
    }

    public function countGroup(string $connection, string $group): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matchesGroup($connection, $group) && $w->isRunning() && ! $w->isTerminating()
        )->count();
    }

    /**
     * Workers that still hold a slot, including those draining.
     *
     * `count()` excludes terminating workers so a scale-down is not repeated
     * on the next cycle. That is right for deciding what to remove and wrong
     * for deciding what to add: a draining worker is still a live OS process
     * still consuming memory and — until it exits — still polling its queue.
     * Spawning against the smaller number puts a second worker on a queue that
     * already has one, which for a queue pinned to exactly one worker is the
     * single thing that configuration exists to prevent.
     */
    public function liveCount(string $connection, string $queue): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matches($connection, $queue) && $w->isRunning()
        )->count();
    }

    public function liveCountGroup(string $connection, string $group): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matchesGroup($connection, $group) && $w->isRunning()
        )->count();
    }

    /**
     * Every running worker, draining or not.
     *
     * Used wherever the question is about resources rather than about the
     * scaling target: host ceilings and the cluster heartbeat must count a
     * draining worker, because the machine is still running it.
     */
    public function liveTotalCount(): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->isRunning()
        )->count();
    }

    public function totalCount(): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->isRunning() && ! $w->isTerminating()
        )->count();
    }

    /**
     * @return array<string, int>
     */
    public function queueCounts(): array
    {
        $counts = [];

        foreach ($this->workers as $worker) {
            if (! $worker->isRunning() || $worker->isTerminating() || $worker->isGroupWorker()) {
                continue;
            }

            $key = "{$worker->connection}:{$worker->queue}";
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function groupCounts(): array
    {
        $counts = [];

        foreach ($this->workers as $worker) {
            if (! $worker->isRunning() || $worker->isTerminating() || ! $worker->isGroupWorker() || $worker->group === null) {
                continue;
            }

            $key = "{$worker->connection}:{$worker->group}";
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /** @return Collection<int, WorkerProcess> */
    public function all(): Collection
    {
        return $this->workers;
    }

    /** @return Collection<int, WorkerProcess> */
    public function getDeadWorkers(): Collection
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->isDead()
        );
    }

    /** @return Collection<int, WorkerProcess> */
    public function getTerminatingWorkers(): Collection
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->isTerminating() && $w->isRunning()
        );
    }

    /** @return array<int, WorkerProcess> */
    public function getByConnection(string $connection, string $queue): array
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matches($connection, $queue)
        )->values()->all();
    }

    /** @return Collection<int, WorkerProcess> */
    public function getTerminatable(string $connection, string $queue, int $count): Collection
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matches($connection, $queue) && $w->isRunning() && ! $w->isTerminating()
        )->take($count);
    }

    /** @return Collection<int, WorkerProcess> */
    public function getTerminatableFromGroup(string $connection, string $group, int $count): Collection
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matchesGroup($connection, $group) && $w->isRunning() && ! $w->isTerminating()
        )->take($count);
    }

    /**
     * Find a worker by PID
     */
    public function findByPid(int $pid): ?WorkerProcess
    {
        return $this->workers->first(
            fn (WorkerProcess $w) => $w->pid() === $pid
        );
    }

    public function reset(): void
    {
        $this->workers = new Collection;
    }
}
