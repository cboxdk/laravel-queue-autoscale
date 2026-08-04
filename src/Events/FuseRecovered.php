<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the probe succeeded and normal autoscaling resumes.
 */
class FuseRecovered
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $failureRate,
        public readonly int $samples,
    ) {}
}
