<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Cluster;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * One workload as the cluster leader sees it, carried across the three phases
 * of a leader cycle: demand evaluation, fair-share allocation, and
 * distribution.
 *
 * A queue and a group are the same shape here on purpose — a group is a
 * synthetic workload whose metrics are its members aggregated — so the
 * distribution phase does not branch on which it is.
 */
readonly class EvaluatedWorkload
{
    /**
     * @param  bool  $isGroup  A group polls several queues from one worker; a queue is its own workload.
     * @param  list<string>  $memberQueues  The queues this workload's workers actually poll.
     */
    public function __construct(
        public bool $isGroup,
        public string $connection,
        public string $name,
        public string $driver,
        public QueueConfiguration $config,
        public int $currentWorkers,
        public QueueMetricsData $metrics,
        public array $memberQueues,
    ) {}

    /**
     * `'queue'` or `'group'`, as the published recommendation and the console
     * summary label it.
     */
    public function type(): string
    {
        return $this->isGroup ? 'group' : 'queue';
    }

    /**
     * Whether the strategy is allowed to move this workload's worker count.
     *
     * This is the configured `workers.scalable` flag, not an inference from the
     * bounds: an equal min and max leaves a queue scalable, while setting the
     * flag false additionally requires them to be equal. A supervised workload
     * is still evaluated and still consults policies; it just has nothing to
     * solve for.
     */
    public function isScalable(): bool
    {
        return $this->config->workers->scalable;
    }

    /**
     * Whether the oldest waiting job has already missed the SLA.
     *
     * Guarded on a non-zero age because an empty queue reports zero, which
     * would otherwise read as "infinitely late" against any target.
     */
    public function isBreaching(): bool
    {
        return $this->metrics->oldestJobAge > 0
            && $this->metrics->oldestJobAge >= $this->config->sla->targetSeconds;
    }

    /**
     * The key under which this workload's SLA breach state is remembered.
     * Groups are namespaced so a group and a queue of the same name on the
     * same connection cannot collide.
     */
    public function breachKey(): string
    {
        return ($this->isGroup ? 'group:' : '')."{$this->connection}:{$this->name}";
    }
}
