<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
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
     * Build the v2 sla_defaults array by starting from BalancedProfile defaults and
     * overlaying the user's v1 sla_defaults values where we know the mapping.
     *
     * Always returns the resolved array because the v1 config always carries explicit
     * sla_defaults keys (that is how looksLikeV1() identifies it).
     *
     * @param  array<string, mixed>  $v1Sla
     * @return array<string, mixed>
     */
    private function buildSlaDefaults(array $v1Sla): array
    {
        $balancedDefaults = (new BalancedProfile)->resolve();

        // Keys we know how to translate
        $knownKeys = ['max_pickup_time_seconds', 'min_workers', 'max_workers', 'scale_cooldown_seconds'];

        // Warn about any v1 keys we cannot map
        foreach (array_keys($v1Sla) as $key) {
            if (! in_array($key, $knownKeys, true)) {
                $this->warn("v1 sla_defaults.{$key} has no direct v2 equivalent and was not migrated.");
            }
        }

        // Apply known mappings on top of BalancedProfile defaults
        $merged = $balancedDefaults;

        if (isset($v1Sla['max_pickup_time_seconds']) && is_numeric($v1Sla['max_pickup_time_seconds'])) {
            $merged['sla']['target_seconds'] = (int) $v1Sla['max_pickup_time_seconds'];
        }

        if (isset($v1Sla['min_workers']) && is_numeric($v1Sla['min_workers'])) {
            $merged['workers']['min'] = (int) $v1Sla['min_workers'];
        }

        if (isset($v1Sla['max_workers']) && is_numeric($v1Sla['max_workers'])) {
            $merged['workers']['max'] = (int) $v1Sla['max_workers'];
        }

        // scale_cooldown_seconds is now a global setting; we handle it in translate().
        // No per-profile key needed here.

        return $merged;
    }

    /**
     * Translate a single v1 queue override array into a v2 partial override array.
     * Only changed keys are included; v2 deep-merges these on top of sla_defaults.
     *
     * @param  array<string, mixed>  $v1Queue
     * @return array<string, mixed>
     */
    private function translateQueueOverride(string $queueName, array $v1Queue): array
    {
        $knownKeys = ['max_pickup_time_seconds', 'min_workers', 'max_workers', 'scale_cooldown_seconds', 'connection'];
        $v2Queue = [];

        foreach (array_keys($v1Queue) as $key) {
            if (! in_array($key, $knownKeys, true)) {
                $this->warn("v1 queues.{$queueName}.{$key} has no direct v2 equivalent and was not migrated.");
            }
        }

        if (isset($v1Queue['connection']) && is_string($v1Queue['connection'])) {
            $v2Queue['connection'] = $v1Queue['connection'];
        }

        if (isset($v1Queue['max_pickup_time_seconds']) && is_numeric($v1Queue['max_pickup_time_seconds'])) {
            $v2Queue['sla']['target_seconds'] = (int) $v1Queue['max_pickup_time_seconds'];
        }

        if (isset($v1Queue['min_workers']) && is_numeric($v1Queue['min_workers'])) {
            $v2Queue['workers']['min'] = (int) $v1Queue['min_workers'];
        }

        if (isset($v1Queue['max_workers']) && is_numeric($v1Queue['max_workers'])) {
            $v2Queue['workers']['max'] = (int) $v1Queue['max_workers'];
        }

        // scale_cooldown_seconds is global in v2; skip silently per-queue.

        return $v2Queue;
    }

    /**
     * @param  array<string, mixed>  $v1
     * @return array<string, mixed>
     */
    private function translate(array $v1): array
    {
        $oldScaling = is_array($v1['scaling'] ?? null) ? $v1['scaling'] : [];
        $oldSla = is_array($v1['sla_defaults'] ?? null) ? $v1['sla_defaults'] : [];

        // Build sla_defaults: resolved array with user values overlaid on BalancedProfile defaults.
        $slaDefaults = $this->buildSlaDefaults($oldSla);

        // Translate per-queue overrides
        $v2Queues = [];
        $v1Queues = is_array($v1['queues'] ?? null) ? $v1['queues'] : [];

        foreach ($v1Queues as $name => $queueConfig) {
            if (is_string($name) && is_array($queueConfig)) {
                /** @var array<string, mixed> $queueConfig */
                $translated = $this->translateQueueOverride($name, $queueConfig);

                if ($translated !== []) {
                    $v2Queues[$name] = $translated;
                }
            }
        }

        // Global cooldown: prefer v1 sla_defaults.scale_cooldown_seconds; fall back to v1 scaling value.
        $cooldownSeconds = 60;

        if (isset($oldSla['scale_cooldown_seconds']) && is_numeric($oldSla['scale_cooldown_seconds'])) {
            $cooldownSeconds = (int) $oldSla['scale_cooldown_seconds'];
        } elseif (isset($oldScaling['cooldown_seconds']) && is_numeric($oldScaling['cooldown_seconds'])) {
            $cooldownSeconds = (int) $oldScaling['cooldown_seconds'];
        }

        return [
            'enabled' => $v1['enabled'] ?? true,
            'manager_id' => $v1['manager_id'] ?? null,
            'sla_defaults' => $slaDefaults,
            'queues' => $v2Queues,
            'excluded' => is_array($v1['excluded'] ?? null) ? $v1['excluded'] : [],
            'groups' => is_array($v1['groups'] ?? null) ? $v1['groups'] : [],
            'pickup_time' => [
                'store' => 'auto',
                'percentile_calculator' => SortBasedPercentileCalculator::class,
                'max_samples_per_queue' => 1000,
            ],
            'spawn_latency' => [
                'tracker' => 'auto',
            ],
            'scaling' => [
                'fallback_job_time_seconds' => $oldScaling['fallback_job_time_seconds'] ?? 2.0,
                'breach_threshold' => $oldScaling['breach_threshold'] ?? 0.5,
                'cooldown_seconds' => $cooldownSeconds,
            ],
            'limits' => is_array($v1['limits'] ?? null) ? $v1['limits'] : [],
            'manager' => is_array($v1['manager'] ?? null) ? $v1['manager'] : [],
            'strategy' => HybridStrategy::class,
            'policies' => is_array($v1['policies'] ?? null) ? $v1['policies'] : [],
        ];
    }
}
