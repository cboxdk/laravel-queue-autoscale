<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Events\ClusterLeaderChanged;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CapacityCalculationResult;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Telemetry\TelemetryEventSubscriber;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Metrics\Registry;
use Cbox\Telemetry\Metrics\Stores\ArrayMetricStore;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Tracer;

function makeScalingDecision(array $overrides = []): ScalingDecision
{
    return new ScalingDecision(...array_merge([
        'connection' => 'redis',
        'queue' => 'default',
        'currentWorkers' => 2,
        'targetWorkers' => 5,
        'reason' => 'backlog growing',
        'predictedPickupTime' => 12.5,
        'slaTarget' => 30,
    ], $overrides));
}

beforeEach(function () {
    config()->set('queue-autoscale.telemetry.events', true);
    $this->fake = Telemetry::fake();
    $this->subscriber = new TelemetryEventSubscriber(app());
});

it('records scaling decision gauges', function () {
    $this->subscriber->handleScalingDecisionMade(new ScalingDecisionMade(makeScalingDecision()));

    $labels = ['connection' => 'redis', 'queue' => 'default'];

    expect($this->fake->gaugeValue('queue_autoscale.workers.target', $labels))->toBe(5.0)
        ->and($this->fake->gaugeValue('queue_autoscale.sla.predicted_pickup', $labels))->toBe(12.5)
        ->and($this->fake->gaugeValue('queue_autoscale.sla.target', $labels))->toBe(30.0);
});

it('records the capacity ceiling gauge with its limiting factor label', function () {
    $capacity = new CapacityCalculationResult(
        maxWorkersByCpu: 8,
        maxWorkersByMemory: 12,
        maxWorkersByConfig: 20,
        finalMaxWorkers: 8,
        limitingFactor: 'cpu',
    );

    $this->subscriber->handleScalingDecisionMade(new ScalingDecisionMade(makeScalingDecision(['capacity' => $capacity])));

    expect($this->fake->gaugeValue('queue_autoscale.capacity.max_workers', ['limiter' => 'cpu']))->toBe(8.0);
});

it('skips the predicted pickup gauge when prediction is null', function () {
    $this->subscriber->handleScalingDecisionMade(new ScalingDecisionMade(makeScalingDecision(['predictedPickupTime' => null])));

    $names = array_map(fn ($family) => $family->name(), $this->fake->collect());

    expect($names)->not->toContain('queue_autoscale.sla.predicted_pickup');
});

it('counts scaling actions and emits a scaling action event', function () {
    $this->subscriber->handleWorkersScaled(new WorkersScaled(
        connection: 'redis', queue: 'default', from: 2, to: 5, action: 'up', reason: 'backlog',
    ));

    $this->fake->assertCounterIncremented('queue_autoscale.scaling.actions', [
        'connection' => 'redis', 'queue' => 'default', 'direction' => 'up',
    ]);
    $this->fake->assertEventEmitted('queue_autoscale.scaling.action');
});

it('sets the breach gauge and counts breaches on sla breach, then clears on recovery', function () {
    $labels = ['connection' => 'redis', 'queue' => 'default'];

    $this->subscriber->handleSlaBreached(new SlaBreached(
        connection: 'redis', queue: 'default', oldestJobAge: 45, slaTarget: 30, pending: 100, activeWorkers: 2,
    ));

    expect($this->fake->gaugeValue('queue_autoscale.sla.breach', $labels))->toBe(1.0);
    $this->fake->assertCounterIncremented('queue_autoscale.sla.breaches', $labels);
    $this->fake->assertEventEmitted('queue_autoscale.sla.breached');

    $this->subscriber->handleSlaRecovered(new SlaRecovered(
        connection: 'redis', queue: 'default', currentJobAge: 5, slaTarget: 30, pending: 3, activeWorkers: 4,
    ));

    expect($this->fake->gaugeValue('queue_autoscale.sla.breach', $labels))->toBe(0.0);
    $this->fake->assertEventEmitted('queue_autoscale.sla.recovered');
});

it('counts leader changes', function () {
    $this->subscriber->handleClusterLeaderChanged(new ClusterLeaderChanged(
        clusterId: 'app-prod', previousLeaderId: 'a', currentLeaderId: 'b',
        observedByManagerId: 'b', changedAt: 1_000,
    ));

    $this->fake->assertCounterIncremented('queue_autoscale.cluster.leader_changes', []);
    $this->fake->assertEventEmitted('queue_autoscale.cluster.leader_changed');
});

it('emits a manager stopped event with uptime', function () {
    $this->subscriber->handleAutoscaleManagerStopped(new AutoscaleManagerStopped(
        managerId: 'm1', host: 'web-01', clusterEnabled: false, clusterId: '',
        startedAt: 1_000_000, stoppedAt: 61_000_000, reason: 'restart_signal',
        workerCount: 3, packageVersion: 'dev',
    ));

    $this->fake->assertEventEmitted('queue_autoscale.manager.stopped', function ($event): bool {
        return $event->attributes['uptime_seconds'] === 60_000.0
            && $event->attributes['reason'] === 'restart_signal';
    });
});

it('records nothing when the events toggle is disabled', function () {
    config()->set('queue-autoscale.telemetry.events', false);

    $this->subscriber->handleWorkersScaled(new WorkersScaled(
        connection: 'redis', queue: 'default', from: 2, to: 5, action: 'up', reason: 'backlog',
    ));

    $this->fake->assertCounterNotIncremented('queue_autoscale.scaling.actions');
    $this->fake->assertEventNotEmitted('queue_autoscale.scaling.action');
});

it('emits a manager started event with the manager and cluster identifiers', function () {
    $this->subscriber->handleAutoscaleManagerStarted(new AutoscaleManagerStarted(
        managerId: 'm1', host: 'web-01', clusterEnabled: true, clusterId: 'app-prod',
        intervalSeconds: 5, startedAt: 1_000, packageVersion: 'dev',
    ));

    $this->fake->assertEventEmitted('queue_autoscale.manager.started', function ($event): bool {
        return $event->attributes['manager_id'] === 'm1'
            && $event->attributes['cluster_id'] === 'app-prod';
    });
});

it('debounces flushes for rapid decisions, then flushes again once the window passes', function () {
    $telemetry = new class(enabled: true, registry: new Registry(new ArrayMetricStore, [1, 5, 10]), tracer: new Tracer(sampleRate: 1.0)) extends TelemetryManager
    {
        public int $flushCount = 0;

        public function flush(bool $forceDetails = false): void
        {
            $this->flushCount++;

            parent::flush($forceDetails);
        }
    };

    app()->instance(TelemetryManager::class, $telemetry);

    // A small, deterministic window instead of the 1-second production
    // default — keeps the test fast without relying on real-time sleeps
    // longer than necessary.
    $subscriber = new TelemetryEventSubscriber(app(), flushIntervalSeconds: 0.05);

    $subscriber->handleScalingDecisionMade(new ScalingDecisionMade(makeScalingDecision()));
    $subscriber->handleScalingDecisionMade(new ScalingDecisionMade(makeScalingDecision()));

    expect($telemetry->flushCount)->toBe(1);

    usleep(60_000);

    $subscriber->handleScalingDecisionMade(new ScalingDecisionMade(makeScalingDecision()));

    expect($telemetry->flushCount)->toBe(2);
});
