<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;
use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

readonly class QueueConfiguration
{
    /**
     * @param  list<string>  $memberQueues  When this configuration represents a group, lists the real
     *                                      member queues whose metrics/samples should be aggregated.
     *                                      Empty for per-queue configurations.
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
        public FuseConfiguration $fuse = new FuseConfiguration(
            enabled: true,
            failureThresholdPercent: 50.0,
            minSamples: 20,
            windowSeconds: 60,
            cooldownSeconds: 60,
        ),
        public array $memberQueues = [],
    ) {}

    /**
     * Queue names to consult for per-real-queue signals (pickup time, spawn latency).
     *
     * For per-queue configs this returns [queue]. For group-adapted configs this returns
     * the member queue list so strategies can aggregate samples across every real queue
     * the group polls.
     *
     * @return list<string>
     */
    public function sampleQueues(): array
    {
        return $this->memberQueues !== [] ? $this->memberQueues : [$this->queue];
    }

    public static function fromConfig(string $connection, string $queue): self
    {
        $defaults = self::resolveProfileOrArray(config('queue-autoscale.sla_defaults'));
        $override = QueueConfigResolver::overrideFor($queue);

        $overrideArray = self::resolveProfileOrArray($override);

        /** @var array{
         *     sla: array{target_seconds: int, percentile: int, window_seconds: int, min_samples: int},
         *     forecast: array{forecaster: class-string<ForecasterContract>, policy: class-string<ForecastPolicyContract>, horizon_seconds: int, history_seconds: int},
         *     spawn_compensation: array{enabled: bool, fallback_seconds: float, min_samples: int, ema_alpha: float},
         *     workers: array{min: int, max: int, tries: int, max_time_seconds: int, timeout_seconds: int, sleep_seconds: int, shutdown_timeout_seconds: int, scalable?: bool},
         *     fuse?: array{enabled: bool, failure_threshold_percent: float, min_samples: int, window_seconds: int, cooldown_seconds: int},
         * } $merged
         */
        $merged = self::deepMerge($defaults, $overrideArray);

        // A worker floor is a statement about a queue the operator NAMED.
        //
        // Queues are discovered from metrics, not registered, so an app that
        // mints a queue name per tenant presents thousands of them. Each one
        // that matches no entry in `queues` inherits sla_defaults — and the
        // shipped default profile floors at one worker, a floor the engine
        // deliberately applies AFTER the CPU/memory clamp. The result is one
        // permanently-running process per queue name nobody asked for, bounded
        // by nothing. In cluster mode it is worse: the fair-share allocator
        // satisfies every floor before it weighs demand, so unnamed floors
        // evict the queue that actually has a backlog.
        //
        // Withdrawing only the floor is the narrowest fix: an unmatched queue
        // still gets the default SLA target, max, forecast, spawn compensation
        // and fuse. It scales from zero on demand instead of standing idle.
        // To floor everything anyway, name everything: `'*' => ['workers' =>
        // ['min' => 1]]`, which also trips the doctor's warning to set
        // `limits.max_total_workers` — the bound that makes it safe.
        // A pinned (non-scalable) default is exempt: workers.scalable = false
        // requires min === max by construction, so zeroing the floor would
        // make the configuration invalid and throw. Pointing sla_defaults at a
        // non-scalable profile is an explicit statement that every queue runs
        // a fixed worker count — unlike the shipped BalancedProfile, which is
        // merely what you get by not choosing.
        // Cast, not a strict comparison: the constructor below casts the same
        // value, and reading it two different ways here made them disagree on
        // every falsy non-false value. 'scalable' => 0 slipped past a strict
        // check, had its floor zeroed, and then threw on the cast.
        $scalable = (bool) ($merged['workers']['scalable'] ?? true);

        if ($scalable && QueueConfigResolver::matchedRuleFor($queue) === null) {
            $merged['workers']['min'] = 0;
        }

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
                maxTimeSeconds: (int) $merged['workers']['max_time_seconds'],
                timeoutSeconds: (int) $merged['workers']['timeout_seconds'],
                sleepSeconds: (int) $merged['workers']['sleep_seconds'],
                shutdownTimeoutSeconds: (int) $merged['workers']['shutdown_timeout_seconds'],
                scalable: (bool) ($merged['workers']['scalable'] ?? true),
            ),
            fuse: FuseConfiguration::fromArray($merged['fuse'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveProfileOrArray(mixed $value): array
    {
        if (self::isProfileClass($value)) {
            return (new $value)->resolve();
        }

        if (! is_array($value)) {
            return [];
        }

        // A 'profile' key names the baseline and the rest of the array refines
        // it, matching how groups already accept a profile alongside their
        // own settings. Without this a caller wanting one shipped profile with
        // a single value changed had to restate every field it contains.
        if (isset($value['profile'])) {
            $profile = $value['profile'];
            unset($value['profile']);

            if (! self::isProfileClass($profile)) {
                throw new InvalidConfigurationException(
                    "queue-autoscale.queues 'profile' must be a class implementing ProfileContract, got: "
                    .(is_string($profile) ? $profile : get_debug_type($profile))
                );
            }

            return self::deepMerge(
                (new $profile)->resolve(),
                self::resolveProfileOrArray($value),
            );
        }

        // Config arrays are string-keyed by construction; filtering rather
        // than asserting keeps a malformed positional entry from silently
        // becoming a config key.
        $resolved = [];

        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $resolved[$key] = $entry;
            }
        }

        return $resolved;
    }

    /**
     * @phpstan-assert-if-true class-string<ProfileContract> $value
     */
    private static function isProfileClass(mixed $value): bool
    {
        return is_string($value)
            && class_exists($value)
            && is_subclass_of($value, ProfileContract::class);
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
