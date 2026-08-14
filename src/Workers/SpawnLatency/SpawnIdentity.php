<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers\SpawnLatency;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

/**
 * Identifies one spawned worker across the manager/worker boundary.
 *
 * The manager stamps a pending record when it starts a worker; the worker
 * claims it when its first job fires. The two halves run in different
 * processes, so they need a name they can both derive independently.
 *
 * A bare PID is not that name. PIDs are per host and recycled — pid_max is
 * 32768 by default — while the pending record lives in the Redis every host in
 * the cluster shares, for five minutes. Host A spawning pid 12345 and host B
 * spawning pid 12345 two seconds later wrote to one key: B overwrote A's
 * record, A's worker read B's payload and recorded its latency against B's
 * queue, and the Lua delete then left B's own worker with nothing to claim.
 * Spawn compensation inflates worker targets to cover spawn latency, so it was
 * being computed from another queue's numbers. The same collision happens on a
 * single host across manager restarts inside the TTL.
 *
 * Scoping by manager id fixes it, and the worker can reach the same value
 * because the spawner already injects AUTOSCALE_MANAGER_ID into its
 * environment.
 */
class SpawnIdentity
{
    public static function forPid(int $pid): string
    {
        return AutoscaleConfiguration::managerId().':'.$pid;
    }

    /**
     * The identity of the worker process calling this.
     *
     * Falls back to the manager id resolved from configuration when the
     * environment variable is absent — a worker started by hand rather than by
     * the manager. It will simply find no pending record, which is the
     * behaviour that path already had.
     */
    public static function forCurrentProcess(): string
    {
        $managerId = getenv('AUTOSCALE_MANAGER_ID');

        if (! is_string($managerId) || $managerId === '') {
            $managerId = AutoscaleConfiguration::managerId();
        }

        return $managerId.':'.getmypid();
    }
}
