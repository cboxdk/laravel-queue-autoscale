<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\LaravelQueueAutoscale;
use Illuminate\Console\Command;

class ClusterAutoscaleCommand extends Command
{
    public $signature = 'queue:autoscale:cluster
                        {--json : Emit the cluster snapshot as JSON}';

    public $description = 'Show cluster leader, managers, host capacity and workload targets';

    public function handle(LaravelQueueAutoscale $autoscale): int
    {
        if (! AutoscaleConfiguration::clusterEnabled()) {
            $this->warn('Cluster mode is disabled. Set QUEUE_AUTOSCALE_CLUSTER_ENABLED=true and restart queue:autoscale.');

            return self::SUCCESS;
        }

        $summary = $autoscale->cluster();

        if ($summary === []) {
            $this->warn('No cluster summary available yet. Start at least one autoscale manager with cluster mode enabled.');

            return self::SUCCESS;
        }

        if ($this->option('json') === true) {
            $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->line($json);

            return self::SUCCESS;
        }

        $scaleSignal = is_array($summary['scale_signal'] ?? null) ? $summary['scale_signal'] : [];
        $this->info('Queue Autoscale Cluster');
        $this->line('Cluster ID: '.$this->stringValue($summary['cluster_id'] ?? null, 'n/a'));
        $this->line('Leader: '.$this->stringValue($summary['leader_id'] ?? null, 'n/a'));
        $this->line('Managers: '.$this->stringValue($summary['manager_count'] ?? null, '0'));
        $this->line('Workers: '.$this->stringValue($summary['total_workers'] ?? null, '0').' / '.$this->stringValue($summary['total_worker_capacity'] ?? null, '0'));
        $this->line('Required workers: '.$this->stringValue($summary['required_workers'] ?? null, '0'));
        $this->line('Host signal: '.$this->stringValue($scaleSignal['action'] ?? null, 'hold').' (recommended hosts '.$this->stringValue($scaleSignal['recommended_hosts'] ?? null, '0').')');
        $this->line('');

        $managerRows = [];
        $managers = is_iterable($summary['managers'] ?? null) ? $summary['managers'] : [];

        foreach ($managers as $manager) {
            if (! is_array($manager)) {
                continue;
            }

            $managerRows[] = [
                $this->stringValue($manager['manager_id'] ?? null),
                $this->stringValue($manager['host'] ?? null),
                (bool) ($manager['is_leader'] ?? false) ? 'yes' : 'no',
                $this->stringValue($manager['total_workers'] ?? null, '0'),
                $this->stringValue($manager['max_workers'] ?? null, '0'),
                sprintf('%.1f', $this->floatValue($manager['cpu_percent'] ?? 0.0)),
                sprintf('%.1f', $this->floatValue($manager['memory_percent'] ?? 0.0)),
                $this->stringValue($manager['last_seen_human'] ?? null),
            ];
        }

        if ($managerRows !== []) {
            $this->table(
                ['Manager', 'Host', 'Leader', 'Workers', 'Capacity', 'CPU%', 'Mem%', 'Seen'],
                $managerRows,
            );
        }

        $workloadRows = [];
        $workloads = is_iterable($summary['workloads'] ?? null) ? $summary['workloads'] : [];

        foreach ($workloads as $workload) {
            if (! is_array($workload)) {
                continue;
            }

            $workloadRows[] = [
                $this->stringValue($workload['type'] ?? null),
                $this->stringValue($workload['connection'] ?? null),
                $this->stringValue($workload['name'] ?? null),
                $this->stringValue($workload['current_workers'] ?? null, '0'),
                $this->stringValue($workload['target_workers'] ?? null, '0'),
                $this->stringValue($workload['pending'] ?? null, '0'),
                $this->stringValue($workload['oldest_job_age'] ?? null, '0'),
                sprintf('%.2f', $this->floatValue($workload['throughput_per_minute'] ?? 0.0)),
                $this->stringValue($workload['action'] ?? null, 'hold'),
            ];
        }

        if ($workloadRows !== []) {
            $this->table(
                ['Type', 'Conn', 'Name', 'Current', 'Target', 'Pending', 'Oldest Age', 'Throughput/min', 'Action'],
                $workloadRows,
            );
        }

        return self::SUCCESS;
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
