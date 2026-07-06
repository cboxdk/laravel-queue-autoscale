<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Telemetry;

use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Events\ClusterLeaderChanged;
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
final class TelemetryEventSubscriber
{
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
        ];
    }

    public function handleScalingDecisionMade(ScalingDecisionMade $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $decision = $event->decision;
        $labels = ['connection' => $decision->connection, 'queue' => $decision->queue];

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
                ->set((float) $decision->capacity->finalMaxWorkers, ['limiter' => $decision->capacity->limitingFactor]);
        }

        $this->flushDebounced($telemetry);
    }

    public function handleWorkersScaled(WorkersScaled $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $telemetry = $this->telemetry();
        $labels = ['connection' => $event->connection, 'queue' => $event->queue];

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
        $labels = ['connection' => $event->connection, 'queue' => $event->queue];

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
        $labels = ['connection' => $event->connection, 'queue' => $event->queue];

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
