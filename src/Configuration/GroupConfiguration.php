<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;
use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

/**
 * A worker group runs one set of processes that polls multiple queues in
 * priority order. Groups are the right abstraction when several queues share
 * a workload budget and you want idle workers in one queue to absorb bursts
 * on another without paying spawn latency.
 *
 * Scaling decisions are made at the group level (aggregated metrics across
 * members). Each spawned worker invokes:
 *
 *     queue:work {connection} --queue=queue1,queue2,queue3
 *
 * Laravel's worker iterates that comma-separated list per poll cycle and
 * takes the first job it finds, which yields strict-priority semantics.
 */
final readonly class GroupConfiguration
{
    public const MODE_PRIORITY = 'priority';

    public function __construct(
        public string $name,
        public string $connection,
        /** @var array<int, string> */
        public array $queues,
        public string $mode,
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
    ) {
        if ($this->queues === []) {
            throw new InvalidConfigurationException(
                "Group '{$name}' must declare at least one queue."
            );
        }

        if ($this->mode !== self::MODE_PRIORITY) {
            throw new InvalidConfigurationException(
                "Group '{$name}' has unsupported mode '{$mode}'. Only 'priority' is supported in v2."
            );
        }

        if (! $this->workers->scalable) {
            throw new InvalidConfigurationException(
                "Group '{$name}' cannot use a non-scalable profile (scalable=false). ".
                'Use a per-queue ExclusiveProfile if you need pinned workers.'
            );
        }

        $seen = [];
        foreach ($this->queues as $q) {
            if (isset($seen[$q])) {
                throw new InvalidConfigurationException(
                    "Group '{$name}' lists queue '{$q}' more than once."
                );
            }
            $seen[$q] = true;
        }
    }

    /**
     * The comma-separated argument passed to `queue:work --queue=...`.
     *
     * Order matters: Laravel polls left-to-right and takes the first job
     * found per poll cycle.
     */
    public function queueArgument(): string
    {
        return implode(',', $this->queues);
    }

    /**
     * Adapt this group to a QueueConfiguration so the existing ScalingEngine
     * can evaluate it. The group's name is used as the logical queue
     * identifier so all downstream decisions/log entries remain coherent.
     */
    public function toScalingConfiguration(): QueueConfiguration
    {
        return new QueueConfiguration(
            connection: $this->connection,
            queue: $this->name,
            sla: $this->sla,
            forecast: $this->forecast,
            spawnCompensation: $this->spawnCompensation,
            workers: $this->workers,
            memberQueues: array_values($this->queues),
        );
    }

    /**
     * Build a group from a raw config entry. Accepts either a full array or
     * a profile class plus queue list via the 'profile' key.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $queues = $config['queues'] ?? null;

        if (! is_array($queues)) {
            throw new InvalidConfigurationException(
                "Group '{$name}' requires a 'queues' list."
            );
        }

        /** @var array<int, string> $queueNames */
        $queueNames = array_values(array_filter(
            array_map(static fn ($q): string => is_string($q) ? $q : '', $queues),
            static fn (string $q): bool => $q !== '',
        ));

        $connection = is_string($config['connection'] ?? null)
            ? (string) $config['connection']
            : 'default';

        $mode = is_string($config['mode'] ?? null)
            ? (string) $config['mode']
            : self::MODE_PRIORITY;

        $profileClass = $config['profile'] ?? null;

        $profileResolved = self::resolveProfile($profileClass);
        $overrides = is_array($config['overrides'] ?? null) ? $config['overrides'] : [];
        $merged = self::deepMerge($profileResolved, $overrides);

        /** @var array{
         *     sla: array{target_seconds: int, percentile: int, window_seconds: int, min_samples: int},
         *     forecast: array{forecaster: class-string<ForecasterContract>, policy: class-string<ForecastPolicyContract>, horizon_seconds: int, history_seconds: int},
         *     spawn_compensation: array{enabled: bool, fallback_seconds: float, min_samples: int, ema_alpha: float},
         *     workers: array{min: int, max: int, tries: int, timeout_seconds: int, sleep_seconds: int, shutdown_timeout_seconds: int, scalable?: bool},
         * } $merged
         */
        return new self(
            name: $name,
            connection: $connection,
            queues: $queueNames,
            mode: $mode,
            sla: new SlaConfiguration(
                targetSeconds: (int) $merged['sla']['target_seconds'],
                percentile: (int) $merged['sla']['percentile'],
                windowSeconds: (int) $merged['sla']['window_seconds'],
                minSamples: (int) $merged['sla']['min_samples'],
            ),
            forecast: new ForecastConfiguration(
                forecasterClass: $merged['forecast']['forecaster'],
                policyClass: $merged['forecast']['policy'],
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
                scalable: (bool) ($merged['workers']['scalable'] ?? true),
            ),
        );
    }

    /**
     * Load all configured groups from queue-autoscale.groups.
     *
     * @return array<string, self>
     */
    public static function allFromConfig(): array
    {
        /** @var array<string, mixed> $groupsConfig */
        $groupsConfig = config('queue-autoscale.groups', []);
        $groups = [];

        foreach ($groupsConfig as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            /** @var array<string, mixed> $config */
            $groups[(string) $name] = self::fromConfig((string) $name, $config);
        }

        return $groups;
    }

    /**
     * Validate that no queue appears both in the flat 'queues' section and
     * inside any group's 'queues' list. This is a startup-time safety check.
     *
     * @param  array<string, self>  $groups
     *
     * @throws InvalidConfigurationException
     */
    public static function assertNoQueueConflicts(array $groups): void
    {
        /** @var array<string, array<string, mixed>> $perQueueConfig */
        $perQueueConfig = config('queue-autoscale.queues', []);
        $flatQueues = array_keys($perQueueConfig);

        foreach ($groups as $group) {
            foreach ($group->queues as $q) {
                if (in_array($q, $flatQueues, true)) {
                    throw new InvalidConfigurationException(
                        "Queue '{$q}' is configured both in 'queues' and in group '{$group->name}'. ".
                        'Each queue may only appear in one place.'
                    );
                }
            }
        }

        $seenAcrossGroups = [];
        foreach ($groups as $group) {
            foreach ($group->queues as $q) {
                if (isset($seenAcrossGroups[$q])) {
                    throw new InvalidConfigurationException(
                        "Queue '{$q}' appears in multiple groups ('{$seenAcrossGroups[$q]}' and '{$group->name}'). ".
                        'A queue may only belong to one group.'
                    );
                }
                $seenAcrossGroups[$q] = $group->name;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveProfile(mixed $profile): array
    {
        if (is_string($profile) && class_exists($profile) && is_subclass_of($profile, ProfileContract::class)) {
            /** @var ProfileContract $instance */
            $instance = new $profile;

            return $instance->resolve();
        }

        if (is_array($profile)) {
            /** @var array<string, mixed> $profile */
            return $profile;
        }

        // Fall back to the configured sla_defaults if the group did not name a profile.
        $defaults = config('queue-autoscale.sla_defaults');

        if (is_string($defaults) && class_exists($defaults) && is_subclass_of($defaults, ProfileContract::class)) {
            /** @var ProfileContract $instance */
            $instance = new $defaults;

            return $instance->resolve();
        }

        if (is_array($defaults)) {
            /** @var array<string, mixed> $defaults */
            return $defaults;
        }

        return [];
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
                /** @var array<string, mixed> $baseKey */
                $baseKey = $base[$key];
                /** @var array<string, mixed> $value */
                $base[$key] = self::deepMerge($baseKey, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
