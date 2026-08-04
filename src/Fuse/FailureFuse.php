<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Fuse;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Events\FuseProbing;
use Cbox\LaravelQueueAutoscale\Events\FuseRecovered;
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;

/**
 * Circuit breaker over a queue's job failure rate.
 *
 * Without it, a downstream outage looks exactly like load: jobs fail, get
 * released, the backlog grows and the oldest job ages — so the autoscaler
 * scales up and hammers the failing dependency with more workers while
 * burning through each job's retry budget faster.
 *
 * States move Closed → Open → HalfOpen → Closed. Only the manager process
 * transitions state (once per evaluation cycle); worker processes only ever
 * increment the outcome counters the decision reads.
 *
 * Every transition resets the window. Without that, the failures that tripped
 * the fuse would still be in view when the probe is evaluated and would
 * re-trip it immediately, so the fuse could never close.
 */
final readonly class FailureFuse
{
    /**
     * @param  (\Closure(): float)|null  $clock  Overridable wall clock, matching
     *                                           CacheFailureWindowStore. Cooldowns are compared against
     *                                           microtime() rather than Carbon, so Laravel's travel()
     *                                           helper cannot move them; simulations pass a clock to
     *                                           drive a whole outage without sleeping.
     */
    public function __construct(
        private FailureWindowStoreContract $store,
        private ?\Closure $clock = null,
    ) {}

    public function evaluate(QueueConfiguration $config): FuseVerdict
    {
        $fuse = $config->fuse;

        if (! $fuse->enabled) {
            return FuseVerdict::closed();
        }

        $window = $this->store->currentWindow($config->connection, $config->queue, $fuse->windowSeconds);
        $total = $window['total'];
        $failures = $window['failures'];
        $failureRate = $total > 0 ? ($failures / $total) * 100.0 : 0.0;

        $unhealthy = $total >= $fuse->minSamples && $failureRate >= $fuse->failureThresholdPercent;

        [$state, $changedAt] = $this->currentState($config);
        $now = $this->now();

        return match ($state) {
            FuseState::Closed => $unhealthy
                ? $this->trip($config, $total, $failures, $failureRate, $now)
                : new FuseVerdict(FuseState::Closed, $total, $failures, $failureRate),

            FuseState::Open => ($now - $changedAt) >= $fuse->cooldownSeconds
                ? $this->probe($config, $now)
                : new FuseVerdict(FuseState::Open, $total, $failures, $failureRate),

            FuseState::HalfOpen => match (true) {
                $total < $fuse->minSamples => new FuseVerdict(FuseState::HalfOpen, $total, $failures, $failureRate),
                $unhealthy => $this->trip($config, $total, $failures, $failureRate, $now),
                default => $this->recover($config, $total, $failures, $failureRate, $now),
            },
        };
    }

    private function trip(
        QueueConfiguration $config,
        int $total,
        int $failures,
        float $failureRate,
        float $now,
    ): FuseVerdict {
        $this->transition($config, FuseState::Open, $now);

        FuseTripped::dispatch(
            $config->connection,
            $config->queue,
            $failureRate,
            $total,
            $failures,
            $config->fuse->failureThresholdPercent,
            $config->workers->min,
        );

        return new FuseVerdict(FuseState::Open, $total, $failures, $failureRate);
    }

    private function probe(QueueConfiguration $config, float $now): FuseVerdict
    {
        $this->transition($config, FuseState::HalfOpen, $now);

        FuseProbing::dispatch(
            $config->connection,
            $config->queue,
            max(1, $config->workers->min),
            $config->fuse->cooldownSeconds,
        );

        return new FuseVerdict(FuseState::HalfOpen, 0, 0, 0.0);
    }

    private function recover(
        QueueConfiguration $config,
        int $total,
        int $failures,
        float $failureRate,
        float $now,
    ): FuseVerdict {
        $this->transition($config, FuseState::Closed, $now);

        FuseRecovered::dispatch($config->connection, $config->queue, $failureRate, $total);

        return new FuseVerdict(FuseState::Closed, $total, $failures, $failureRate);
    }

    private function transition(QueueConfiguration $config, FuseState $state, float $now): void
    {
        $this->store->writeState($config->connection, $config->queue, $state->value, $now);
        $this->store->resetWindow($config->connection, $config->queue, $config->fuse->windowSeconds);
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    /**
     * @return array{0: FuseState, 1: float}
     */
    private function currentState(QueueConfiguration $config): array
    {
        $raw = $this->store->readState($config->connection, $config->queue);

        if ($raw === null) {
            return [FuseState::Closed, $this->now()];
        }

        return [
            FuseState::tryFrom($raw['state']) ?? FuseState::Closed,
            $raw['changed_at'],
        ];
    }
}
