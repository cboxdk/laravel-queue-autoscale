<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ClusterManagerPresenceChanged
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  list<string>  $managerIds
     * @param  list<string>  $addedManagerIds
     * @param  list<string>  $removedManagerIds
     */
    public function __construct(
        public readonly string $clusterId,
        public readonly array $managerIds,
        public readonly array $addedManagerIds,
        public readonly array $removedManagerIds,
        public readonly string $leaderId,
        public readonly string $observedByManagerId,
        public readonly int $observedAt,
    ) {}
}
