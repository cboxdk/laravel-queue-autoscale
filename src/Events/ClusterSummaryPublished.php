<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ClusterSummaryPublished
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly string $clusterId,
        public readonly string $leaderId,
        public readonly array $summary,
        public readonly int $publishedAt,
    ) {}
}
