<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a tripped fuse enters its half-open probe.
 *
 * The cooldown has elapsed and a single worker is allowed to run so the
 * autoscaler can find out whether the downstream recovered. The next
 * evaluation with enough samples either closes the fuse or trips it again.
 */
final class FuseProbing
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $probeWorkers,
        public readonly int $cooldownSeconds,
    ) {}
}
