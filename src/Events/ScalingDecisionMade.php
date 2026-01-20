<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ScalingDecisionMade
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly ScalingDecision $decision,
    ) {}
}
