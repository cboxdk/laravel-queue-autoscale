---
title: "Configuration Value Objects"
description: "The readonly objects your queue-autoscale config is parsed into, and what each field controls"
weight: 20
---

## Configuration Value Objects

All `final readonly`. Live in `src/Configuration/`.

### `QueueConfiguration`

Per-queue resolved configuration. Built by `QueueConfiguration::fromConfig($connection, $queue)`.

```php
readonly class QueueConfiguration
{
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
        public array $memberQueues = [],  // populated when adapted from a GroupConfiguration
    ) {}

    /** @return list<string> Real queue names to aggregate signals across (group support). */
    public function sampleQueues(): array;

    public static function fromConfig(string $connection, string $queue): self;
}

`fromConfig()` resolves `queue-autoscale.queues.{queue}` through `resolveProfileOrArray()`: a `ProfileContract` class string, or a literal partial-override array deep-merged over `sla_defaults`. It does **not** understand `['profile' => ..., 'overrides' => [...]]` — that shape belongs to `GroupConfiguration` only.

Access is nested: `$config->workers->min`, `$config->workers->max`, `$config->sla->targetSeconds`. There is no `$config->minWorkers`, `$config->maxWorkers` or `$config->maxPickupTimeSeconds`.
```

### `SlaConfiguration`

```php
public function __construct(
    public int $targetSeconds,   // > 0
    public int $percentile,      // one of 50, 75, 90, 95, 99
    public int $windowSeconds,   // >= 60
    public int $minSamples,      // >= 1; below this many samples, fall back to oldest_job_age
) {}
```

The constructor throws `InvalidConfigurationException` on any violation of those constraints.

### `WorkerConfiguration`

```php
public function __construct(
    public int $min,                  // >= 0
    public int $max,                  // >= $min
    public int $tries,                // >= 1
    public int $timeoutSeconds,       // > 0
    public int $sleepSeconds,
    public int $shutdownTimeoutSeconds,
    public bool $scalable = true,   // false = supervised/pinned (ExclusiveProfile)
) {}

public function pinnedCount(): int;   // returns $min; used when scalable=false
```

Constructor guards throw `InvalidConfigurationException`, including `scalable=false` requiring `min === max` and `min >= 1`.

> Every value here reaches the spawned worker. The global `queue-autoscale.workers` block that used to override them no longer exists.

### `ForecastConfiguration`

```php
public function __construct(
    public string $forecasterClass,       // class-string<ForecasterContract>
    public string $policyClass,           // class-string<ForecastPolicyContract>
    public int $horizonSeconds,           // how far ahead to predict
    public int $historySeconds,           // how much history to feed the forecaster
) {}
```

### `SpawnCompensationConfiguration`

```php
public function __construct(
    public bool $enabled,
    public float $fallbackSeconds,
    public int $minSamples,
    public float $emaAlpha,
) {}
```

### `FuseConfiguration`

```php
readonly class FuseConfiguration
{
    public function __construct(
        public bool $enabled,
        public float $failureThresholdPercent,  // (0, 100]
        public int $minSamples,                 // >= 1
        public int $windowSeconds,              // >= 1
        public int $cooldownSeconds,
    ) {}

    public static function fromArray(array $config): self;
}
```

See [Failure Fuse](../basic-usage/failure-fuse.md).

### `GroupConfiguration`

Multi-queue priority worker group. See [Queue Topology → Groups](../basic-usage/queue-topology.md#worker-groups).

```php
readonly class GroupConfiguration
{
    public const MODE_PRIORITY = 'priority';

    public function __construct(
        public string $name,
        public string $connection,
        public array $queues,           // array<int, string> in priority order
        public string $mode,            // MODE_PRIORITY is the only supported value
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
    ) {}

    public function queueArgument(): string;                    // 'email,sms,push'
    public function toScalingConfiguration(): QueueConfiguration;
    public static function fromConfig(string $name, array $config): self;
    public static function allFromConfig(): array;              // array<string, self>
    public static function assertNoQueueConflicts(array $groups): void;
}
```

`fromConfig()` reads `queues`, `connection` (default `'default'`), `mode` (default `'priority'`), `profile` and `overrides`. The `profile` + `overrides` pair is **groups-only**; the per-queue resolver does not understand it.

The constructor throws `InvalidConfigurationException` for an empty queue list, an unsupported mode, a non-scalable profile, or a duplicate queue within the group. `assertNoQueueConflicts()` throws when a queue appears both under `queues` and in a group, or in two groups.
