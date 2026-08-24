<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Illuminate\Support\Facades\Log;

/**
 * Finds and terminates workers left behind by a previous manager generation.
 *
 * The worker pool is process-local state: when a manager dies abruptly (the
 * kernel OOM killer, a crashed runtime), its queue:work children reparent to
 * PID 1 and keep running, invisible to the replacement manager. The
 * replacement counts zero workers and spawns a full new set on top of the
 * orphans, and under memory pressure each doubled generation makes the next
 * OOM kill more likely.
 *
 * Workers are recognised by the environment markers WorkerSpawner stamps on
 * every child, and only workers stamped with THIS manager's id are touched:
 * the id derives deterministically from host and container identity, so a
 * supervisor-restarted manager matches its predecessor's workers while a
 * second manager deliberately running on the same host does not.
 */
class OrphanedWorkerReaper
{
    public function __construct(
        private readonly string $procPath = '/proc',
    ) {}

    /**
     * SIGTERM every orphan and report how many were signalled.
     *
     * SIGTERM rather than SIGKILL: queue:work finishes its current job and
     * exits, so the overlap with freshly spawned workers is bounded by one
     * job's duration instead of --max-time.
     */
    public function reap(string $managerId): int
    {
        $signalled = [];

        foreach ($this->findOrphans($managerId) as $pid) {
            if (posix_kill($pid, SIGTERM)) {
                $signalled[] = $pid;
            }
        }

        if ($signalled !== []) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Terminated orphaned workers from a previous manager generation',
                [
                    'manager_id' => $managerId,
                    'count' => count($signalled),
                    'pids' => $signalled,
                ]
            );
        }

        return count($signalled);
    }

    /**
     * PIDs stamped with this package's worker markers and the given manager id.
     *
     * @return array<int, int>
     */
    private function findOrphans(string $managerId): array
    {
        if (! is_dir($this->procPath)) {
            // Not a procfs host (e.g. macOS development): nothing to scan,
            // and outside a container an abruptly dead manager is far rarer
            // than under a memory-limited PID 1.
            Log::channel(AutoscaleConfiguration::logChannel())->debug(
                'Orphaned worker scan skipped; procfs is not available on this host'
            );

            return [];
        }

        $pids = [];
        $ownPid = getmypid();

        foreach (glob("{$this->procPath}/[0-9]*", GLOB_ONLYDIR) ?: [] as $dir) {
            $pid = (int) basename($dir);

            if ($pid === $ownPid) {
                continue;
            }

            // environ is only readable for same-user processes; anything
            // else is by definition not one of our workers.
            $environ = @file_get_contents("{$dir}/environ");

            if ($environ === false || $environ === '') {
                continue;
            }

            $env = $this->parseEnviron($environ);

            if (($env['LARAVEL_AUTOSCALE_WORKER'] ?? null) !== 'true') {
                continue;
            }

            if (($env['AUTOSCALE_MANAGER_ID'] ?? null) !== $managerId) {
                continue;
            }

            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * @return array<string, string>
     */
    private function parseEnviron(string $environ): array
    {
        $env = [];

        foreach (explode("\0", $environ) as $entry) {
            if ($entry === '' || ! str_contains($entry, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $entry, 2);
            $env[$name] = $value;
        }

        return $env;
    }
}
