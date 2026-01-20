<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugQueueCommand extends Command
{
    public $signature = 'queue:autoscale:debug
                        {--queue=default : Queue name}
                        {--connection=database : Queue connection}';

    public $description = 'Debug queue state by showing raw database/redis contents';

    public function handle(): int
    {
        $queueOpt = $this->option('queue');
        $queue = is_string($queueOpt) ? $queueOpt : 'default';

        $connectionOpt = $this->option('connection');
        $connection = is_string($connectionOpt) ? $connectionOpt : 'database';

        $this->info("Debugging queue: {$connection}:{$queue}");
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
}
