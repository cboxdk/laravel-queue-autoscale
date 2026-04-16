<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

final readonly class QueueConfiguration
{
    public function __construct(
        public string $connection,
        public string $queue,
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
    ) {}

    public static function fromConfig(string $connection, string $queue): self
    {
        $defaults = self::resolveProfileOrArray(config('queue-autoscale.sla_defaults'));
        $override = config("queue-autoscale.queues.{$queue}", []);

        $overrideArray = self::resolveProfileOrArray($override);
        $merged = self::deepMerge($defaults, $overrideArray);

        return new self(
            connection: $connection,
            queue: $queue,
            sla: new SlaConfiguration(
                targetSeconds: (int) $merged['sla']['target_seconds'],
                percentile: (int) $merged['sla']['percentile'],
                windowSeconds: (int) $merged['sla']['window_seconds'],
                minSamples: (int) $merged['sla']['min_samples'],
            ),
            forecast: new ForecastConfiguration(
                forecasterClass: (string) $merged['forecast']['forecaster'],
                policyClass: (string) $merged['forecast']['policy'],
                horizonSeconds: (int) $merged['forecast']['horizon_seconds'],
                historySeconds: (int) $merged['forecast']['history_seconds'],
            ),
            spawnCompensation: new SpawnCompensationConfiguration(
                enabled: (bool) $merged['spawn_compensation']['enabled'],
                fallbackSeconds: (float) $merged['spawn_compensation']['fallback_seconds'],
                minSamples: (int) $merged['spawn_compensation']['min_samples'],
                emaAlpha: (float) $merged['spawn_compensation']['ema_alpha'],
            ),
            workers: new WorkerConfiguration(
                min: (int) $merged['workers']['min'],
                max: (int) $merged['workers']['max'],
                tries: (int) $merged['workers']['tries'],
                timeoutSeconds: (int) $merged['workers']['timeout_seconds'],
                sleepSeconds: (int) $merged['workers']['sleep_seconds'],
                shutdownTimeoutSeconds: (int) $merged['workers']['shutdown_timeout_seconds'],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveProfileOrArray(mixed $value): array
    {
        if (is_string($value) && class_exists($value) && is_subclass_of($value, ProfileContract::class)) {
            /** @var ProfileContract $instance */
            $instance = new $value;

            return $instance->resolve();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
