<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

/**
 * Smooths target worker count across evaluation cycles to prevent oscillation
 *
 * When throughput is stable (coefficient of variation < 5%), limits scale-down
 * to at most 1 worker per cycle. Scale-up is always unrestricted to preserve
 * reactivity to genuine load increases.
 *
 * This prevents the pattern where transient pending=0 blips cause the strategy
 * to drop target sharply via Little's Law, only to pump it back up when pending
 * refills — producing 33%+ oscillation under steady-state load.
 */
final class TargetSmoother
{
    private const MAX_THROUGHPUT_HISTORY = 10;

    private const STABLE_CV_THRESHOLD = 0.05;

    private const MIN_SAMPLES_FOR_STABILITY = 3;

    private const MAX_SCALE_DOWN_WHEN_STABLE = 1;

    /**
     * @var array<string, int>
     */
    private array $previousTargets = [];

    /**
     * @var array<string, list<float>>
     */
    private array $throughputHistory = [];

    /**
     * @var array{applied: bool, raw_target: int|null, smoothed_target: int|null, cv: float|null, stable: bool|null}
     */
    private array $lastSmoothing = [
        'applied' => false,
        'raw_target' => null,
        'smoothed_target' => null,
        'cv' => null,
        'stable' => null,
    ];

    /**
     * Smooth target to prevent oscillation during stable throughput
     *
     * When throughput is stable and target wants to decrease, limits
     * the decrease to MAX_SCALE_DOWN_WHEN_STABLE per cycle. Scale-up
     * is always immediate.
     *
     * @param  string  $queueKey  Unique key for the queue (connection:queue)
     * @param  int  $rawTarget  Target calculated by the strategy (already clamped to bounds)
     * @param  float  $throughputPerMinute  Current throughput in jobs/minute
     * @return int Smoothed target worker count
     */
    public function smooth(string $queueKey, int $rawTarget, float $throughputPerMinute): int
    {
        $this->recordThroughput($queueKey, $throughputPerMinute);

        $previousTarget = $this->previousTargets[$queueKey] ?? null;

        // First call or scaling up: allow immediately
        if ($previousTarget === null || $rawTarget >= $previousTarget) {
            $this->previousTargets[$queueKey] = $rawTarget;
            $this->lastSmoothing = [
                'applied' => false,
                'raw_target' => $rawTarget,
                'smoothed_target' => $rawTarget,
                'cv' => null,
                'stable' => null,
            ];

            return $rawTarget;
        }

        // Scale-down requested — check throughput stability
        $cv = $this->coefficientOfVariation($queueKey);
        $stable = $cv !== null && $cv < self::STABLE_CV_THRESHOLD;

        if ($stable) {
            // Limit scale-down to 1 worker per cycle
            $smoothedTarget = max($rawTarget, $previousTarget - self::MAX_SCALE_DOWN_WHEN_STABLE);
            $this->previousTargets[$queueKey] = $smoothedTarget;
            $this->lastSmoothing = [
                'applied' => $smoothedTarget !== $rawTarget,
                'raw_target' => $rawTarget,
                'smoothed_target' => $smoothedTarget,
                'cv' => $cv,
                'stable' => true,
            ];

            return $smoothedTarget;
        }

        // Throughput is volatile — allow full scale-down
        $this->previousTargets[$queueKey] = $rawTarget;
        $this->lastSmoothing = [
            'applied' => false,
            'raw_target' => $rawTarget,
            'smoothed_target' => $rawTarget,
            'cv' => $cv,
            'stable' => false,
        ];

        return $rawTarget;
    }

    /**
     * @return array{applied: bool, raw_target: int|null, smoothed_target: int|null, cv: float|null, stable: bool|null}
     */
    public function getLastSmoothing(): array
    {
        return $this->lastSmoothing;
    }

    public function reset(?string $queueKey = null): void
    {
        if ($queueKey !== null) {
            unset($this->previousTargets[$queueKey], $this->throughputHistory[$queueKey]);
        } else {
            $this->previousTargets = [];
            $this->throughputHistory = [];
        }

        $this->lastSmoothing = [
            'applied' => false,
            'raw_target' => null,
            'smoothed_target' => null,
            'cv' => null,
            'stable' => null,
        ];
    }

    private function recordThroughput(string $queueKey, float $throughputPerMinute): void
    {
        if (! isset($this->throughputHistory[$queueKey])) {
            $this->throughputHistory[$queueKey] = [];
        }

        $this->throughputHistory[$queueKey][] = $throughputPerMinute;

        if (count($this->throughputHistory[$queueKey]) > self::MAX_THROUGHPUT_HISTORY) {
            array_shift($this->throughputHistory[$queueKey]);
        }
    }

    /**
     * Compute coefficient of variation for the throughput history
     *
     * Returns null if insufficient samples or zero mean (no meaningful throughput).
     */
    private function coefficientOfVariation(string $queueKey): ?float
    {
        $history = $this->throughputHistory[$queueKey] ?? [];

        if (count($history) < self::MIN_SAMPLES_FOR_STABILITY) {
            return null;
        }

        $mean = array_sum($history) / count($history);

        if ($mean <= 0.0) {
            return null;
        }

        $sumSquaredDiffs = 0.0;
        foreach ($history as $value) {
            $sumSquaredDiffs += ($value - $mean) ** 2;
        }

        $stddev = sqrt($sumSquaredDiffs / count($history));

        return $stddev / $mean;
    }
}
