<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ClusterLeaderChanged
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $clusterId,
        public readonly ?string $previousLeaderId,
        public readonly ?string $currentLeaderId,
        public readonly string $observedByManagerId,
        public readonly int $changedAt,
    ) {}
}
