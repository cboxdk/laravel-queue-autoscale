<?php

declare(strict_types=1);

namespace App\QueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Http;

/**
 * Example: Slack notification policy
 *
 * Sends Slack notifications when scaling decisions are made.
 * Useful for monitoring and alerting on scaling events.
 *
 * Usage:
 * In config/queue-autoscale.php:
 * 'policies' => [
 *     \App\QueueAutoscale\Policies\SlackNotificationPolicy::class,
 * ],
 *
 * In .env:
 * SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
 */
class SlackNotificationPolicy implements ScalingPolicy
{
    public function __construct(
        private ?string $webhookUrl = null,
        private int $significantChangeThreshold = 2, // Notify on changes >= 2 workers
    ) {
        $this->webhookUrl = $webhookUrl ?? config('services.slack.webhook_url');
    }

    public function before(ScalingDecision $decision): void
    {
        // No action before scaling
    }

    public function after(ScalingDecision $decision): void
    {
        if (! $this->webhookUrl) {
            return;
        }

        $change = $decision->targetWorkers - $decision->currentWorkers;

        // Only notify on significant changes
        if (abs($change) < $this->significantChangeThreshold) {
            return;
        }

        $this->sendSlackNotification($decision, $change);
    }

    private function sendSlackNotification(ScalingDecision $decision, int $change): void
    {
        $emoji = $this->getEmoji($change);
        $color = $this->getColor($change);

        $message = [
            'text' => sprintf(
                '%s Queue Autoscale: %s workers %s',
                $emoji,
                abs($change),
                $change > 0 ? 'added' : 'removed'
            ),
            'attachments' => [
                [
                    'color' => $color,
                    'fields' => [
                        [
                            'title' => 'Queue',
                            'value' => "{$decision->connection}/{$decision->queue}",
                            'short' => true,
                        ],
                        [
                            'title' => 'Workers',
                            'value' => "{$decision->currentWorkers} → {$decision->targetWorkers}",
                            'short' => true,
                        ],
                        [
                            'title' => 'Reason',
                            'value' => $decision->reason,
                            'short' => false,
                        ],
                        [
                            'title' => 'Predicted Pickup Time',
                            'value' => $decision->predictedPickupTime !== null
                                ? round($decision->predictedPickupTime, 1).'s'
                                : 'N/A',
                            'short' => true,
                        ],
                        [
                            'title' => 'SLA Target',
                            'value' => $decision->slaTarget.'s',
                            'short' => true,
                        ],
                    ],
                    'footer' => 'Laravel Queue Autoscale',
                    'ts' => time(),
                ],
            ],
        ];

        try {
            Http::post($this->webhookUrl, $message);
        } catch (\Exception $e) {
            // Fail silently - don't disrupt autoscaling
            logger()->warning('Failed to send Slack notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getEmoji(int $change): string
    {
        return match (true) {
            $change > 5 => '🚀',
            $change > 0 => '📈',
            $change < -5 => '💤',
            default => '📉',
        };
    }

    private function getColor(int $change): string
    {
        return match (true) {
            $change > 5 => 'danger',   // Large scale up
            $change > 0 => 'warning',  // Scale up
            $change < -5 => 'good',    // Large scale down
            default => '#439FE0',      // Scale down
        };
    }
}
