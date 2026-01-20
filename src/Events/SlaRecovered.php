<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a queue recovers from an SLA breach
 *
 * This event is fired when a queue that was previously breaching its SLA
 * has recovered and is now processing within the SLA target.
 *
 * Use this event to:
 * - Close alerts and incidents
 * - Log recovery metrics
 * - Notify stakeholders of resolution
 * - Calculate Mean Time To Recovery (MTTR)
 */
final class SlaRecovered
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $currentJobAge,
        public readonly int $slaTarget,
        public readonly int $pending,
        public readonly int $activeWorkers,
    ) {}

    /**
     * Get the current margin below SLA (safety buffer)
     */
    public function marginSeconds(): int
    {
        return max(0, $this->slaTarget - $this->currentJobAge);
    }

    /**
     * Get the margin as percentage of SLA
     */
    public function marginPercentage(): float
    {
        if ($this->slaTarget === 0) {
            return 0.0;
        }

        return (($this->slaTarget - $this->currentJobAge) / $this->slaTarget) * 100;
    }
}
