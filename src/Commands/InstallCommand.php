<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class InstallCommand extends Command
{
    protected $signature = 'queue:autoscale:install
                            {--topology= : Installation preset: single-low, single-redis, or cluster}
                            {--metrics-connection= : Metrics backend connection name}
                            {--publish-migrations : Publish laravel-queue-metrics database migrations}
                            {--write-env : Write the recommended values into the env file}
                            {--env-file= : Env file path to update (default: base_path(".env"))}
                            {--force : Overwrite published config files when they already exist}
                            {--no-publish : Skip vendor:publish steps}';

    protected $description = 'Interactive install guide for queue autoscale and queue metrics.';

    /**
     * @var array<string, array{
     *     label: string,
     *     metrics_driver: string,
     *     cluster_enabled: bool,
     *     pickup_store: string,
     *     spawn_tracker: string,
     *     publish_migrations: bool,
     *     connection_default: string,
     *     next_steps: array<int, string>,
     *     notes: array<int, string>
     * }>
     */
    private const PRESETS = [
        'single-low' => [
            'label' => 'Single host, low traffic, no Redis infrastructure',
            'metrics_driver' => 'database',
            'cluster_enabled' => false,
            'pickup_store' => 'auto',
            'spawn_tracker' => 'auto',
            'publish_migrations' => true,
            'connection_default' => 'database',
            'next_steps' => [
                'Run `php artisan migrate` before starting the autoscaler.',
                'Run a single `php artisan queue:autoscale` process on the host.',
            ],
            'notes' => [
                'Database metrics storage is intended for lower-traffic environments.',
                'Pickup-time and spawn-latency signals stay local/no-op unless you explicitly switch them to Redis later.',
            ],
        ],
        'single-redis' => [
            'label' => 'Single host with Redis-backed metrics and predictive signals',
            'metrics_driver' => 'redis',
            'cluster_enabled' => false,
            'pickup_store' => 'redis',
            'spawn_tracker' => 'redis',
            'publish_migrations' => false,
            'connection_default' => 'redis',
            'next_steps' => [
                'Run a single `php artisan queue:autoscale` process on the host.',
            ],
            'notes' => [
                'Redis stores queue metrics and the shared predictive-signal history.',
            ],
        ],
        'cluster' => [
            'label' => 'Multi-host cluster with Redis coordination',
            'metrics_driver' => 'redis',
            'cluster_enabled' => true,
            'pickup_store' => 'auto',
            'spawn_tracker' => 'auto',
            'publish_migrations' => false,
            'connection_default' => 'redis',
            'next_steps' => [
                'Run one `php artisan queue:autoscale` process per host or pod.',
                'Use `php artisan queue:autoscale:cluster` to inspect leader, members, and capacity distribution.',
            ],
            'notes' => [
                'Cluster mode requires Redis and auto-joins managers across hosts.',
                'One autoscale manager per host is still enforced by the local host lock.',
            ],
        ],
    ];

    public function handle(): int
    {
        $presetKey = $this->resolvePresetKey();
        if ($presetKey === null) {
            return self::FAILURE;
        }

        $preset = self::PRESETS[$presetKey];

        $metricsConnection = $this->resolveMetricsConnection($preset['metrics_driver'], $preset['connection_default']);
        if ($metricsConnection === '') {
            $this->error('A metrics connection name is required.');

            return self::FAILURE;
        }

        if (! $this->option('no-publish')) {
            if (! $this->publishConfigs($this->option('force') === true)) {
                return self::FAILURE;
            }
        }

        $publishMigrations = ! $this->option('no-publish') && $this->shouldPublishMigrations($preset['publish_migrations']);
        if ($publishMigrations && ! $this->publishMetricsMigrations($this->option('force') === true)) {
            return self::FAILURE;
        }

        $env = $this->buildEnvBlock(
            presetKey: $presetKey,
            metricsDriver: $preset['metrics_driver'],
            metricsConnection: $metricsConnection,
            clusterEnabled: $preset['cluster_enabled'],
            pickupStore: $preset['pickup_store'],
            spawnTracker: $preset['spawn_tracker'],
        );

        $this->newLine();
        $this->info('Queue Autoscale install preset selected');
        $this->line('   Preset: '.$preset['label']);
        $this->line('   Metrics storage: '.$preset['metrics_driver']);
        $this->line('   Metrics connection: '.$metricsConnection);
        $this->line('   Cluster mode: '.($preset['cluster_enabled'] ? 'enabled' : 'disabled'));

        foreach ($preset['notes'] as $note) {
            $this->line('   Note: '.$note);
        }

        $this->newLine();
        $this->line('Recommended .env values:');
        foreach ($env as $key => $value) {
            $this->line($key.'='.$value);
        }

        if ($this->shouldWriteEnv($env)) {
            $envFile = $this->resolveEnvFilePath();
            $this->writeEnvFile($envFile, $env);
            $this->callSilent('config:clear');

            $this->newLine();
            $this->info("Updated env file: {$envFile}");
            $this->line('Cleared the config cache so the new values are picked up immediately.');
        }

        $this->newLine();
        $this->info('Next steps');
        foreach ($preset['next_steps'] as $step) {
            $this->line(' - '.$step);
        }

        if ($publishMigrations) {
            $this->line(' - Queue metrics migrations were published for database storage.');
        }

        return self::SUCCESS;
    }

    private function resolvePresetKey(): ?string
    {
        $configured = $this->option('topology');

        if (is_string($configured) && $configured !== '') {
            $normalized = strtolower($configured);
            if (! array_key_exists($normalized, self::PRESETS)) {
                $this->error('Invalid --topology value. Supported values: single-low, single-redis, cluster.');

                return null;
            }

            return $normalized;
        }

        if (! $this->input->isInteractive()) {
            return 'single-low';
        }

        $labels = [];
        foreach (self::PRESETS as $key => $preset) {
            $labels[$preset['label']] = $key;
        }

        $selected = $this->choice(
            'Which deployment shape are you installing?',
            array_keys($labels),
            0,
        );

        if (! is_string($selected)) {
            return null;
        }

        return $labels[$selected] ?? null;
    }

    private function resolveMetricsConnection(string $metricsDriver, string $connectionDefault): string
    {
        $configured = $this->option('metrics-connection');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (! $this->input->isInteractive()) {
            return $this->defaultConnectionName($connectionDefault);
        }

        $default = $this->defaultConnectionName($connectionDefault);
        $label = $metricsDriver === 'database'
            ? 'Which database connection should queue metrics use?'
            : 'Which Redis connection should queue metrics use?';

        /** @var string $answer */
        $answer = $this->ask($label, $default);

        return $answer;
    }

    private function defaultConnectionName(string $connectionDefault): string
    {
        if ($connectionDefault === 'database') {
            $databaseDefault = config('database.default', 'mysql');

            return is_string($databaseDefault) && $databaseDefault !== ''
                ? $databaseDefault
                : 'mysql';
        }

        $metricsDefault = config('queue-metrics.storage.connection', 'default');

        return is_string($metricsDefault) && $metricsDefault !== ''
            ? $metricsDefault
            : 'default';
    }

    private function shouldPublishMigrations(bool $default): bool
    {
        if (! $default) {
            return $this->option('publish-migrations') === true;
        }

        if ($this->option('publish-migrations') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm(
            'Publish queue-metrics database migrations now?',
            true,
        );
    }

    /**
     * @param  array<string, string>  $env
     */
    private function shouldWriteEnv(array $env): bool
    {
        if ($this->option('write-env') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        $envFile = $this->resolveEnvFilePath();

        $this->newLine();
        $this->line("Planned changes for {$envFile}:");
        foreach ($this->describeEnvChanges($envFile, $env) as $change) {
            $this->line($change);
        }

        return $this->confirm("Apply these changes to {$envFile}?", true);
    }

    private function resolveEnvFilePath(): string
    {
        $configured = $this->option('env-file');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return base_path('.env');
    }

    /**
     * @return array<string, string>
     */
    private function buildEnvBlock(
        string $presetKey,
        string $metricsDriver,
        string $metricsConnection,
        bool $clusterEnabled,
        string $pickupStore,
        string $spawnTracker,
    ): array {
        $env = [
            'QUEUE_AUTOSCALE_ENABLED' => 'true',
            'QUEUE_AUTOSCALE_CLUSTER_ENABLED' => $clusterEnabled ? 'true' : 'false',
            'QUEUE_AUTOSCALE_PICKUP_TIME_STORE' => $pickupStore,
            'QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER' => $spawnTracker,
            'QUEUE_METRICS_STORAGE' => $metricsDriver,
            'QUEUE_METRICS_CONNECTION' => $metricsConnection,
        ];

        if ($presetKey === 'single-low') {
            $env['QUEUE_METRICS_MAX_SAMPLES'] = '500';
        }

        return $env;
    }

    private function publishConfigs(bool $force): bool
    {
        $this->info('Publishing config files...');

        $configArgs = ['--tag' => 'queue-autoscale-config'];
        if ($force) {
            $configArgs['--force'] = true;
        }

        if ($this->call('vendor:publish', $configArgs) !== self::SUCCESS) {
            $this->error('Failed to publish queue-autoscale config.');

            return false;
        }

        $metricsArgs = ['--tag' => 'queue-metrics-config'];
        if ($force) {
            $metricsArgs['--force'] = true;
        }

        if ($this->call('vendor:publish', $metricsArgs) !== self::SUCCESS) {
            $this->error('Failed to publish queue-metrics config.');

            return false;
        }

        return true;
    }

    private function publishMetricsMigrations(bool $force): bool
    {
        $this->info('Publishing queue-metrics database migrations...');

        $args = ['--tag' => 'laravel-queue-metrics-migrations'];
        if ($force) {
            $args['--force'] = true;
        }

        if ($this->call('vendor:publish', $args) !== self::SUCCESS) {
            $this->error('Failed to publish queue-metrics migrations.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function writeEnvFile(string $path, array $env): void
    {
        $contents = $this->loadEnvContents($path);

        foreach ($env as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $replacement = $key.'='.$value;

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $replacement, $contents, 1);

                continue;
            }

            $contents = rtrim($contents, "\n").PHP_EOL.$replacement.PHP_EOL;
        }

        $normalized = ltrim($contents, PHP_EOL);
        $tempPath = $path.'.tmp.'.bin2hex(random_bytes(8));

        File::put($tempPath, $normalized);

        if (! rename($tempPath, $path)) {
            @unlink($tempPath);

            throw new \RuntimeException("Failed to move temporary env file into place: {$path}");
        }
    }

    /**
     * @param  array<string, string>  $env
     * @return array<int, string>
     */
    private function describeEnvChanges(string $path, array $env): array
    {
        $contents = $this->loadEnvContents($path);
        $changes = [];

        foreach ($env as $key => $value) {
            $current = $this->extractEnvValue($contents, $key);

            if ($current === null) {
                $changes[] = '+ '.$key.'='.$value;

                continue;
            }

            if ($current === $value) {
                $changes[] = '= '.$key.' is already set correctly';

                continue;
            }

            $changes[] = '~ '.$key.': '.$current.' -> '.$value;
        }

        return $changes;
    }

    private function loadEnvContents(string $path): string
    {
        if (File::exists($path)) {
            return File::get($path);
        }

        $defaultEnvPath = base_path('.env');
        $examplePath = base_path('.env.example');

        if ($path === $defaultEnvPath && File::exists($examplePath)) {
            return File::get($examplePath);
        }

        return '';
    }

    private function extractEnvValue(string $contents, string $key): ?string
    {
        $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';

        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }
}
