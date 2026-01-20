<?php

declare(strict_types=1);

namespace App\QueueAutoscale\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Example: Metrics logging policy
 *
 * Logs detailed metrics for every scaling decision.
 * Useful for analysis, debugging, and optimization.
 *
 * Usage:
 * In config/queue-autoscale.php:
 * 'policies' => [
 *     \App\QueueAutoscale\Policies\MetricsLoggingPolicy::class,
 * ],
 */
class MetricsLoggingPolicy implements ScalingPolicy
{
    private array $beforeMetrics = [];

    public function __construct(
        private string $logChannel = 'autoscale-metrics',
        private bool $logToSeparateFile = true,
    ) {
        if ($this->logToSeparateFile) {
            $this->configureLogChannel();
        }
    }

    public function before(ScalingDecision $decision): void
    {
        $key = $this->getDecisionKey($decision);

        $this->beforeMetrics[$key] = [
            'timestamp' => microtime(true),
            'workers' => $decision->currentWorkers,
        ];

        $this->log('debug', 'Scaling decision initiated', [
            'queue' => "{$decision->connection}/{$decision->queue}",
            'current_workers' => $decision->currentWorkers,
            'target_workers' => $decision->targetWorkers,
            'change' => $decision->targetWorkers - $decision->currentWorkers,
            'reason' => $decision->reason,
        ]);
    }

    public function after(ScalingDecision $decision): void
    {
        $key = $this->getDecisionKey($decision);
        $before = $this->beforeMetrics[$key] ?? null;

        if ($before) {
            $duration = microtime(true) - $before['timestamp'];
            unset($this->beforeMetrics[$key]);
        } else {
            $duration = null;
        }

        $level = $this->determineLogLevel($decision);

        $this->log($level, 'Scaling decision completed', [
            'queue' => "{$decision->connection}/{$decision->queue}",
            'workers_before' => $decision->currentWorkers,
            'workers_after' => $decision->targetWorkers,
            'change' => $decision->targetWorkers - $decision->currentWorkers,
            'reason' => $decision->reason,
            'predicted_pickup_time' => $decision->predictedPickupTime,
            'sla_target' => $decision->slaTarget,
            'sla_health' => $this->calculateSlaHealth($decision),
            'duration_ms' => $duration ? round($duration * 1000, 2) : null,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function getDecisionKey(ScalingDecision $decision): string
    {
        return "{$decision->connection}.{$decision->queue}";
    }

    private function determineLogLevel(ScalingDecision $decision): string
    {
        $change = abs($decision->targetWorkers - $decision->currentWorkers);

        // SLA breach or near breach
        if ($decision->predictedPickupTime > $decision->slaTarget * 0.9) {
            return 'warning';
        }

        // Significant scaling change
        if ($change >= 5) {
            return 'notice';
        }

        return 'info';
    }

    private function calculateSlaHealth(ScalingDecision $decision): ?string
    {
        if ($decision->predictedPickupTime === null || $decision->predictedPickupTime === 0.0) {
            return 'healthy';
        }

        $ratio = $decision->predictedPickupTime / $decision->slaTarget;

        return match (true) {
            $ratio >= 1.0 => 'breach',
            $ratio >= 0.9 => 'critical',
            $ratio >= 0.7 => 'warning',
            default => 'healthy',
        };
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logToSeparateFile) {
            Log::channel($this->logChannel)->$level($message, $context);
        } else {
            Log::$level("[Autoscale] {$message}", $context);
        }
    }

    private function configureLogChannel(): void
    {
        // Dynamically add log channel if not already configured
        if (! config("logging.channels.{$this->logChannel}")) {
            config([
                "logging.channels.{$this->logChannel}" => [
                    'driver' => 'daily',
                    'path' => storage_path('logs/autoscale-metrics.log'),
                    'level' => 'debug',
                    'days' => 14,
                ],
            ]);
        }
    }
}
