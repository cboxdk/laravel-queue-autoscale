---
title: "Workers"
description: "The worker pool, spawner, terminator and the scaler that changes the pool"
weight: 50
---

## Workers

### `WorkerProcess`

A live `queue:work` subprocess wrapped with spawn metadata.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerProcess
{
    public function __construct(
        public readonly Process $process,
        public readonly string $connection,
        public readonly string $queue,         // singular name OR comma-separated for group workers
        public readonly Carbon $spawnedAt,
        public readonly ?string $group = null,
    ) {}

    public function pid(): ?int;
    public function isRunning(): bool;
    public function isDead(): bool;
    public function isTerminating(): bool;
    public function markTerminationRequested(Carbon $requestedAt, int $timeoutSeconds): void;
    public function terminationDeadlinePassed(Carbon $now): bool;
    public function uptimeSeconds(): int;
    public function matches(string $connection, string $queue): bool;        // false for group workers
    public function matchesGroup(string $connection, string $group): bool;
    public function isGroupWorker(): bool;
    public function getIncrementalOutput(): string;
    public function getIncrementalErrorOutput(): string;
}
```

### `WorkerPool`

Collection wrapper over `WorkerProcess`, held in-process by the manager daemon. A web request cannot see it.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerPool
{
    public function add(WorkerProcess $worker): void;
    public function addMany(Collection $workers): void;
    public function removeWorker(WorkerProcess $worker): void;
    public function remove(string $connection, string $queue, int $count): Collection;
    public function removeFromGroup(string $connection, string $group, int $count): Collection;

    public function count(string $connection, string $queue): int;
    public function countGroup(string $connection, string $group): int;
    public function totalCount(): int;
    public function queueCounts(): array;
    public function groupCounts(): array;

    public function all(): Collection;
    public function getDeadWorkers(): Collection;
    public function getTerminatingWorkers(): Collection;
    public function getByConnection(string $connection, string $queue): array;
    public function getTerminatable(string $connection, string $queue, int $count): Collection;
    public function getTerminatableFromGroup(string $connection, string $group, int $count): Collection;
    public function findByPid(int $pid): ?WorkerProcess;
    public function reset(): void;
}
```

There is no `getWorkerCount()` — use `count($connection, $queue)`, `countGroup()`, `totalCount()`, `queueCounts()` or `groupCounts()`.

### `WorkerScaler`

Everything that actually changes the pool. The manager decides what the pool should
look like; the scaler carries it out, and every method reports what it achieved rather
than what it was asked for — the spawner drops workers that fail to launch, and
`getTerminatable()` skips workers already draining.

```php
namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerScaler
{
    public function scaleUp(ScalingDecision $decision): void;
    public function scaleDown(ScalingDecision $decision): void;
    public function scaleUpGroup(GroupConfiguration $group, ScalingDecision $decision): void;
    public function scaleDownGroup(GroupConfiguration $group, ScalingDecision $decision): void;

    /** Trim a spawn request to the host's remaining `limits.max_total_workers` headroom. */
    public function clampToHostCeiling(int $requested): int;

    public function cleanupDeadWorkers(): void;
    public function enforceTerminationDeadlines(): void;

    /** @return list<string> */
    public function scalingLog(): array;
}
```

It shares the manager's `WorkerPool` rather than owning one, so counts read elsewhere
in a cycle and counts changed here are always the same pool.

### `WorkerSpawner`

Spawns `queue:work` subprocesses. The command it builds is exactly:

```bash
{PHP_BINARY} artisan queue:work {connection} \
    --queue={queue} \
    --tries={workers.tries} \
    --max-time={workers.max_time_seconds} \
    --timeout={workers.timeout_seconds} \
    --sleep={workers.sleep_seconds}
```

Every value comes from the queue's resolved `WorkerConfiguration`. `--memory` is never passed.

The only environment variables injected into a worker are:

```text
LARAVEL_AUTOSCALE_WORKER=true
AUTOSCALE_MANAGER_ID=<manager id>
AUTOSCALE_WORKER_GROUP=<group name>   # group workers only
```
