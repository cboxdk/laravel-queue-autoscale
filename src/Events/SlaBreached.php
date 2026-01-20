<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when an SLA breach is actively occurring
 *
 * This event is fired when a job's age has exceeded the configured SLA target.
 * This indicates an actual breach in progress, not just a prediction.
 *
 * Use this event to:
 * - Trigger alerts to operations teams
 * - Log critical SLA violations
 * - Escalate to incident management systems
 * - Track SLA compliance metrics
 */
final class SlaBreached
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $oldestJobAge,
        public readonly int $slaTarget,
        public readonly int $pending,
        public readonly int $activeWorkers,
    ) {}

    /**
     * Get the breach severity (how far over SLA)
     */
    public function breachSeconds(): int
    {
        return max(0, $this->oldestJobAge - $this->slaTarget);
    }

    /**
     * Get the breach percentage (how far over SLA as percentage)
     */
    public function breachPercentage(): float
    {
        if ($this->slaTarget === 0) {
            return 0.0;
        }

        return (($this->oldestJobAge - $this->slaTarget) / $this->slaTarget) * 100;
    }
}
