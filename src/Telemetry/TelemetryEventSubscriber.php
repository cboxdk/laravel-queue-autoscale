<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Telemetry;

use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Events\ClusterLeaderChanged;
use Cbox\LaravelQueueAutoscale\Events\FuseProbing;
use Cbox\LaravelQueueAutoscale\Events\FuseRecovered;
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\Telemetry\TelemetryManager;
use Illuminate\Contracts\Container\Container;

/**
 * Pushes autoscaler state to telemetry from the manager daemon. Push (not
 * observable) gauges on purpose: the manager is long-running and standalone,
 * so nothing else could evaluate a scrape callback for its in-memory state.
 *
 * Rare-but-important signals flush immediately — the daemon has no request
 * terminate. The per-tick decision handler flushes at most once per
 * configured debounce window (one second by default).
 *
 * `Event::subscribe()` maps each listener to `[self::class, $method]` and
 * lets the container resolve a fresh instance on every dispatch — it does
 * NOT reuse the instance that registered the subscription. The debounce
 * state above therefore only holds across dispatches because this class is
 * bound as a container singleton (see the service provider); without that
 * binding, every dispatch would construct a new instance with
 * `$lastFlushAt = 0.0`, and every decision would flush.
 */
class TelemetryEventSubscriber
{
    /**
     * Queue names that have been given a label of their own.
     *
     * Static because the subscriber is resolved per event; an instance
     * property would reset the count every time and cap nothing.
     *
     * @var array<string, true>
     */
    private static array $namedQueues = [];

    /**
     * The fuse reports as one gauge with an encoded state rather than a
     * boolean per state, so a dashboard reads the current state from a single
     * series instead of reconciling several that can disagree mid-transition.
     */
    private const float FUSE_STATE_CLOSED = 0.0;

    private const float FUSE_STATE_HALF_OPEN = 1.0;

    private const float FUSE_STATE_OPEN = 2.0;

    private const string FUSE_STATE_DESCRIPTION = 'Failure fuse state: 0 closed, 1 half-open (probing), 2 open (holding at workers.min)';

    private float $lastFlushAt = 0.0;

