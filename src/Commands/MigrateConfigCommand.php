<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MigrateConfigCommand extends Command
{
    protected $signature = 'queue-autoscale:migrate-config
                            {--source= : v1 config file path (default: config/queue-autoscale.php)}
                            {--destination= : Output path for v2 config (default: config/queue-autoscale.v2.php)}';

    protected $description = 'Migrate a v1 queue-autoscale config file to v2 shape.';

    public function handle(): int
    {
        $source = $this->option('source') ?: config_path('queue-autoscale.php');
        $destination = $this->option('destination') ?: config_path('queue-autoscale.v2.php');

        if (! is_string($source) || ! is_string($destination)) {
            $this->error('Invalid --source or --destination option.');

            return self::FAILURE;
        }

        if (! File::exists($source)) {
            $this->error("Source file not found: {$source}");

            return self::FAILURE;
        }

        /** @var array<string, mixed> $v1 */
        $v1 = require $source;

        if (! $this->looksLikeV1($v1)) {
            $this->warn('Source does not look like a v1 config. Skipping.');

            return self::SUCCESS;
        }

        $v2 = $this->translate($v1);
        File::put($destination, "<?php\n\nreturn ".var_export($v2, true).";\n");

        $this->info("v2 config written to: {$destination}");
        $this->line('Review it carefully, then replace the original.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function looksLikeV1(array $config): bool
    {
        if (! isset($config['sla_defaults']) || ! is_array($config['sla_defaults'])) {
            return false;
        }

        return array_key_exists('max_pickup_time_seconds', $config['sla_defaults']);
    }

    /**
     * @param  array<string, mixed>  $v1
     * @return array<string, mixed>
     */
    private function translate(array $v1): array
    {
        $oldScaling = is_array($v1['scaling'] ?? null) ? $v1['scaling'] : [];
        $oldSla = is_array($v1['sla_defaults'] ?? null) ? $v1['sla_defaults'] : [];

        return [
            'enabled' => $v1['enabled'] ?? true,
            'manager_id' => $v1['manager_id'] ?? gethostname(),
            'sla_defaults' => BalancedProfile::class,
            'queues' => [],
            'pickup_time' => [
                'store' => RedisPickupTimeStore::class,
                'percentile_calculator' => SortBasedPercentileCalculator::class,
                'max_samples_per_queue' => 1000,
            ],
            'scaling' => [
                'fallback_job_time_seconds' => $oldScaling['fallback_job_time_seconds'] ?? 2.0,
                'breach_threshold' => $oldScaling['breach_threshold'] ?? 0.5,
                'cooldown_seconds' => $oldSla['scale_cooldown_seconds'] ?? 60,
            ],
            'limits' => is_array($v1['limits'] ?? null) ? $v1['limits'] : [],
            'manager' => is_array($v1['manager'] ?? null) ? $v1['manager'] : [],
            'strategy' => HybridStrategy::class,
            'policies' => is_array($v1['policies'] ?? null) ? $v1['policies'] : [],
        ];
    }
}
