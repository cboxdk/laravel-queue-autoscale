<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Output\ConsoleReporter;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Everything that actually changes the worker pool.
 *
 * The manager decides what the pool should look like; this decides nothing and
 * carries those decisions out — spawning, terminating, reaping the dead, and
 * enforcing termination deadlines. It shares the manager's WorkerPool rather
 * than owning one, because the read paths that size the next decision live on
 * the other side of that boundary.
 *
 * Every operation reports what it actually achieved rather than what it was
 * asked for: the spawner drops workers that fail to launch and
 * getTerminatable() skips workers already draining, so trusting the requested
 * count made logs and events describe pool states that were never reached.
 */
class WorkerScaler
{
    /**
     * Human-readable one-line records of this cycle's scaling, surfaced by the
     * console renderer.
     *
     * @var list<string>
     */
    private array $scalingLog = [];

    public function __construct(
        private readonly WorkerPool $pool,
        private readonly WorkerSpawner $spawner,
        private readonly WorkerTerminator $terminator,
        private readonly ConsoleReporter $reporter,
        private readonly AlertRateLimiter $alerts,
        private readonly WorkerOutputBuffer $outputBuffer,
    ) {}

    /**
     * @return list<string>
     */
    public function scalingLog(): array
    {
        return $this->scalingLog;
    }

    public function clearScalingLog(): void
    {
        $this->scalingLog = [];
    }

    public function recordScaling(string $entry): void
    {
        $this->scalingLog[] = $entry;
    }