    public function __construct(
        private readonly Container $container,
        private readonly float $flushIntervalSeconds = 1.0,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            ScalingDecisionMade::class => 'handleScalingDecisionMade',
            WorkersScaled::class => 'handleWorkersScaled',
            SlaBreached::class => 'handleSlaBreached',
            SlaRecovered::class => 'handleSlaRecovered',
            AutoscaleManagerStarted::class => 'handleAutoscaleManagerStarted',
            AutoscaleManagerStopped::class => 'handleAutoscaleManagerStopped',
            ClusterLeaderChanged::class => 'handleClusterLeaderChanged',
            FuseTripped::class => 'handleFuseTripped',
            FuseProbing::class => 'handleFuseProbing',
            FuseRecovered::class => 'handleFuseRecovered',
        ];
    }

    public function handleScalingDecisionMade(ScalingDecisionMade $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $decision = $event->decision;
        $labels = $this->labelsFor($decision->connection, $decision->queue);

        $telemetry->gauge('queue_autoscale.workers.target', description: 'Worker count the autoscaler is steering toward', unit: '{workers}')
            ->set((float) $decision->targetWorkers, $labels);

        $telemetry->gauge('queue_autoscale.sla.target', description: 'Configured SLA pickup target', unit: 's')
            ->set((float) $decision->slaTarget, $labels);

        if ($decision->predictedPickupTime !== null) {
            $telemetry->gauge('queue_autoscale.sla.predicted_pickup', description: 'Predicted job pickup time', unit: 's')
                ->set($decision->predictedPickupTime, $labels);
        }

        if ($decision->capacity !== null) {
            $telemetry->gauge('queue_autoscale.capacity.max_workers', description: 'Host worker capacity ceiling', unit: '{workers}')
                ->set((float) $decision->capacity->finalMaxWorkers, ['limiter' => $decision->capacity->limitingFactor->value]);
        }

        $this->flushDebounced($telemetry);
    }

    public function handleWorkersScaled(WorkersScaled $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = $this->labelsFor($event->connection, $event->queue);

        $telemetry->counter('queue_autoscale.scaling.actions', 'Executed scaling actions', unit: '{actions}')
            ->inc(1, [...$labels, 'direction' => $event->action]);

        $telemetry->event('queue_autoscale.scaling.action', [
            ...$labels,
            'from' => $event->from,
            'to' => $event->to,
            'direction' => $event->action,
            'reason' => $event->reason,
        ]);

        $telemetry->flush();
    }

    public function handleSlaBreached(SlaBreached $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = $this->labelsFor($event->connection, $event->queue);

        $telemetry->gauge('queue_autoscale.sla.breach', description: 'Whether the queue is currently breaching its SLA', unit: '1')
            ->set(1.0, $labels);

        $telemetry->counter('queue_autoscale.sla.breaches', 'SLA breach transitions', unit: '{breaches}')
            ->inc(1, $labels);

        $telemetry->event('queue_autoscale.sla.breached', [
            ...$labels,
            'oldest_job_age' => $event->oldestJobAge,
            'sla_target' => $event->slaTarget,
            'breach_seconds' => $event->breachSeconds(),
            'breach_percentage' => $event->breachPercentage(),
            'pending' => $event->pending,
            'active_workers' => $event->activeWorkers,
        ]);

        $telemetry->flush();
    }

    public function handleSlaRecovered(SlaRecovered $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = $this->labelsFor($event->connection, $event->queue);

        $telemetry->gauge('queue_autoscale.sla.breach', description: 'Whether the queue is currently breaching its SLA', unit: '1')
            ->set(0.0, $labels);

        $telemetry->event('queue_autoscale.sla.recovered', [
            ...$labels,
            'current_job_age' => $event->currentJobAge,
            'sla_target' => $event->slaTarget,
            'margin_seconds' => $event->marginSeconds(),
            'pending' => $event->pending,
            'active_workers' => $event->activeWorkers,
        ]);

        $telemetry->flush();
    }

    public function handleAutoscaleManagerStarted(AutoscaleManagerStarted $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();

        $telemetry->event('queue_autoscale.manager.started', [
            'manager_id' => $event->managerId,
            'host' => $event->host,
            'cluster_enabled' => $event->clusterEnabled,
            'cluster_id' => $event->clusterId,
            'interval_seconds' => $event->intervalSeconds,
            'package_version' => $event->packageVersion,
        ]);

        $telemetry->flush();
    }

    public function handleAutoscaleManagerStopped(AutoscaleManagerStopped $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();

        $telemetry->event('queue_autoscale.manager.stopped', [
            'manager_id' => $event->managerId,
            'host' => $event->host,
            'reason' => $event->reason,
            'worker_count' => $event->workerCount,
            'uptime_seconds' => ($event->stoppedAt - $event->startedAt) / 1000.0,
        ]);

        $telemetry->flush();
    }

    public function handleClusterLeaderChanged(ClusterLeaderChanged $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();

        $telemetry->counter('queue_autoscale.cluster.leader_changes', 'Cluster leader changes', unit: '{changes}')
            ->inc(1, []);

        $telemetry->event('queue_autoscale.cluster.leader_changed', [
            'cluster_id' => $event->clusterId,
            'previous_leader_id' => $event->previousLeaderId ?? '',
            'current_leader_id' => $event->currentLeaderId ?? '',
        ]);

        $telemetry->flush();
    }

    public function handleFuseTripped(FuseTripped $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = $this->labelsFor($event->connection, $event->queue);

        $telemetry->gauge('queue_autoscale.fuse.state', description: self::FUSE_STATE_DESCRIPTION, unit: '1')
            ->set(self::FUSE_STATE_OPEN, $labels);

        $telemetry->counter('queue_autoscale.fuse.trips', 'Failure fuse trips', unit: '{trips}')
            ->inc(1, $labels);

        $telemetry->event('queue_autoscale.fuse.tripped', [
            ...$labels,
            'failure_rate' => $event->failureRate,
            'threshold_percent' => $event->thresholdPercent,
            'samples' => $event->samples,
            'failures' => $event->failures,
            'held_at_workers' => $event->heldAtWorkers,
        ]);

        $telemetry->flush();
    }

    public function handleFuseProbing(FuseProbing $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = $this->labelsFor($event->connection, $event->queue);

        $telemetry->gauge('queue_autoscale.fuse.state', description: self::FUSE_STATE_DESCRIPTION, unit: '1')
            ->set(self::FUSE_STATE_HALF_OPEN, $labels);

        $telemetry->event('queue_autoscale.fuse.probing', [
            ...$labels,
            'probe_workers' => $event->probeWorkers,
            'cooldown_seconds' => $event->cooldownSeconds,
        ]);

        $telemetry->flush();
    }

    public function handleFuseRecovered(FuseRecovered $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = $this->labelsFor($event->connection, $event->queue);

        $telemetry->gauge('queue_autoscale.fuse.state', description: self::FUSE_STATE_DESCRIPTION, unit: '1')
            ->set(self::FUSE_STATE_CLOSED, $labels);

        $telemetry->event('queue_autoscale.fuse.recovered', [
            ...$labels,
            'failure_rate' => $event->failureRate,
            'samples' => $event->samples,
        ]);

        $telemetry->flush();
    }

    /**
     * Metric labels for a queue, with the queue name capped by cardinality.
     *
     * Queues are discovered rather than listed, so an application naming them
     * per tenant presents thousands — one time series per tenant per metric,
     * which is how a metrics backend falls over or produces a very large bill.
     * Queue names embedding tenant identifiers also reach a system with a
     * different access-control model than the app.
     *
     * Past the cap, further queues share one bucket rather than minting new
     * series. Which queues get named is whichever appeared first, and that is
     * deliberate: a stable set of names beats a set that churns.
     *
     * @return array<string, string>
     */
    private function labelsFor(string $connection, string $queue): array
    {
        return ['connection' => $connection, 'queue' => $this->queueLabel($queue)];
    }

    private function queueLabel(string $queue): string
    {
        $cap = config('queue-autoscale.telemetry.max_queue_labels', 100);

        if (! is_numeric($cap) || (int) $cap <= 0) {
            return $queue;
        }

        if (isset(self::$namedQueues[$queue])) {
            return $queue;
        }

        if (count(self::$namedQueues) >= (int) $cap) {
            return '__other__';
        }

        self::$namedQueues[$queue] = true;

        return $queue;
    }

    private function enabled(): bool
    {
        return (bool) config('queue-autoscale.telemetry.events', true);
    }

    private function telemetry(): TelemetryManager
    {
        return $this->container->make(TelemetryManager::class);
    }

    private function flushDebounced(TelemetryManager $telemetry): void
    {
        $nowSeconds = microtime(true);

        if ($nowSeconds - $this->lastFlushAt < $this->flushIntervalSeconds) {
            return;
        }

        $this->lastFlushAt = $nowSeconds;
        $telemetry->flush();
    }
}
