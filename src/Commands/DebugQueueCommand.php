<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Fuse\FuseState;
use Cbox\LaravelQueueAutoscale\Fuse\NullFailureWindowStore;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugQueueCommand extends Command
{
    public $signature = 'queue:autoscale:debug
                        {--queue=default : Queue name}
                        {--connection= : Queue connection (defaults to queue.default config)}';

    public $description = 'Debug queue state by showing raw database/redis contents';

    public function handle(): int
    {
        $queueOpt = $this->option('queue');
        $queue = is_string($queueOpt) ? $queueOpt : 'default';

        $connectionOpt = $this->option('connection');
        $defaultConnection = config('queue.default');
        $fallback = is_string($defaultConnection) ? $defaultConnection : 'database';
        $connection = is_string($connectionOpt) && $connectionOpt !== '' ? $connectionOpt : $fallback;

        $this->info("Debugging queue: {$connection}:{$queue}");
        $this->line('');

        $this->line('Telemetry: '.$this->describeTelemetryIntegration());
        $this->line('');

        // Get queue depth from metrics package
        $this->info('=== QueueMetrics::getQueueDepth() ===');
        try {
            $depth = QueueMetrics::getQueueDepth($connection, $queue);
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Pending', (string) $depth->pendingJobs],
                    ['Reserved', (string) $depth->reservedJobs],
                    ['Delayed', (string) $depth->delayedJobs],
                    ['Total', (string) $depth->totalJobs()],
                    ['Oldest Pending Age', $depth->oldestPendingJobAge?->diffForHumans() ?? 'N/A'],
                    ['Measured At', $depth->measuredAt->toDateTimeString()],
                ]
            );
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
        }

        $this->line('');
        $this->info('=== Failure Fuse ===');
        $this->debugFuse($connection, $queue);

        // Check driver type
        $driver = config("queue.connections.{$connection}.driver");
        $driverStr = is_string($driver) ? $driver : 'unknown';
        $this->line('');
        $this->info("=== Raw Queue Data (driver: {$driverStr}) ===");

        if ($driverStr === 'database') {
            $this->debugDatabaseQueue($connection, $queue);
        } elseif ($driverStr === 'redis') {
            $this->debugRedisQueue($connection, $queue);
        } else {
            $this->warn("Unknown driver: {$driverStr}");
        }

        return self::SUCCESS;
    }

    /**
     * Answers "why is this queue stuck at workers.min?" — the fuse holding a
     * queue back is otherwise only visible in the manager's own log output,
     * which is the wrong place to look during an incident.
     */
    private function debugFuse(string $connection, string $queue): void
    {
        try {
            $config = QueueConfiguration::fromConfig($connection, $queue);
        } catch (\Throwable $e) {
            $this->error("Failed to resolve queue configuration: {$e->getMessage()}");

            return;
        }

        $fuse = $config->fuse;

        if (! $fuse->enabled) {
            $this->line('Disabled for this queue — scaling is never held back by failure rate.');

            return;
        }

        $store = $this->laravel->make(FailureWindowStoreContract::class);

        if ($store instanceof NullFailureWindowStore) {
            $this->warn('Outcome tracking is disabled (fuse.store), so the fuse can never trip.');

            return;
        }

        try {
            $window = $store->currentWindow($connection, $queue, $fuse->windowSeconds);
            $state = $store->readState($connection, $queue);
        } catch (\Throwable $e) {
            // A diagnostic command must never be the thing that breaks. An
            // unreachable cache is itself the finding here: it means the fuse
            // cannot see outcomes either.
            $this->error("Failed to read fuse state: {$e->getMessage()}");
            $this->warn('The fuse cannot function while its store is unreachable — check the cache backend.');

            return;
        }

        $rate = $window['total'] > 0 ? ($window['failures'] / $window['total']) * 100.0 : 0.0;
        $stateName = $state['state'] ?? FuseState::Closed->value;

        $this->table(
            ['Metric', 'Value'],
            [
                ['State', $stateName],
                ['Failure rate', sprintf('%.1f%% (%d of %d jobs)', $rate, $window['failures'], $window['total'])],
                ['Trips at', sprintf('%.1f%% over >= %d jobs', $fuse->failureThresholdPercent, $fuse->minSamples)],
                ['Window', "{$fuse->windowSeconds}s"],
                ['Cooldown', "{$fuse->cooldownSeconds}s"],
                ['Worker range', "{$config->workers->min} - {$config->workers->max}"],
            ]
        );

        match ($stateName) {
            FuseState::Open->value => $this->warn(
                "TRIPPED: scaling is held at {$config->workers->min} worker(s). ".
                "A probe runs automatically after {$fuse->cooldownSeconds}s."
            ),
            FuseState::HalfOpen->value => $this->warn(
                'PROBING: running at the minimum worker count to test whether the dependency recovered.'
            ),
            default => $window['total'] < $fuse->minSamples
                ? $this->line("Closed. Not enough samples yet to evaluate ({$window['total']} of {$fuse->minSamples}).")
                : $this->line('Closed. Scaling is unconstrained by the fuse.'),
        };

        if ($this->cacheDriverIsPerProcess()) {
            $this->warn(
                'The "array" cache driver is in use: worker processes and the manager do not share '.
                'state, so the fuse cannot see recorded outcomes. Use a shared cache driver.'
            );
        }
    }

    /**
     * The array driver keeps everything in one process's memory, which makes
     * the fuse silently inert in production.
     */
    private function cacheDriverIsPerProcess(): bool
    {
        $store = config('queue-autoscale.fuse.store', 'auto');
        $usesCache = ! is_string($store) || in_array(trim($store), ['', 'auto', 'cache'], true);

        return $usesCache && config('cache.default') === 'array';
    }

    private function debugDatabaseQueue(string $connection, string $queue): void
    {
        $tableConfig = config("queue.connections.{$connection}.table", 'jobs');
        $table = is_string($tableConfig) ? $tableConfig : 'jobs';
        $this->line("Table: {$table}");
        $this->line('');

        // Count by state
        $pending = DB::table($table)
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->timestamp)
            ->count();

        $reserved = DB::table($table)
            ->where('queue', $queue)
            ->whereNotNull('reserved_at')
            ->count();

        $delayed = DB::table($table)
            ->where('queue', $queue)
            ->where('available_at', '>', now()->timestamp)
            ->count();

        $this->table(
            ['State', 'Count'],
            [
                ['Pending (available now)', (string) $pending],
                ['Reserved (being processed)', (string) $reserved],
                ['Delayed (scheduled)', (string) $delayed],
            ]
        );

        // Show reserved jobs details
        if ($reserved > 0) {
            $this->line('');
            $this->info('=== Reserved Jobs Details ===');

            $reservedJobs = DB::table($table)
                ->where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->select(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->limit(10)
                ->get();

            $rows = [];
            foreach ($reservedJobs as $job) {
                $reservedAt = is_numeric($job->reserved_at) ? (int) $job->reserved_at : 0;
                $createdAt = is_numeric($job->created_at) ? (int) $job->created_at : 0;
                $reservedAgo = (int) now()->timestamp - $reservedAt;
                $rows[] = [
                    (string) $job->id,
                    (string) $job->attempts,
                    "{$reservedAgo}s ago",
                    date('H:i:s', $createdAt),
                ];
            }

            $this->table(['ID', 'Attempts', 'Reserved', 'Created'], $rows);

            // Check for stale reserved jobs
            $retryAfterConfig = config("queue.connections.{$connection}.retry_after", 90);
            $retryAfter = is_numeric($retryAfterConfig) ? (int) $retryAfterConfig : 90;
            $staleThreshold = (int) now()->timestamp - $retryAfter;

            $staleCount = DB::table($table)
                ->where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<', $staleThreshold)
                ->count();

            if ($staleCount > 0) {
                $this->warn("⚠️  {$staleCount} stale reserved jobs (reserved > {$retryAfter}s ago)");
                $this->line("   These may be from crashed workers. Run 'php artisan queue:restart' to release them.");
            }
        }

        // Total row count
        $total = DB::table($table)->where('queue', $queue)->count();
        $this->line('');
        $this->line("Total rows in {$table} for queue '{$queue}': {$total}");
    }

    private function debugRedisQueue(string $connection, string $queue): void
    {
        $prefixConfig = config("queue.connections.{$connection}.prefix", 'queues');
        $prefix = is_string($prefixConfig) ? $prefixConfig : 'queues';

        $redisConnection = config("queue.connections.{$connection}.connection", 'default');
        $redisConnectionStr = is_string($redisConnection) ? $redisConnection : 'default';

        $redis = app('redis')->connection($redisConnectionStr);

        $pendingKey = "{$prefix}:{$queue}";
        $reservedKey = "{$prefix}:{$queue}:reserved";
        $delayedKey = "{$prefix}:{$queue}:delayed";

        $this->table(
            ['Key', 'Type', 'Count'],
            [
                [$pendingKey, 'LIST', (string) $redis->llen($pendingKey)],
                [$reservedKey, 'ZSET', (string) $redis->zcard($reservedKey)],
                [$delayedKey, 'ZSET', (string) $redis->zcard($delayedKey)],
            ]
        );
    }

    private function describeTelemetryIntegration(): string
    {
        if (! class_exists(TelemetryManager::class)) {
            return 'not installed (composer require cboxdk/laravel-telemetry)';
        }

        if (! $this->laravel->bound(TelemetryManager::class)) {
            return 'installed but not booted (telemetry service provider not registered)';
        }

        if (! config('queue-autoscale.telemetry.enabled', true)) {
            return 'disabled (queue-autoscale.telemetry.enabled)';
        }

        if (! config('telemetry.enabled', true)) {
            return 'inactive (telemetry.enabled is false)';
        }

        return 'active';
    }
}
