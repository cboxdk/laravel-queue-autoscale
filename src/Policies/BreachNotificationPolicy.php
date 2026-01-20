<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Policy that logs and notifies when SLA breaches are detected or predicted
 *
 * Use Case: Any workload where visibility into SLA compliance is important.
 * This policy provides monitoring, alerting, and visibility for SLA-related events.
 *
 * Example:
 * - Production systems requiring SLA monitoring
 * - Critical queues with strict performance requirements
 * - Systems with on-call rotations for queue issues
 *
 * Notifications:
 * - Logs SLA breach risks to configured log channel
 * - Logs actual SLA breaches
 * - Can be extended to send alerts via Slack, PagerDuty, etc.
 *
 * Benefits:
 * - Visibility into SLA compliance
 * - Early warning system for performance issues
 * - Audit trail for debugging
 * - Foundation for alerting integrations
 */
final readonly class BreachNotificationPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        // This policy doesn't modify decisions, only monitors
        return null;
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // Check for SLA breach risk
        if ($decision->isSlaBreachRisk()) {
            $this->logBreachRisk($decision);
        }

        // Check if predicted pickup time is very close to SLA (90%+)
        if ($decision->predictedPickupTime !== null) {
            $slaUtilization = ($decision->predictedPickupTime / $decision->slaTarget) * 100;

            if ($slaUtilization >= 90) {
                $this->logHighSlaUtilization($decision, $slaUtilization);
            }
        }
    }

    private function logBreachRisk(ScalingDecision $decision): void
    {
        Log::channel(AutoscaleConfiguration::logChannel())->warning(
            'SLA BREACH RISK DETECTED',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'predicted_pickup_time' => $decision->predictedPickupTime,
                'sla_target' => $decision->slaTarget,
                'current_workers' => $decision->currentWorkers,
                'target_workers' => $decision->targetWorkers,
                'reason' => $decision->reason,
            ]
        );
    }

    private function logHighSlaUtilization(ScalingDecision $decision, float $utilization): void
    {
        Log::channel(AutoscaleConfiguration::logChannel())->notice(
            sprintf('High SLA utilization: %.1f%%', $utilization),
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'sla_utilization_percent' => round($utilization, 1),
                'predicted_pickup_time' => $decision->predictedPickupTime,
                'sla_target' => $decision->slaTarget,
                'current_workers' => $decision->currentWorkers,
                'target_workers' => $decision->targetWorkers,
            ]
        );
    }
}