    /**
     * Trim a spawn request to what the host-wide ceiling still allows.
     *
     * Capacity is enforced per queue, and workers.min is applied AFTER the
     * CPU/memory clamp so a floor always beats measured capacity. That is
     * deliberate for one queue, but queues are DISCOVERED from metrics rather
     * than only read from config: an app with per-tenant queue names presents
     * thousands of queues, each of which is then raised to its floor. Nothing
     * bounded the sum. This is that bound.
     */
    public function clampToHostCeiling(int $requested): int
    {
        $ceiling = AutoscaleConfiguration::maxTotalWorkers();

        if ($ceiling === null || $requested <= 0) {
            return max(0, $requested);
        }

        $headroom = max(0, $ceiling - $this->pool->totalCount());

        if ($headroom >= $requested) {
            return $requested;
        }

        if ($this->alerts->allow('host_ceiling:'.AutoscaleConfiguration::hostLabel())) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Host worker ceiling reached; spawn request trimmed',
                [
                    'ceiling' => $ceiling,
                    'running' => $this->pool->totalCount(),
                    'requested' => $requested,
                    'granted' => $headroom,
                ]
            );
        }

        return $headroom;
    }

    /**
     * Announce departure so the cluster does not keep counting this host.
     *
     * A deliberate stop was previously indistinguishable from a crash: the
     * heartbeat key lived out its TTL and the registry entry survived until
     * another manager pruned it, so the leader distributed work to a host that
     * was already gone — and if this manager WAS the leader, the cluster had
     * no leader until the lease expired.
     *
     * Best-effort by design: shutdown must complete even if Redis is the
     * reason we are shutting down.
     */
    public function scaleUpGroup(GroupConfiguration $group, ScalingDecision $decision): void
    {
        $draining = $this->pool->liveCountGroup($group->connection, $group->name)
            - $this->pool->countGroup($group->connection, $group->name);

        $toAdd = $this->clampToHostCeiling(max($decision->workersToAdd() - $draining, 0));

        if ($toAdd === 0) {
            return;
        }

        $this->reporter->verbose("  ⬆️  Scaling group UP: spawning {$toAdd} worker(s) for [{$group->queueArgument()}]", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] group:%s scaled UP %d -> %d (%s)',
            now()->format('H:i:s'),
            $group->name,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $workers = $this->spawner->spawn(
            $group->connection,
            $group->queueArgument(),
            $toAdd,
            $group->spawnCompensation,
            group: $group->name,
            workerConfig: $group->workers,
        );

        foreach ($workers as $worker) {
            $this->reporter->verbose("     ✓ Group worker spawned: PID {$worker->pid()}", 'info');
        }

        $this->pool->addMany($workers);

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled up group workers',
            [
                'group' => $group->name,
                'queues' => $group->queues,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'added' => $toAdd,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $group->connection,
            queue: $group->name,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
            action: 'up',
            reason: $decision->reason,
        ));
    }

    public function scaleDownGroup(GroupConfiguration $group, ScalingDecision $decision): void
    {
        $toRemove = $decision->workersToRemove();

        $this->reporter->verbose("  ⬇️  Scaling group DOWN: terminating {$toRemove} worker(s) in '{$group->name}'", 'info');

        $workers = $this->pool->getTerminatableFromGroup($group->connection, $group->name, $toRemove);

        foreach ($workers as $worker) {
            $this->terminator->requestTermination($worker);
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled down group workers',
            [
                'group' => $group->name,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'removed' => $toRemove,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $group->connection,
            queue: $group->name,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
            action: 'down',
            reason: $decision->reason,
        ));
    }

    /**
     * Supervise a non-scalable (pinned) queue: maintain the target worker
     * count. In non-cluster mode the target is always pinnedCount(). In
     * cluster mode the leader distributes the pinned count across managers,
     * so the local target may be 0 (not assigned) or pinnedCount() (assigned).
     *
     * Respawns on death, terminates excess. Never evaluates scaling.
     * Still tracks SLA breach state for observability parity.
     */
    public function scaleUp(ScalingDecision $decision): void
    {
        // A worker draining toward exit is invisible to count(), which is
        // right for scale-down and wrong here: it is still a live process
        // still polling the queue, so spawning against the smaller number
        // puts a second worker on a queue that already has one.
        $draining = $this->pool->liveCount($decision->connection, $decision->queue)
            - $this->pool->count($decision->connection, $decision->queue);

        $toAdd = $this->clampToHostCeiling(max($decision->workersToAdd() - $draining, 0));

        if ($toAdd === 0) {
            return;
        }

        $this->reporter->verbose("  ⬆️  Scaling UP: spawning {$toAdd} worker(s)", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] %s:%s scaled UP %d -> %d (%s)',
            now()->format('H:i:s'),
            $decision->connection,
            $decision->queue,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $spawnConfig = $decision->spawnCompensation
            ?? QueueConfiguration::fromConfig($decision->connection, $decision->queue)->spawnCompensation;

        $workers = $this->spawner->spawn(
            $decision->connection,
            $decision->queue,
            $toAdd,
            $spawnConfig,
            workerConfig: QueueConfiguration::fromConfig($decision->connection, $decision->queue)->workers,
        );

        foreach ($workers as $worker) {
            $this->reporter->verbose("     ✓ Worker spawned: PID {$worker->pid()}", 'info');
        }

        $this->pool->addMany($workers);

        // Report what actually started, not what was asked for. The spawner
        // drops workers that fail to launch, so trusting the requested count
        // meant a run where every spawn failed still logged and emitted
        // "scaled 0 -> 5" while the pool gained nothing.
        $spawned = $workers->count();
        $reached = $decision->currentWorkers + $spawned;

        if ($spawned < $toAdd) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Fewer workers started than requested',
                [
                    'connection' => $decision->connection,
                    'queue' => $decision->queue,
                    'requested' => $toAdd,
                    'started' => $spawned,
                ]
            );
        }

        if ($spawned === 0) {
            return;
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled up workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $reached,
                'added' => $spawned,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $reached,
            action: 'up',
            reason: $decision->reason
        ));
    }

    public function scaleDown(ScalingDecision $decision): void
    {
        $toRemove = $decision->workersToRemove();

        $this->reporter->verbose("  ⬇️  Scaling DOWN: terminating {$toRemove} worker(s)", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] %s:%s scaled DOWN %d -> %d (%s)',
            now()->format('H:i:s'),
            $decision->connection,
            $decision->queue,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $workers = $this->pool->getTerminatable(
            $decision->connection,
            $decision->queue,
            $toRemove
        );

        foreach ($workers as $worker) {
            $this->reporter->verbose("     ✓ Requesting worker termination: PID {$worker->pid()}", 'info');
            $this->terminator->requestTermination($worker);
        }

        // Report what was actually terminated. getTerminatable() can return
        // fewer workers than asked for — some may already be draining — and
        // reporting the request instead made the log and the event describe a
        // pool state that was never reached. scaleUp was fixed for exactly
        // this; the down path was missed.
        $removed = $workers->count();
        $reached = $decision->currentWorkers - $removed;

        if ($removed < $toRemove) {
            $this->reporter->verbose(
                "  ⚠️  Only {$removed} of {$toRemove} worker(s) could be terminated; the rest are already draining",
                'warn'
            );
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled down workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $reached,
                'requested' => $decision->targetWorkers,
                'removed' => $removed,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $reached,
            action: 'down',
            reason: $decision->reason
        ));
    }

    public function cleanupDeadWorkers(): void
    {
        $dead = $this->pool->getDeadWorkers();

        if (count($dead) > 0) {
            $this->reporter->verbose('🔧 Cleaning up '.count($dead).' dead worker(s)', 'warn');
        }

        foreach ($dead as $worker) {
            $this->pool->removeWorker($worker);

            // The output buffer keeps a partial-line fragment per PID and
            // nothing ever cleared it, so a long-lived manager accumulated one
            // entry per worker it had ever run — and a recycled PID inherited
            // the previous worker's dangling line.
            $pid = $worker->pid();

            if ($pid !== null) {
                $this->outputBuffer->clearBuffer($pid);
            }

            $this->reporter->verbose("   💀 Removed dead worker: PID {$worker->pid()}", 'warn');

            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Removed dead worker',
                ['pid' => $worker->pid()]
            );
        }
    }

    public function enforceTerminationDeadlines(): void
    {
        foreach ($this->pool->getTerminatingWorkers() as $worker) {
            $this->terminator->forceKillIfExpired($worker);
        }
    }
}
