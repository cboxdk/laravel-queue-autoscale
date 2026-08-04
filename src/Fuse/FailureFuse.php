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

        $window = $this->observeWindow($config);
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
                // Enough evidence to judge the probe on its merits.
                $total >= $fuse->minSamples => $unhealthy
                    ? $this->trip($config, $total, $failures, $failureRate, $now)
                    : $this->recover($config, $total, $failures, $failureRate, $now),

                // Not enough yet, but the probe has had long enough that more
                // waiting will not help — decide on what we have.
                $this->probeExhausted($config, $changedAt, $now) => $failureRate >= $fuse->failureThresholdPercent
                    ? $this->trip($config, $total, $failures, $failureRate, $now)
                    : $this->recover($config, $total, $failures, $failureRate, $now),

                default => new FuseVerdict(FuseState::HalfOpen, $total, $failures, $failureRate),
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

    /**
     * Outcome counts for everything this configuration is responsible for.
     *
     * Workers record outcomes under the REAL queue name they pulled the job
     * from. A group is scaled as a single unit under the group's name, so
     * reading the group name alone would find an empty window forever and the
     * fuse could never trip for any grouped queue.
     *
     * @return array{total: int, failures: int}
     */
    private function observeWindow(QueueConfiguration $config): array
    {
        $total = 0;
        $failures = 0;

        foreach ($config->sampleQueues() as $queue) {
            $window = $this->store->currentWindow($config->connection, $queue, $config->fuse->windowSeconds);
            $total += $window['total'];
            $failures += $window['failures'];
        }

        return ['total' => $total, 'failures' => $failures];
    }

    /**
     * State is keyed on the scaling identity (the group name for a group),
     * because the fuse trips for the unit that scales. The window is cleared
     * per member queue, because that is where the counters live.
     */
    private function transition(QueueConfiguration $config, FuseState $state, float $now): void
    {
        $this->store->writeState($config->connection, $config->queue, $state->value, $now);

        foreach ($config->sampleQueues() as $queue) {
            $this->store->resetWindow($config->connection, $queue, $config->fuse->windowSeconds);
        }
    }

    /**
     * Has the probe had long enough that waiting for more samples is futile?
     *
     * Without this the half-open state has no deadline, and a queue can be
     * pinned at the probe ceiling forever: the probe runs at most
     * max(1, workers.min) workers, the window holds at most 2 x window_seconds
     * of outcomes, and if that worker cannot finish min_samples jobs in that
     * span the count never arrives. The throttle that makes the probe safe is
     * exactly what starves it — a queue whose jobs take longer than
     * (2 x window_seconds / min_samples) would never recover, which is
     * precisely the slow downstream-calling workload the fuse targets.
     *
     * The deadline covers both the cooldown and a full window turnover, so a
     * probe is never cut short before its evidence could have accumulated.
     */
    private function probeExhausted(QueueConfiguration $config, float $changedAt, float $now): bool
    {
        $deadline = max($config->fuse->cooldownSeconds, $config->fuse->windowSeconds * 2);

        return ($now - $changedAt) >= $deadline;
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
