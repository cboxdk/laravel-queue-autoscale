<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

final readonly class ClusterScalingSignalUpdated
{
    public function __construct(
        public string $clusterId,
        public string $leaderId,
        public int $currentHosts,
        public int $recommendedHosts,
        public int $currentCapacity,
        public int $requiredWorkers,
        public string $action,
        public string $reason,
    ) {}
}
