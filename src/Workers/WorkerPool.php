<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Illuminate\Support\Collection;

final class WorkerPool
{
    /** @var Collection<int, WorkerProcess> */
    private Collection $workers;

    public function __construct()
    {
        $this->workers = collect();
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
            fn (WorkerProcess $w) => $w->matches($connection, $queue) && $w->isRunning()
        )->count();
    }

    public function countGroup(string $connection, string $group): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matchesGroup($connection, $group) && $w->isRunning()
        )->count();
    }

    public function totalCount(): int
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->isRunning()
        )->count();
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

    /** @return array<int, WorkerProcess> */
    public function getByConnection(string $connection, string $queue): array
    {
        return $this->workers->filter(
            fn (WorkerProcess $w) => $w->matches($connection, $queue)
        )->values()->all();
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
        $this->workers = collect();
    }
}
