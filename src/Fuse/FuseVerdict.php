<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Fuse;

/**
 * The fuse's view of one queue at one evaluation cycle.
 */
readonly class FuseVerdict
{
    public function __construct(
        public FuseState $state,
        public int $samples,
        public int $failures,
        public float $failureRate,
    ) {}

    /**
     * Verdict used when the fuse is disabled for this queue — never constrains.
     */
    public static function closed(): self
    {
        return new self(FuseState::Closed, 0, 0, 0.0);
    }

    public function isTripped(): bool
    {
        return $this->state->isTripped();
    }

    /**
     * Worker ceiling the fuse imposes, or null when it isn't tripped.
     *
     * The half-open probe floors at one worker but never drops below
     * workers.min — the configured floor is the operator's decision, so a
     * queue with min=5 probes with five. The floor of one matters for
     * scale-to-zero queues: holding at zero would process no further jobs, so
     * no outcomes would ever be recorded and the fuse could never close again.
     *
     * This is a ceiling, not a target. The caller lowers its target to meet
     * it and never raises one to reach it, so an idle queue stays idle while
     * tripped rather than being handed probe workers it has no work for.
     */
    public function workerCeiling(int $configuredMin): ?int
    {
        return match ($this->state) {
            FuseState::Closed => null,
            FuseState::Open => $configuredMin,
            FuseState::HalfOpen => max(1, $configuredMin),
        };
    }

    public function reason(): string
    {
        return match ($this->state) {
            FuseState::Closed => '',
            FuseState::Open => $this->samples > 0
                ? sprintf(
                    'fuse OPEN: %.1f%% failure rate over %d jobs — holding at workers.min instead of scaling into the failure',
                    $this->failureRate,
                    $this->samples,
                )
                : 'fuse OPEN: holding at workers.min while the downstream recovers',
            FuseState::HalfOpen => $this->samples > 0
                ? sprintf('fuse HALF-OPEN: probing recovery (%d/%d jobs failing so far)', $this->failures, $this->samples)
                : 'fuse HALF-OPEN: probing recovery at the minimum worker count',
        };
    }
}
