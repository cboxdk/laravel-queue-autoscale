<?php

declare(strict_types=1);

namespace App\QueueAutoscale\Strategies;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;

/**
 * Example: Time-based scaling strategy
 *
 * Scales workers based on time of day patterns.
 * Useful for predictable daily load patterns (e.g., higher during business hours).
 *
 * Usage:
 * In config/queue-autoscale.php:
 * 'strategy' => \App\QueueAutoscale\Strategies\TimeBasedStrategy::class,
 */
class TimeBasedStrategy implements ScalingStrategyContract
{
    private string $lastReason = 'No calculation performed yet';

    private ?float $lastPrediction = null;

    public function __construct(
        private array $schedule = [
            // Business hours: 9am-5pm = high capacity
            '09:00-17:00' => ['multiplier' => 2.0, 'min_workers' => 3],
            // Evening: 5pm-9pm = medium capacity
            '17:00-21:00' => ['multiplier' => 1.5, 'min_workers' => 2],
            // Night: 9pm-9am = low capacity
            '21:00-09:00' => ['multiplier' => 0.5, 'min_workers' => 1],
        ]
    ) {}

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        $currentHour = now()->format('H:i');
        $scheduleConfig = $this->getScheduleForTime($currentHour);

        // Calculate base workers from metrics
        $baseWorkers = $this->calculateBaseWorkers($metrics);

        // Apply time-based multiplier
        $targetWorkers = (int) ceil($baseWorkers * $scheduleConfig['multiplier']);

        // Enforce schedule minimum
        $targetWorkers = max($targetWorkers, $scheduleConfig['min_workers']);

        $this->lastReason = sprintf(
            'Time-based: %s (multiplier: %.1fx, base: %d workers)',
            $this->getCurrentPeriod($currentHour),
            $scheduleConfig['multiplier'],
            $baseWorkers
        );

        $this->lastPrediction = $metrics->depth->pending > 0
            ? $metrics->depth->pending / max($targetWorkers, 1)
            : 0.0;

        return $targetWorkers;
    }

    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    public function getLastPrediction(): ?float
    {
        return $this->lastPrediction;
    }

    private function calculateBaseWorkers(object $metrics): int
    {
        // Simple rate-based calculation
        $processingRate = $metrics->processingRate ?? 0.0;
        $activeWorkers = $metrics->activeWorkerCount ?? 0;

        if ($processingRate <= 0 || $activeWorkers <= 0) {
            return 0;
        }

        // Estimate average job time
        $avgJobTime = $activeWorkers / $processingRate;

        // Calculate workers needed for current rate
        return (int) ceil($processingRate * $avgJobTime);
    }

    private function getScheduleForTime(string $currentTime): array
    {
        foreach ($this->schedule as $period => $config) {
            [$start, $end] = explode('-', $period);

            if ($this->isTimeBetween($currentTime, $start, $end)) {
                return $config;
            }
        }

        // Fallback to default
        return ['multiplier' => 1.0, 'min_workers' => 1];
    }

    private function getCurrentPeriod(string $currentTime): string
    {
        foreach ($this->schedule as $period => $config) {
            [$start, $end] = explode('-', $period);

            if ($this->isTimeBetween($currentTime, $start, $end)) {
                return $period;
            }
        }

        return 'default';
    }

    private function isTimeBetween(string $current, string $start, string $end): bool
    {
        if ($start < $end) {
            // Normal range: 09:00-17:00
            return $current >= $start && $current < $end;
        } else {
            // Overnight range: 21:00-09:00
            return $current >= $start || $current < $end;
        }
    }
}
