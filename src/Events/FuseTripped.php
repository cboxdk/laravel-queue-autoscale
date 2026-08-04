<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the failure fuse trips and the queue is held at workers.min.
 *
 * This means the observed failure rate crossed the configured threshold —
 * usually a downstream dependency is down and scaling up would only increase
 * pressure on it. Treat this as an incident signal, not a scaling signal.
 */
final class FuseTripped
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $failureRate,
        public readonly int $samples,
        public readonly int $failures,
        public readonly float $thresholdPercent,
        public readonly int $heldAtWorkers,
    ) {}
}
