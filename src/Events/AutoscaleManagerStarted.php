<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AutoscaleManagerStarted
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $managerId,
        public readonly string $host,
        public readonly bool $clusterEnabled,
        public readonly string $clusterId,
        public readonly int $intervalSeconds,
        public readonly int $startedAt,
        public readonly string $packageVersion,
    ) {}
}
