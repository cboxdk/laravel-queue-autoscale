<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class QueueConfiguration
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $maxPickupTimeSeconds,
        public int $minWorkers,
        public int $maxWorkers,
        public int $scaleCooldownSeconds,
    ) {}

    public static function fromConfig(string $connection, string $queue): self
    {
        $defaults = config('queue-autoscale.sla_defaults');
        $override = config("queue-autoscale.queues.{$queue}", []);

        return new self(
            connection: $connection,
            queue: $queue,
            maxPickupTimeSeconds: $override['max_pickup_time_seconds'] ?? $defaults['max_pickup_time_seconds'],
            minWorkers: $override['min_workers'] ?? $defaults['min_workers'],
            maxWorkers: $override['max_workers'] ?? $defaults['max_workers'],
            scaleCooldownSeconds: $override['scale_cooldown_seconds'] ?? $defaults['scale_cooldown_seconds'],
        );
    }
}
