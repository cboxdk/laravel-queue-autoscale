<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Manager;

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterCooldown;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterSummaryBuilder;
use Cbox\LaravelQueueAutoscale\Cluster\CooldownDecision;
use Cbox\LaravelQueueAutoscale\Cluster\EvaluatedWorkload;
use Cbox\LaravelQueueAutoscale\Cluster\WorkerDistributor;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStopped;
use Cbox\LaravelQueueAutoscale\Events\ClusterLeaderChanged;
use Cbox\LaravelQueueAutoscale\Events\ClusterManagerPresenceChanged;
use Cbox\LaravelQueueAutoscale\Events\ClusterScalingSignalUpdated;
use Cbox\LaravelQueueAutoscale\Events\ClusterSummaryPublished;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\SlaBreached;
use Cbox\LaravelQueueAutoscale\Events\SlaBreachPredicted;
use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Output\ConsoleReporter;
use Cbox\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\QueueStats;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\WorkerStatus;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MeasuredResourceSample;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\ResourceEstimate;
use Cbox\LaravelQueueAutoscale\Scaling\FairShareAllocator;
use Cbox\LaravelQueueAutoscale\Scaling\MeasuredResourceCollector;
use Cbox\LaravelQueueAutoscale\Scaling\QueueMetricsAdapter;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingScope;
use Cbox\LaravelQueueAutoscale\Scaling\WorkloadDiscovery;
use Cbox\LaravelQueueAutoscale\Scaling\WorkloadStateTracker;
use Cbox\LaravelQueueAutoscale\Support\Coerce;
use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Cbox\LaravelQueueAutoscale\Support\WorkloadName;
use Cbox\LaravelQueueAutoscale\Workers\OrphanedWorkerReaper;
use Cbox\LaravelQueueAutoscale\Workers\WorkerOutputBuffer;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerScaler;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

class AutoscaleManager
{
    private WorkerPool $pool;

    private int $interval = 5;

    /**
     * How long per-queue bookkeeping outlives the last scaling action.
     *
     * Comfortably longer than any sane cooldown, so the anti-flapping window
     * is never truncated by the cleanup that exists to bound memory.
     */
    private const QUEUE_STATE_RETENTION_SECONDS = 3600;

    private ?OutputRendererContract $renderer = null;

    private WorkerOutputBuffer $outputBuffer;

    private int $startedAt = 0;

    /** @var array<string, QueueStats> */
    private array $currentQueueStats = [];

    private ?string $stopReason = null;

    private ?string $lastObservedLeaderId = null;

    /** @var list<string> */
    private array $lastObservedManagerIds = [];

    private readonly MeasuredResourceCollector $resourceCollector;

    private readonly WorkerScaler $scaler;

    private readonly WorkloadDiscovery $discovery;

    private readonly WorkloadStateTracker $workloadState;

    /** Whether this manager held the lease on the previous cycle. */
    /**
     * Leadership changes inside one anti-flapping window that count as unstable.
     *
     * Two allows for an ordinary failover and the handover that follows it. A
     * third inside the same window is no longer a transition, it is a pattern.
     */
    private const UNSTABLE_LEADERSHIP_CHANGES = 3;

    /**
     * How often a repeatedly failing cycle is announced on the console. The
     * first failure is always shown; a daemon that fails every few seconds must
     * not fill the terminal with the same line.
     */
    private const CYCLE_FAILURE_REPORT_INTERVAL_SECONDS = 60.0;

    private bool $wasLeader = false;

    /**
     * When this manager last saw the cluster's leader change, as Unix seconds.
     *
     * Gaining the lease discards the placement cache, the damping window and
     * the fairness ledger's accumulated position, because all three describe a
     * cluster the new leader has not observed. One failover costs a cycle;
     * leadership that keeps moving costs those guards permanently, and nothing
     * said so — a change was a debug line and an event nobody is obliged to
     * listen to.
     *
     * @var list<float>
     */
    private array $leaderChanges = [];

    /**
     * When a failing cycle was last announced on the console, as a Unix
     * timestamp with fractional seconds.
     */
    private ?float $cycleFailureReportedAt = null;

    public function __construct(
        private readonly ScalingEngine $engine,
        private readonly WorkerSpawner $spawner,
        private readonly WorkerTerminator $terminator,
        private readonly PolicyExecutor $policies,
        private readonly SignalHandler $signals,
        private readonly RestartSignal $restartSignal,
        private readonly ClusterStoreContract $clusterStore,
        private readonly CapacityCalculator $capacity,
        private readonly ResourceEstimateResolver $resolver,
        private readonly AlertRateLimiter $alerts = new AlertRateLimiter,
        private readonly OrphanedWorkerReaper $orphanReaper = new OrphanedWorkerReaper,
        private readonly WorkerDistributor $distributor = new WorkerDistributor,
        private readonly ClusterCooldown $cooldown = new ClusterCooldown,
        private readonly QueueMetricsAdapter $metricsAdapter = new QueueMetricsAdapter,
        private readonly ConsoleReporter $reporter = new ConsoleReporter,
        private readonly ClusterSummaryBuilder $summaryBuilder = new ClusterSummaryBuilder,
        ?MeasuredResourceCollector $resourceCollector = null,
        // Appended rather than slotted in beside the other leader-memory
        // collaborators so no positional caller shifts. It carries the
        // banked entitlement that keeps a workload from being starved
        // forever, which only accumulates if the instance OUTLIVES the cycle —
        // building one inside the evaluation, as this did, reset every balance
        // each time and made the whole guarantee a no-op.
        private readonly FairShareAllocator $allocator = new FairShareAllocator,
    ) {
        $this->pool = new WorkerPool;
        $this->outputBuffer = new WorkerOutputBuffer;

        $this->resourceCollector = $resourceCollector ?? new MeasuredResourceCollector($resolver);
        $this->discovery = new WorkloadDiscovery($metricsAdapter);
        $this->workloadState = new WorkloadStateTracker;

        // The scaler shares this manager's pool and output buffer rather than
        // owning them: the read paths that size the next decision live here,
        // and the buffer must be cleared by whoever reaps the dead worker.
        $this->scaler = new WorkerScaler(
            $this->pool,
            $spawner,
            $terminator,
            $this->reporter,
            $alerts,
            $this->outputBuffer,
        );
    }

    public function configure(int $interval): void
    {
        $this->interval = $interval;
    }

    /**
     * Announce departure so the cluster does not keep counting this host.
     *
     * A deliberate stop was previously indistinguishable from a crash: the
     * heartbeat key lived out its TTL and the registry entry survived until
     * another manager pruned it, so the leader distributed work to a host that
     * was already gone — and if this manager WAS the leader, the cluster had
     * no leader until the lease expired.
     *
     * Best-effort by design: shutdown must complete even if Redis is the
     * reason we are shutting down.
     */
    private function leaveCluster(): void
    {
        if (! AutoscaleConfiguration::clusterEnabled()) {
            return;
        }

        try {
            $this->clusterStore->deregister(AutoscaleConfiguration::managerId());
            $this->verbose('   ✓ Left the cluster', 'info');
        } catch (\Throwable $e) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Could not deregister from the cluster during shutdown',
                ['exception' => $e::class, 'message' => $e->getMessage()]
            );
        }
    }

    /**
     * Keep a fuse-held queue visible in the log for as long as it is held.
     *
     * Scaling actions are logged when they happen, but a held queue only
     * scales once — down to workers.min on the trip — and then holds. Without
     * this, the log falls silent for the rest of the outage, which is exactly
     * when an operator goes looking for it. Rate-limited the same way SLA
     * breach risk is, so a long outage produces a periodic line rather than
     * one per evaluation cycle.
     */
    private function logFuseHold(ScalingDecision $decision): void
    {
        if ($decision->capacity?->limitingFactor !== LimitingFactor::Fuse) {
            return;
        }

        if (! $this->alerts->allow("fuse_hold:{$decision->connection}:{$decision->queue}")) {
            return;
        }

        Log::channel(AutoscaleConfiguration::logChannel())->warning(
            'Autoscaling held back by failure fuse',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'current_workers' => $decision->currentWorkers,
                'target_workers' => $decision->targetWorkers,
                'reason' => $decision->reason,
            ]
        );
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->reporter->setOutput($output);
    }

    public function setRenderer(OutputRendererContract $renderer): void
    {
        $this->renderer = $renderer;
    }

    private function verbose(string $message, string $level = 'info'): void
    {
        $this->reporter->verbose($message, $level);
    }

    private function isVeryVerbose(): bool
    {
        return $this->reporter->isVeryVerbose();
    }

    public function run(): int
    {
        $this->startedAt = (int) round(microtime(true) * 1000);

        if (AutoscaleConfiguration::clusterEnabled()) {
            $this->assertClusterReady();
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Autoscale manager started',
            [
                'manager_id' => AutoscaleConfiguration::managerId(),
                'interval' => $this->interval,
            ]
        );

        event(new AutoscaleManagerStarted(
            managerId: AutoscaleConfiguration::managerId(),
            host: AutoscaleConfiguration::hostLabel(),
            clusterEnabled: AutoscaleConfiguration::clusterEnabled(),
            clusterId: AutoscaleConfiguration::clusterEnabled() ? AutoscaleConfiguration::clusterAppId() : '',
            intervalSeconds: $this->interval,
            startedAt: $this->startedAt,
            packageVersion: $this->packageVersion(),
        ));

        $this->reapOrphanedWorkers();

        $this->signals->register(function () {
            $this->stopReason = 'signal';

            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Shutdown signal received'
            );
        });

        $this->renderer?->initialize();

        // The loop must not be able to exit without draining the pool. Anything
        // that escapes runLoop() would otherwise leave every queue:work child
        // running until --max-time — an hour by default — while the supervisor
        // restarts the manager and it spawns a full fresh set on top of them.
        try {
            $this->runLoop();
        } finally {
            $this->shutdown();
        }

        return 0;
    }

    /**
     * Terminate workers a previous manager generation left running.
     *
     * Runs before the first spawn so a replacement manager does not
     * double-provision on top of orphans it cannot see. The pool is empty at
     * this point, so nothing this manager owns can match.
     */
    private function reapOrphanedWorkers(): void
    {
        if (! AutoscaleConfiguration::reapOrphansOnStart()) {
            return;
        }

        $this->orphanReaper->reap(AutoscaleConfiguration::managerId());
    }

    private function runLoop(): void
    {
        while (! $this->signals->shouldStop()) {
            $startTime = microtime(true);
            $this->signals->dispatch();

            try {
                // Inside the try because it reads the cache, and a cache blip
                // is not a reason to tear the manager down.
                if ($this->restartSignal->requestedAfter($this->startedAt)) {
                    $this->verbose('Restart signal detected; shutting down manager for supervised restart.', 'info');
                    $this->stopReason = 'restart_signal';

                    Log::channel(AutoscaleConfiguration::logChannel())->info(
                        'Restart signal detected; shutting down manager for supervised restart'
                    );

                    $this->signals->requestStop();

                    continue;
                }

                $this->processWorkerOutput();
                $this->scaler->enforceTerminationDeadlines();
                $this->scaler->cleanupDeadWorkers();

                if (AutoscaleConfiguration::clusterEnabled()) {
                    $this->runClusterCycle();
                } else {
                    $this->evaluateAndScale();
                }

                $this->renderOutput();
            } catch (\Throwable $e) {
                Log::channel(AutoscaleConfiguration::logChannel())->error(
                    'Autoscale evaluation failed',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                );

                $this->reportCycleFailureToConsole($e);
            }

            $executionTime = microtime(true) - $startTime;
            $sleepTime = max(0, $this->interval - $executionTime);

            // Sleep only the remaining time to maintain cadence
            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1_000_000));
            }
        }
    }

    private function runClusterCycle(): void
    {
        $this->beginEvaluationCycle();

        $capacity = $this->capacity->calculateMaxWorkers(
            $this->pool->totalCount(),
            ResourceEstimate::globalDefault(),
        );
        $cpu = $capacity->cpuBreakdown();
        $memory = $capacity->memoryBreakdown();
        $queueCounts = $this->pool->queueCounts();
        $groupCounts = $this->pool->groupCounts();
        $state = new ClusterManagerState(
            managerId: AutoscaleConfiguration::managerId(),
            host: AutoscaleConfiguration::hostLabel(),
            lastSeenAt: $this->currentTimestamp(),
            totalWorkers: $this->pool->totalCount(),
            maxWorkers: $capacity->finalMaxWorkers,
            availableWorkerCapacity: max($capacity->finalMaxWorkers - $this->pool->totalCount(), 0),
            capacityLimiter: $capacity->limitingFactor->value,
            cpuPercent: $cpu->currentCpuPercent,
            cpuCores: $cpu->totalCores,
            cpuUsableCores: $cpu->usableCores,
            cpuReservedCores: $cpu->reserveCores,
            memoryPercent: $memory->currentMemoryPercent,
            memoryTotalMb: $memory->totalMemoryMb,
            memoryUsedMb: $memory->usedMb(),
            memoryFreeMb: $memory->freeMb(),
            queueCount: count($queueCounts),
            groupCount: count($groupCounts),
            packageVersion: $this->packageVersion(),
            queueWorkers: $queueCounts,
            groupWorkers: $groupCounts,
        );

        $this->clusterStore->heartbeat($state);

        $currentLeaderId = $this->clusterStore->leaderId();
        $isLeader = $this->clusterStore->isLeader($state->managerId);

        $this->noteLeadership($isLeader);

        if ($isLeader) {
            $currentLeaderId = $state->managerId;
            $this->dispatchLeaderChanged($currentLeaderId);
            $this->verbose('Cluster leader lease active on this manager', 'debug');

            // Captured HERE, next to the check that says we hold the lease —
            // not down in the publish. An evaluation takes as long as it takes,
            // and a manager that stalls past its lease expiry resumes into a
            // cluster somebody else now leads. Reading the token at publish
            // time hands that manager the NEW leader's token, which satisfies
            // the fencing check in the store and lets a stale leader overwrite
            // the real one's recommendations under its own id. Holding the
            // token we were issued means the fence rejects us instead, which is
            // the whole point of having one.
            $this->evaluateAndPublishClusterRecommendations($this->clusterStore->leaderToken());
        } else {
            $currentLeaderId = $this->clusterStore->leaderId();
            $this->dispatchLeaderChanged($currentLeaderId);
            $leaderText = $currentLeaderId !== null ? $currentLeaderId : 'none';
            $this->verbose("Cluster follower mode; current leader={$leaderText}", 'debug');
        }

        $recommendation = $this->clusterStore->recommendationFor($state->managerId);

        if ($recommendation === null) {
            $this->verbose('No cluster recommendation available yet for this manager', 'debug');

            return;
        }

        $this->applyClusterRecommendation($recommendation);
    }

    private function evaluateAndPublishClusterRecommendations(?string $leaderToken = null): void
    {
        $this->reportMeasuredResources($this->resourceCollector->collect());

        $discovered = $this->discovery->discover();
        $allQueues = $discovered->queues;
        $groups = $discovered->groups;

        $groupedQueueKeys = $this->groupedQueueKeys($groups);
        $metricsByKey = [];

        foreach ($allQueues as $metricsArray) {
            $mappedData = $this->metricsAdapter->mapFields($metricsArray);
            $metrics = QueueMetricsData::fromArray($mappedData);
            $metricsByKey["{$metrics->connection}:{$metrics->queue}"] = $metrics;
        }

        $activeManagers = $this->clusterStore->activeManagers();
        $this->dispatchManagerPresenceChanged($activeManagers);
        $managerIds = array_map(static fn (ClusterManagerState $state): string => $state->managerId, $activeManagers);
        $assignedTotals = array_fill_keys($managerIds, 0);
        $assignments = array_fill_keys($managerIds, []);
        $clusterTotalWorkers = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->totalWorkers, $activeManagers));
        $clusterCapacity = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->maxWorkers, $activeManagers));

        // Phase A: Collect demands for all workloads
        $demands = [];
        $workerConfigs = [];

        /** @var array<string, EvaluatedWorkload> $workloadMeta */
        $workloadMeta = [];

        foreach ($metricsByKey as $queueKey => $metrics) {
            // Discovered names reach a worker's command line on whichever host
            // the target lands, so filter unsafe names here exactly as the
            // single-host loop does. Without this, a recommendation for e.g.
            // an empty-named queue is published every cycle and each host's
            // spawn guard throws on it.
            if (! $this->workloadNameIsSafe($metrics->connection, $metrics->queue)) {
                continue;
            }

            if (AutoscaleConfiguration::isExcluded($metrics->queue) || isset($groupedQueueKeys[$queueKey])) {
                continue;
            }

            // Isolated per workload for the same reason the apply and
            // single-host paths are: one bad config entry, one throwing policy
            // or one unreadable metric would otherwise unwind to the run loop
            // and leave EVERY host in the cluster without a recommendation for
            // the cycle, each holding against a stale one.
            try {
                $config = QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);
                $workloadKey = ClusterRecommendation::queueWorkloadKey($metrics->connection, $metrics->queue);
                $currentWorkers = $this->clusterCurrentWorkers($activeManagers, $workloadKey);
                $targetWorkers = $this->clusterTargetWorkers($config, $metrics, $currentWorkers, $clusterTotalWorkers);
                $targetWorkers = $this->applyClusterScopedPolicies($metrics->connection, $metrics->queue, $currentWorkers, $targetWorkers, $config);

                $demands[$workloadKey] = $targetWorkers;
                $workerConfigs[$workloadKey] = ['min' => $config->workers->min, 'max' => $config->workers->max];
                $workloadMeta[$workloadKey] = new EvaluatedWorkload(
                    isGroup: false,
                    connection: $metrics->connection,
                    name: $metrics->queue,
                    driver: $metrics->driver,
                    config: $config,
                    currentWorkers: $currentWorkers,
                    metrics: $metrics,
                    memberQueues: [$metrics->queue],
                );
            } catch (\Throwable $e) {
                $this->reportWorkloadFailure('queue', $metrics->connection, $metrics->queue, $e);
            }
        }

        foreach ($groups as $group) {
            try {
                $aggregated = $this->metricsAdapter->aggregateGroup($group, $metricsByKey);
                $config = $group->toScalingConfiguration();
                $workloadKey = ClusterRecommendation::groupWorkloadKey($group->connection, $group->name);
                $currentWorkers = $this->clusterCurrentWorkers($activeManagers, $workloadKey);
                $targetWorkers = $this->clusterTargetWorkers($config, $aggregated, $currentWorkers, $clusterTotalWorkers);
                $targetWorkers = $this->applyClusterScopedPolicies($group->connection, $group->name, $currentWorkers, $targetWorkers, $config);

                $demands[$workloadKey] = $targetWorkers;
                $workerConfigs[$workloadKey] = ['min' => $config->workers->min, 'max' => $config->workers->max];
                $workloadMeta[$workloadKey] = new EvaluatedWorkload(
                    isGroup: true,
                    connection: $group->connection,
                    name: $group->name,
                    driver: $aggregated->driver,
                    config: $config,
                    currentWorkers: $currentWorkers,
                    metrics: $aggregated,
                    memberQueues: array_values($group->queues),
                );
            } catch (\Throwable $e) {
                $this->reportWorkloadFailure('group', $group->connection, $group->name, $e);
            }
        }

        // Phase B: Fair-share allocation
        $pinnedDemands = [];
        $scalableDemands = [];
        $scalableConfigs = [];

        foreach ($demands as $workloadKey => $demand) {
            if (! $workloadMeta[$workloadKey]->isScalable()) {
                $pinnedDemands[$workloadKey] = $demand;
            } else {
                $scalableDemands[$workloadKey] = $demand;
                $scalableConfigs[$workloadKey] = $workerConfigs[$workloadKey];
            }
        }

        $scalableCapacity = max($clusterCapacity - array_sum($pinnedDemands), 0);
        // What each workload is running right now, so a manager that has just
        // taken the lease opens its fairness ledger from what it can observe
        // rather than from zero — see FairShareAllocator.
        $observedWorkers = [];

        foreach ($scalableDemands as $workloadKey => $demand) {
            $observedWorkers[$workloadKey] = $workloadMeta[$workloadKey]->currentWorkers;
        }

        $scalableTargets = $this->allocator->allocate(
            $scalableDemands,
            $scalableConfigs,
            $scalableCapacity,
            $observedWorkers,
        );
        $adjustedTargets = $pinnedDemands + $scalableTargets;

        // Phase C consumes this in iteration order and accumulates
        // $assignedTotals as it goes, so the order must be stable across
        // cycles. Workloads arrive here in metrics-discovery order, which is
        // not: the same per-workload split could pass the balance check one
        // cycle and fail it the next purely because workloads evaluated
        // earlier had shifted the running totals differently.
        ksort($adjustedTargets);

        // Phase C: Distribute adjusted targets across hosts, build workload
        // summaries, and record scaling decisions + SLA events.
        $workloads = [];

        $dampedDecisions = $this->dampClusterTargets($adjustedTargets, $workloadMeta, $clusterCapacity);

        foreach ($adjustedTargets as $workloadKey => $targetWorkers) {
            $meta = $workloadMeta[$workloadKey];
            $currentWorkers = $meta->currentWorkers;
            $slaTarget = $meta->config->sla->targetSeconds;
            $isBreaching = $meta->isBreaching();
            $damped = $dampedDecisions[$workloadKey];

            if ($damped->wasHeld) {
                $this->verbose("  ⏸️  Anti-flapping: holding {$workloadKey} at {$damped->targetWorkers} during cooldown", 'debug');
            }

            if ($damped->breachOverride) {
                $this->verbose("  🚨 SLA breach while {$workloadKey} reverses into a cluster scale-up", 'warn');
            }

            $targetWorkers = $damped->targetWorkers;
            $workloadAssignments = $this->distributor->distribute($activeManagers, $workloadKey, $targetWorkers, $assignedTotals);

            foreach ($workloadAssignments as $managerId => $target) {
                $assignments[$managerId][$workloadKey] = $target;
            }

            $workloads[] = [
                'type' => $meta->type(),
                'connection' => $meta->connection,
                'name' => $meta->name,
                'driver' => $meta->driver,
                'current_workers' => $currentWorkers,
                'demand' => $demands[$workloadKey],
                'target_workers' => $targetWorkers,
                'worker_min' => $meta->config->workers->min,
                'worker_max' => $meta->config->workers->max,
                'sla_target_seconds' => $meta->config->sla->targetSeconds,
                'pending' => $meta->metrics->pending,
                'oldest_job_age' => $meta->metrics->oldestJobAge,
                'oldest_job_age_status' => $meta->metrics->ageStatus,
                'throughput_per_minute' => $meta->metrics->throughputPerMinute,
                'active_workers' => $meta->metrics->activeWorkers,
                'utilization_percent' => round($meta->metrics->utilizationRate, 1),
                'member_queues' => $meta->memberQueues,
                'action' => $targetWorkers <=> $currentWorkers,
            ];

            // Record scaling decision and fire events for this workload
            $reason = $targetWorkers > $currentWorkers ? 'cluster:scale_up' : ($targetWorkers < $currentWorkers ? 'cluster:scale_down' : 'cluster:hold');

            $decision = new ScalingDecision(
                connection: $meta->connection,
                queue: $meta->name,
                currentWorkers: $currentWorkers,
                targetWorkers: $targetWorkers,
                reason: $reason,
                slaTarget: $meta->config->sla->targetSeconds,
                scope: ScalingScope::Cluster,
            );

            if (! $decision->shouldHold()) {
                $decisionEntry = [
                    'workload_key' => $workloadKey,
                    'type' => $meta->type(),
                    'connection' => $meta->connection,
                    'name' => $meta->name,
                    'from' => $currentWorkers,
                    'to' => $targetWorkers,
                    'action' => $decision->action(),
                    'reason' => $reason,
                ];

                $this->clusterStore->recordDecision($decisionEntry);
            }

            // Deliberately not ScalingDecisionMade. What the leader produces
            // is a recommendation, not a decision that was acted on — each
            // host still applies its own policies and its own resource ceiling
            // to it. Emitting it here made the event describe a target no host
            // necessarily used. The cluster's own view is published as
            // ClusterSummaryPublished and ClusterScalingSignalUpdated; the
            // per-host decisions arrive as ScalingDecisionMade from each host
            // as it acts.

            // SLA breach/recovery tracking
            $breachKey = $meta->breachKey();
            $wasBreaching = $this->workloadState->wasBreaching($breachKey);

            if ($isBreaching && ! $wasBreaching) {
                event(new SlaBreached(
                    connection: $meta->connection,
                    queue: $meta->name,
                    oldestJobAge: $meta->metrics->oldestJobAge,
                    slaTarget: $slaTarget,
                    pending: $meta->metrics->pending,
                    activeWorkers: $meta->metrics->activeWorkers,
                ));
            } elseif (! $isBreaching && $wasBreaching) {
                event(new SlaRecovered(
                    connection: $meta->connection,
                    queue: $meta->name,
                    currentJobAge: $meta->metrics->oldestJobAge,
                    slaTarget: $slaTarget,
                    pending: $meta->metrics->pending,
                    activeWorkers: $meta->metrics->activeWorkers,
                ));
            }

            $this->workloadState->setBreaching($breachKey, $isBreaching);

            if ($decision->isSlaBreachRisk()) {
                event(new SlaBreachPredicted($decision));
            }
        }

        // Prune cached distributions and cooldown state for workloads no
        // longer present
        $this->distributor->pruneTo($adjustedTargets);
        $this->cooldown->pruneTo($adjustedTargets);

        $issuedAt = $this->currentTimestamp();

        foreach ($managerIds as $managerId) {
            $this->clusterStore->publishRecommendation(
                new ClusterRecommendation(
                    managerId: $managerId,
                    issuedAt: $issuedAt,
                    workloads: $assignments[$managerId],
                    leaderId: AutoscaleConfiguration::managerId(),
                    leaderToken: $leaderToken,
                )
            );
        }

        // Everything from here down is reporting, and all of it lives inside
        // the guard — reading the decision history included. The summary is an
        // artifact ABOUT the scaling; the recommendations above are the work. A
        // throw anywhere in here escapes to the cycle's catch-all, which leaves
        // the enclosing method without applying this manager's OWN
        // recommendation, so a reporting problem becomes a scaling outage on
        // the leader itself.
        try {
            // Fenced with the same token the recommendations were. A manager
            // that stalled past its lease has its recommendation writes
            // rejected by the store, but the summary write is a plain setex —
            // so it would still overwrite the real leader's summary and fire
            // the scale signal that autoscalers outside this package are
            // documented to consume. A stale recommended_hosts is worse than
            // none: something acts on it.
            if ($leaderToken !== null && $this->clusterStore->leaderToken() !== $leaderToken) {
                $this->verbose('  ⏭️  Lease moved during this cycle; not publishing a summary', 'debug');

                return;
            }

            $recentDecisions = $this->clusterStore->recentDecisions(
                AutoscaleConfiguration::decisionHistorySeconds()
            );

            $summary = $this->summaryBuilder->build($activeManagers, $workloads, $recentDecisions);
            $this->clusterStore->publishSummary($summary);

            event(new ClusterSummaryPublished(
                clusterId: Coerce::toString($summary['cluster_id'] ?? null),
                leaderId: Coerce::toString($summary['leader_id'] ?? null),
                summary: $summary,
                publishedAt: $this->currentTimestamp(),
            ));

            $scaleSignal = is_array($summary['scale_signal'] ?? null) ? $summary['scale_signal'] : [];

            event(new ClusterScalingSignalUpdated(
                clusterId: Coerce::toString($summary['cluster_id'] ?? null),
                leaderId: Coerce::toString($summary['leader_id'] ?? null),
                currentHosts: Coerce::toInt($scaleSignal['current_hosts'] ?? 0),
                recommendedHosts: Coerce::toInt($scaleSignal['recommended_hosts'] ?? 0),
                currentCapacity: Coerce::toInt($summary['total_worker_capacity'] ?? 0),
                requiredWorkers: Coerce::toInt($summary['required_workers'] ?? 0),
                action: Coerce::toString($scaleSignal['action'] ?? null, 'hold'),
                reason: Coerce::toString($scaleSignal['reason'] ?? null),
            ));
        } catch (\Throwable $e) {
            Log::channel(AutoscaleConfiguration::logChannel())->error(
                'Cluster summary could not be published; scaling continues',
                ['error' => $e->getMessage()]
            );
        }
    }

    private function applyClusterRecommendation(ClusterRecommendation $recommendation): void
    {
        if ($recommendation->leaderId !== null && $recommendation->leaderId !== $this->clusterStore->leaderId()) {
            $this->verbose(
                "Ignoring stale cluster recommendation from previous leader={$recommendation->leaderId}",
                'debug',
            );

            return;
        }

        if ($recommendation->leaderToken !== null && $recommendation->leaderToken !== $this->clusterStore->leaderToken()) {
            $this->verbose(
                'Ignoring stale cluster recommendation from previous leader lease',
                'debug',
            );

            return;
        }

        $groups = GroupConfiguration::allFromConfig();
        $groupedQueueKeys = $this->groupedQueueKeys($groups);

        foreach ($recommendation->workloads as $workloadKey => $target) {
            if (! str_starts_with($workloadKey, 'queue:')) {
                continue;
            }

            $parts = explode(':', $workloadKey, 3);
            if (count($parts) !== 3) {
                continue;
            }

            [, $connection, $queue] = $parts;

            // The leader may be running an older package version that does
            // not filter discovered names, so a recommendation cannot be
            // trusted to contain only names that are safe to hand to a
            // worker process.
            if (! $this->workloadNameIsSafe($connection, $queue)) {
                continue;
            }

            if (isset($groupedQueueKeys["{$connection}:{$queue}"])) {
                continue;
            }

            if (AutoscaleConfiguration::isExcluded($queue)) {
                continue;
            }

            try {
                $config = QueueConfiguration::fromConfig($connection, $queue);

                if (! $config->workers->scalable) {
                    $rawMetrics = $this->metricsAdapter->forQueue($connection, $queue);
                    $metrics = QueueMetricsData::fromArray($this->metricsAdapter->mapFields($rawMetrics));

                    // Clamped like the scalable paths. A pinned queue's max IS
                    // its pinned count, so an unclamped recommendation could
                    // ask a max-1 queue for thousands.
                    $this->superviseQueue($config, $metrics, $this->clampToLocalMax($target, $config->workers->max, "{$connection}:{$queue}"));

                    continue;
                }

                $this->reconcileQueueTarget($config, $target);
            } catch (\Throwable $e) {
                $this->reportWorkloadFailure('queue', $connection, $queue, $e);
            }
        }

        foreach ($groups as $group) {
            $target = $recommendation->targetForGroup($group->connection, $group->name);

            // A workload the leader did not publish is one it does not know
            // about, not one it wants scaled to zero. Leave it alone rather
            // than draining it.
            if ($target === null) {
                continue;
            }

            try {
                $this->reconcileGroupTarget($group, $target);
            } catch (\Throwable $e) {
                $this->reportWorkloadFailure('group', $group->connection, $group->name, $e);
            }
        }
    }

    /**
     * Damp every workload's allocated target, without letting a hold squat on
     * capacity another workload has been allocated.
     *
     * A hold republishes the last allowed target, which is by definition above
     * the one fair share just handed out — that is what damping IS. Under
     * contention that surplus is not free: fair share has already promised it
     * to somebody else, the distributor hands out hosts in order and simply
     * stops when they fill, and the workload at the end of the queue gets
     * nothing. A scale-up is never held by the damper, so it would arrive at
     * the distributor unblocked and still be starved by a neighbour's refusal
     * to shrink.
     *
     * So the surplus is given back when, and only when, the total no longer
     * fits. Anti-flapping is a preference about the shape of a change; the
     * capacity ceiling is a fact about the hardware, and facts win.
     *
     * @param  array<string, int>  $adjustedTargets
     * @param  array<string, EvaluatedWorkload>  $workloadMeta
     * @return array<string, CooldownDecision>
     */
    private function dampClusterTargets(array $adjustedTargets, array $workloadMeta, int $clusterCapacity): array
    {
        $cooldownSeconds = Coerce::toInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;

        $decisions = [];
        $surpluses = [];

        foreach ($adjustedTargets as $workloadKey => $targetWorkers) {
            $meta = $workloadMeta[$workloadKey];

            // A fuse-forced withdrawal is published immediately rather than
            // damped, same as on the single-host paths. evaluateDemand()
            // applies the fuse ceiling to cluster-wide demand too, so without
            // this the leader keeps every host's share of a failing queue alive
            // for the rest of the window.
            $decisions[$workloadKey] = $this->engine->isFuseConstraining($meta->config)
                ? new CooldownDecision($targetWorkers)
                : $this->cooldown->apply(
                    $workloadKey,
                    $meta->currentWorkers,
                    $targetWorkers,
                    $meta->isBreaching(),
                    $cooldownSeconds,
                );

            $surplus = $decisions[$workloadKey]->targetWorkers - $targetWorkers;

            if ($surplus > 0) {
                $surpluses[$workloadKey] = $surplus;
            }
        }

        $overshoot = array_sum(array_map(
            static fn (CooldownDecision $decision): int => $decision->targetWorkers,
            $decisions,
        )) - $clusterCapacity;

        if ($overshoot <= 0 || $surpluses === []) {
            return $decisions;
        }

        // Biggest squatter first, then by key so two identical clusters do not
        // disagree about who yields.
        uksort($surpluses, static function (string $a, string $b) use ($surpluses): int {
            return ($surpluses[$b] <=> $surpluses[$a]) ?: strcmp($a, $b);
        });

        foreach ($surpluses as $workloadKey => $surplus) {
            if ($overshoot <= 0) {
                break;
            }

            $givenBack = min($surplus, $overshoot);
            $overshoot -= $givenBack;

            $held = $decisions[$workloadKey];
            $decisions[$workloadKey] = new CooldownDecision(
                $held->targetWorkers - $givenBack,
                wasHeld: $held->wasHeld,
                breachOverride: $held->breachOverride,
            );

            $this->verbose(
                "  ⚖️  Anti-flapping yielded {$givenBack} worker(s) on {$workloadKey}: the cluster is at capacity",
                'debug',
            );
        }

        return $decisions;
    }

    private function clusterTargetWorkers(
        QueueConfiguration $config,
        QueueMetricsData $metrics,
        int $currentWorkers,
        int $clusterTotalWorkers,
    ): int {
        if (! $config->workers->scalable) {
            return $config->workers->pinnedCount();
        }

        // Use demand-only evaluation: strategy + config bounds, no system
        // capacity constraint. The leader must see actual demand so it can
        // recommend the right host count and distribute work across all
        // managers. Per-host capacity enforcement happens during distribution
        // (distributeClusterTarget respects each manager's maxWorkers).
        return $this->engine->evaluateDemand($metrics, $config);
    }

    /**
     * Consult cluster-scope policies against a workload's cluster-wide demand.
     *
     * The leader's evaluation is deliberately unconstrained by host capacity,
     * but the constraints operators express through policies are often global
     * by nature: an external API's concurrency ceiling, license seats, a
     * provider rate limit. Those must clamp the cluster total once, here,
     * before distribution: applied only on each host's apply path, a cap of
     * N produces N workers per host instead of N across the cluster.
     *
     * Only policies that implement ClusterScopedPolicy are consulted, so
     * existing policies keep their per-host semantics untouched.
     */
    private function applyClusterScopedPolicies(
        string $connection,
        string $name,
        int $currentWorkers,
        int $targetWorkers,
        QueueConfiguration $config,
    ): int {
        if (! $this->policies->hasClusterScopedPolicies()) {
            return $targetWorkers;
        }

        $decision = $this->policies->beforeScalingClusterScoped(new ScalingDecision(
            connection: $connection,
            queue: $name,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: 'cluster:demand',
            slaTarget: $config->sla->targetSeconds,
            spawnCompensation: $config->spawnCompensation,
            scope: ScalingScope::Cluster,
        ));

        $this->policies->afterScalingClusterScoped($decision);

        return max(0, $decision->targetWorkers);
    }

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     */
    private function clusterCurrentWorkers(array $activeManagers, string $workloadKey): int
    {
        $total = 0;
        [$type, $connection, $name] = explode(':', $workloadKey, 3);

        foreach ($activeManagers as $state) {
            $counts = $type === 'group' ? $state->groupWorkers : $state->queueWorkers;
            $total += (int) ($counts["{$connection}:{$name}"] ?? 0);
        }

        return $total;
    }

    /**
     * Bound a leader-supplied target by this host's own configured maximum.
     *
     * Logged once per workload per breach through the rate limiter rather than
     * every cycle: a leader publishing an impossible number will publish it
     * again next cycle, and the operator needs to see it, not drown in it.
     *
     * The workload is part of the limiter key AND the log context. Without it
     * the suppression is fleet-wide — the limiter is backed by the shared
     * cache cluster mode already requires — so one host would log one
     * unattributable line and every other host and workload would go silent.
     */
    private function clampToLocalMax(int $targetWorkers, int $localMax, string $workload): int
    {
        $target = max(0, $targetWorkers);

        if ($target <= $localMax) {
            return $target;
        }

        if ($this->alerts->allow("cluster_target_above_local_max:{$workload}")) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Cluster recommendation exceeded this host\'s configured maximum; clamped',
                ['workload' => $workload, 'recommended' => $target, 'local_max' => $localMax]
            );
        }

        return $localMax;
    }

    private function reconcileQueueTarget(QueueConfiguration $config, int $targetWorkers): void
    {
        $currentWorkers = $this->pool->count($config->connection, $config->queue);

        // Clamp a remote instruction to this host's own configuration.
        //
        // Redundant while the leader is correct — it already applied
        // workers.max and fair-share. It stops being redundant the moment the
        // leader is not: a bug, a version mismatch mid-rolling-deploy, or
        // anything with write access to the coordination key. The difference
        // is a blast radius of workers.max instead of whatever integer arrived
        // over the wire, and a follower's own config is the last thing that
        // should be overridable from outside the process.
        $targetWorkers = $this->clampToLocalMax($targetWorkers, $config->workers->max, "{$config->connection}:{$config->queue}");

        // No early return when the target already matches. A single host runs
        // the policy chain on every cycle including holds, so a policy there
        // can raise a target the strategy left alone — and can report on a
        // steady queue from afterScaling. Returning here would make both
        // impossible the moment cluster mode was enabled, which is the same
        // asymmetry this whole change exists to remove.
        $decision = new ScalingDecision(
            connection: $config->connection,
            queue: $config->queue,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: 'cluster:recommendation',
            spawnCompensation: $config->spawnCompensation,
        );

        $this->applyDecision(
            $decision,
            fn (ScalingDecision $d) => $this->scaler->scaleUp($d),
            fn (ScalingDecision $d) => $this->scaler->scaleDown($d),
        );
    }

    private function reconcileGroupTarget(GroupConfiguration $group, int $targetWorkers): void
    {
        $currentWorkers = $this->pool->countGroup($group->connection, $group->name);
        $targetWorkers = $this->clampToLocalMax($targetWorkers, $group->workers->max, "group:{$group->connection}:{$group->name}");

        $decision = new ScalingDecision(
            connection: $group->connection,
            queue: $group->name,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: 'cluster:recommendation',
            spawnCompensation: $group->spawnCompensation,
        );

        $this->applyDecision(
            $decision,
            fn (ScalingDecision $d) => $this->scaler->scaleUpGroup($group, $d),
            fn (ScalingDecision $d) => $this->scaler->scaleDownGroup($group, $d),
        );
    }

    /**
     * The one place a scaling decision is put into effect.
     *
     * Every caller goes through here, because the alternative is what caused
     * the defect this replaces: the sequence — consult the policies, act on
     * what they return, tell them what happened — was written out inline at
     * four call sites, and the two cluster ones simply never grew the policy
     * half. A policy was therefore silently inert the moment cluster mode was
     * enabled, with no error and no log line saying it had been skipped.
     *
     * A policy's answer is authoritative: nothing re-clamps afterwards. That
     * is deliberate and documented in docs/advanced-usage/scaling-policies.md.
     *
     * @param  \Closure(ScalingDecision): void  $scaleUp
     * @param  \Closure(ScalingDecision): void  $scaleDown
     */
    private function applyDecision(
        ScalingDecision $decision,
        \Closure $scaleUp,
        \Closure $scaleDown,
        ?string $noActionMessage = null,
    ): ScalingDecision {
        $finalDecision = $this->policies->beforeScaling($decision);

        if ($finalDecision->targetWorkers !== $decision->targetWorkers) {
            $this->verbose(
                "  🔧 Policy modified decision: {$decision->targetWorkers} → {$finalDecision->targetWorkers} workers",
                'info'
            );
        }

        if ($finalDecision->shouldScaleUp()) {
            $scaleUp($finalDecision);
        } elseif ($finalDecision->shouldScaleDown()) {
            $scaleDown($finalDecision);
        } elseif ($noActionMessage !== null) {
            $this->verbose($noActionMessage, 'debug');
        }

        $this->policies->afterScaling($finalDecision);

        // Announced here so the event means the same thing in both modes:
        // this manager decided and acted. The cluster leader used to emit it
        // for the whole fleet with the pre-policy target, which stopped
        // describing what any given host did the moment policies could change
        // that target per host.
        event(new ScalingDecisionMade($finalDecision));

        return $finalDecision;
    }

    /**
     * Record whether this manager holds the lease, discarding stale working
     * memory when it has just taken it.
     *
     * The distribution cache exists so a steady cluster is not reshuffled
     * every cycle. That makes it a leader's working memory: losing the lease
     * and winning it back means another manager has been placing workers in
     * between, so anything remembered from before describes a cluster that no
     * longer exists. Because the cache is only checked for feasibility, a
     * stale layout that still sums to the right total was replayed wholesale —
     * every host churning its workers to match a ten-minute-old picture, for
     * no change in demand.
     */
    private function noteLeadership(bool $isLeader): void
    {
        if ($isLeader && ! $this->wasLeader) {
            $this->distributor->reset();

            // The cooldown memory is the same kind of stale working memory as
            // the distribution cache: another leader has been publishing in
            // between, so directions and targets remembered from the previous
            // lease describe a cluster that no longer exists. The cost of a
            // failover is one undamped cycle.
            $this->cooldown->reset();
        }

        $this->wasLeader = $isLeader;
    }

    /**
     * Warn when leadership is moving faster than the guards can rebuild.
     *
     * Measured against the anti-flapping window, because that is the yardstick
     * every piece of discarded state is sized in: the damping window is exactly
     * that long, and the fairness ledger needs a comparable stretch to reach
     * its first hand-over. Leadership changing several times inside one such
     * window means none of them ever completes — placement restarts from
     * nothing, scale-downs stop being damped, and the workload that has been
     * starved longest loses its claim to be served next. Measured: with
     * leadership moving every eleven cycles, two of six contending queues went
     * back to never being served at all.
     *
     * This is the disease; the guards degrading are the symptom. Saying so is
     * cheaper and more useful than making each guard survive independently.
     */
    /**
     * Say on the console that a cycle failed, not only in the log file.
     *
     * A cycle that throws is caught so one bad workload cannot take the daemon
     * down, and the failure went to the configured log channel alone. That
     * makes the worst case invisible: a manager whose EVERY cycle fails —
     * an unreachable cache, a database the metrics package cannot read — prints
     * its start-up banner and then nothing, looks entirely healthy, and does
     * nothing at all. It is also the likeliest moment for it to happen, because
     * that is what a fresh misconfiguration looks like.
     *
     * Throttled in-process rather than through the alert limiter, which is
     * backed by the cache: a cache failure is one of the things this has to be
     * able to report, and a reporter that depends on the failing component
     * reports nothing.
     */
    private function reportCycleFailureToConsole(\Throwable $e): void
    {
        $now = microtime(true);

        if ($this->cycleFailureReportedAt !== null
            && ($now - $this->cycleFailureReportedAt) < self::CYCLE_FAILURE_REPORT_INTERVAL_SECONDS) {
            return;
        }

        $this->cycleFailureReportedAt = $now;

        // Not verbose(): that is gated on -v, and this is exactly the message
        // an operator needs when they did not think to ask for detail.
        $this->reporter->error('⚠️  Evaluation cycle failed: '.$e->getMessage().' ('.$e::class.')');
    }

    private function noteLeadershipChange(): void
    {
        $window = Coerce::toInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;
        $now = microtime(true);

        $this->leaderChanges[] = $now;
        $this->leaderChanges = array_values(array_filter(
            $this->leaderChanges,
            static fn (float $observedAt): bool => ($now - $observedAt) <= $window,
        ));

        if (count($this->leaderChanges) < self::UNSTABLE_LEADERSHIP_CHANGES) {
            return;
        }

        if (! $this->alerts->allow('cluster_leadership_unstable')) {
            return;
        }

        Log::channel(AutoscaleConfiguration::logChannel())->warning(
            'Cluster leadership is changing faster than the scaling guards can rebuild',
            [
                'changes_observed' => count($this->leaderChanges),
                'window_seconds' => $window,
                'current_leader' => $this->lastObservedLeaderId,
                'observed_by' => AutoscaleConfiguration::managerId(),
                'consequence' => 'worker placement, anti-flapping damping and fair-share rotation '
                    .'each restart on every change, so none of them completes',
                'remedy' => 'raise cluster.leader_lease_seconds above the time a slow evaluation '
                    .'cycle can take, or find why the leader keeps missing its renewal',
            ]
        );
    }

    private function currentTimestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function assertClusterReady(): void
    {
        try {
            $this->clusterStore->ping();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Cluster mode requires a working Redis connection for coordination. '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    private function packageVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        if (! InstalledVersions::isInstalled('cboxdk/laravel-queue-autoscale')) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion('cboxdk/laravel-queue-autoscale') ?? 'unknown';
    }

    /**
     * @param  list<MeasuredResourceSample>  $samples
     */
    private function reportMeasuredResources(array $samples): void
    {
        foreach ($samples as $sample) {
            $this->verbose(sprintf(
                '  Measured resources [%s]: cpu=%.3f cores (%d samples), mem=%.1f MB (%d samples)',
                $sample->workloadKey(),
                $sample->cpuCores,
                $sample->cpuSamples,
                $sample->memoryMb,
                $sample->memorySamples,
            ), 'debug');
        }
    }

    private function beginEvaluationCycle(): void
    {
        $this->capacity->invalidateCache();

        // Per-queue state is keyed by queue name and the manager runs for
        // weeks. An application that generates queue names per tenant will
        // therefore accumulate one entry per tenant that has ever dispatched a
        // job, in a process that never restarts to shed them. currentQueueStats
        // has a second problem: it is what the renderer draws, so a queue that
        // stopped existing kept being displayed forever.
        $this->currentQueueStats = [];

        $this->forgetQueuesNotSeenRecently();
    }

    /**
     * Drop per-queue bookkeeping for queues that have gone quiet.
     *
     * Bounded rather than cleared: the anti-flapping window and the breach
     * state are what stop a queue oscillating, so discarding them every cycle
     * would defeat both. A queue nothing has been recorded about within the
     * retention window has nothing left worth remembering.
     *
     * Quiet means unseen, not unscaled. A leader records breach state for every
     * workload it discovers and never scales any of them itself, so a sweep
     * driven by the last scaling action skipped its map entirely.
     */
    private function forgetQueuesNotSeenRecently(): void
    {
        $this->workloadState->forgetQuietSince(now()->subSeconds(self::QUEUE_STATE_RETENTION_SECONDS));
    }

    private function evaluateAndScale(): void
    {
        $this->beginEvaluationCycle();

        $this->reportMeasuredResources($this->resourceCollector->collect());

        $discovered = $this->discovery->discover();
        $allQueues = $discovered->queues;
        $groups = $discovered->groups;

        // Build a set of queue names that are owned by groups so we skip
        // them in the per-queue loop (they are handled via evaluateGroup).
        $groupedQueueKeys = $this->groupedQueueKeys($groups);

        // Collect metrics DTOs keyed by connection:queue for group aggregation.
        /** @var array<string, QueueMetricsData> $metricsByKey */
        $metricsByKey = [];

        foreach ($allQueues as $queueKey => $metricsArray) {
            // Map field names from API response to DTO format
            $mappedData = $this->metricsAdapter->mapFields($metricsArray);

            // Convert array to QueueMetricsData DTO
            $metrics = QueueMetricsData::fromArray($mappedData);
            $metricsByKey["{$metrics->connection}:{$metrics->queue}"] = $metrics;

            // Discovered names reach a worker's command line, so a name that
            // would change what the worker does never gets that far.
            if (! $this->workloadNameIsSafe($metrics->connection, $metrics->queue)) {
                continue;
            }

            // Skip queues the operator has explicitly excluded from autoscaling.
            if (AutoscaleConfiguration::isExcluded($metrics->queue)) {
                $this->announceExclusion($metrics->connection, $metrics->queue);

                continue;
            }

            // Skip queues that are managed by a group — evaluateGroup handles them.
            if (isset($groupedQueueKeys["{$metrics->connection}:{$metrics->queue}"])) {
                continue;
            }

            try {
                $this->evaluateQueue($metrics->connection, $metrics->queue, $metrics);
            } catch (\Throwable $e) {
                $this->reportWorkloadFailure('queue', $metrics->connection, $metrics->queue, $e);
            }
        }

        // Evaluate each group exactly once per cycle using aggregated metrics.
        foreach ($groups as $group) {
            try {
                $this->evaluateGroup($group, $metricsByKey);
            } catch (\Throwable $e) {
                $this->reportWorkloadFailure('group', $group->connection, $group->name, $e);
            }
        }
    }

    /**
     * Build a lookup table of connection:queue strings claimed by any group.
     *
     * @param  array<string, GroupConfiguration>  $groups
     * @return array<string, true>
     */
    private function groupedQueueKeys(array $groups): array
    {
        $keys = [];

        foreach ($groups as $group) {
            foreach ($group->queues as $queue) {
                $keys["{$group->connection}:{$queue}"] = true;
            }
        }

        return $keys;
    }

    /**
     * Track queues we have already announced as excluded, so we log once
     * per-process rather than on every evaluation cycle.
     *
     * @var array<string, true>
     */
    private array $announcedExclusions = [];

    /** @var array<string, true> */
    private array $rejectedWorkloadNames = [];

    /**
     * Whether a discovered workload can safely be handed to a worker process.
     *
     * Rejection is announced once per name rather than every cycle: a queue
     * that cannot be scaled is worth one loud line, not a log flood.
     */
    private function workloadNameIsSafe(string $connection, string $queue): bool
    {
        if (WorkloadName::isSafe($connection) && WorkloadName::isSafe($queue)) {
            return true;
        }

        $key = "{$connection}\0{$queue}";

        if (! isset($this->rejectedWorkloadNames[$key])) {
            $this->rejectedWorkloadNames[$key] = true;

            $offender = WorkloadName::isSafe($queue) ? $connection : $queue;

            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Refusing to manage a queue whose name cannot be passed to a worker safely',
                [
                    'connection' => $connection,
                    'queue' => $queue,
                    'reason' => WorkloadName::reason($offender),
                ]
            );
        }

        return false;
    }

    /**
     * Log a workload-level failure without letting it abort the cycle.
     *
     * Before this guard existed, one queue that could not be reconciled
     * (a malformed discovered name, a transient spawn failure) unwound to
     * the run loop's catch-all and silently skipped every other workload
     * in that cycle: nothing scaled up and exited workers were never
     * respawned until the offending workload aged out of discovery.
     */
    private function reportWorkloadFailure(string $type, string $connection, string $name, \Throwable $e): void
    {
        Log::channel(AutoscaleConfiguration::logChannel())->error(
            'Workload evaluation failed; continuing with remaining workloads',
            [
                'type' => $type,
                'connection' => $connection,
                'name' => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]
        );
    }

    private function announceExclusion(string $connection, string $queue): void
    {
        $key = "{$connection}:{$queue}";

        if (isset($this->announcedExclusions[$key])) {
            return;
        }

        $this->announcedExclusions[$key] = true;

        $this->verbose("Skipping excluded queue: {$key}", 'debug');

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Queue excluded from autoscaling',
            ['connection' => $connection, 'queue' => $queue]
        );
    }

    private function evaluateQueue(string $connection, string $queue, QueueMetricsData $metrics): void
    {
        $this->verbose("Evaluating queue: {$connection}:{$queue}", 'debug');
        $this->verbose("  Metrics: pending={$metrics->pending}, oldest_age={$metrics->oldestJobAge}s, active_workers={$metrics->activeWorkers}, throughput={$metrics->throughputPerMinute}/min", 'debug');

        // 1. Get configuration
        $config = QueueConfiguration::fromConfig($connection, $queue);

        // Non-scalable queues (e.g. ExclusiveProfile) bypass the scaling engine
        // entirely. We act as a process supervisor: ensure pinned worker count,
        // respawn on death, but never react to load signals.
        if (! $config->workers->scalable) {
            $this->superviseQueue($config, $metrics);

            return;
        }

        // Warn if throughput data unavailable (needs historical data)
        if ($metrics->throughputPerMinute === 0.0 && $metrics->activeWorkers > 0) {
            $this->verbose('  ⚠️  Throughput=0 despite active workers - metrics package needs more historical data', 'debug');
        }

        // 2. Count current workers (per-queue and total pool)
        $currentWorkers = $this->pool->count($connection, $queue);
        $totalPoolWorkers = $this->pool->totalCount();
        $this->verbose("  Current workers: {$currentWorkers} (total pool: {$totalPoolWorkers})", 'debug');

        // 3. Calculate scaling decision (total pool count ensures capacity is shared across queues)
        $decision = $this->engine->evaluate($metrics, $config, $currentWorkers, $totalPoolWorkers);

        // 4. Check for SLA breach
        $isBreaching = $metrics->oldestJobAge > 0 && $metrics->oldestJobAge >= $config->sla->targetSeconds;

        if ($isBreaching) {
            $this->verbose("  🚨 SLA BREACH: oldest_age={$metrics->oldestJobAge}s >= SLA={$config->sla->targetSeconds}s", 'error');
        }

        // 5. Anti-flapping check: hold a scale-DOWN that reverses a recent
        // scale-up. A scale-up is never held — see WorkloadStateTracker.
        $key = "{$connection}:{$queue}";
        $currentDirection = $decision->shouldScaleUp() ? 'up' : ($decision->shouldScaleDown() ? 'down' : 'hold');
        $scaleCooldownSeconds = Coerce::toInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;

        // A withdrawal the fuse forced is never damped. Failures look like load,
        // so the fleet has usually just scaled UP when the fuse trips — exactly
        // the state in which the damper would hold the withdrawal, leaving a
        // full-size fleet hammering a dead dependency for the rest of the
        // window on top of the fuse's own detection latency.
        // holdsReversal() first: it clears a direction that has outlived its
        // window as a side effect, and that has to happen every cycle. The fuse
        // is only consulted when a hold is actually imminent.
        if ($this->workloadState->holdsReversal($key, $currentDirection, $scaleCooldownSeconds)
            && ! $this->engine->isFuseConstraining($config)) {
            $remaining = $this->workloadState->cooldownRemaining($key, $scaleCooldownSeconds);
            $this->verbose("  ⏸️  Anti-flapping: cannot reverse into a scale-down during cooldown ({$remaining}s remaining)", 'debug');

            return;
        }

        // Read AFTER the check, which drops a direction that has outlived its
        // window. Reading first would report a reversal against a move from
        // minutes ago, and disagree with the cluster path on the same facts.
        if ($currentDirection === 'up' && $isBreaching && $this->workloadState->lastDirection($key) === 'down') {
            $this->verbose('  🚨 SLA breach during a reversing scale-up', 'warn');
        }

        // Log scaling recommendation
        if ($decision->shouldScaleUp() || $decision->shouldScaleDown()) {
            $this->verbose("  📊 Scaling recommended: current={$currentWorkers} → target={$decision->targetWorkers}", 'debug');
        }

        // 6. Display decision
        $this->verbose("  📊 Decision: {$currentWorkers} → {$decision->targetWorkers} workers", 'info');
        $this->verbose("     Reason: {$decision->reason}", 'info');
        $this->logFuseHold($decision);

        if ($decision->predictedPickupTime !== null) {
            $this->verbose("     Predicted pickup time: {$decision->predictedPickupTime}s (SLA: {$decision->slaTarget}s)", 'info');
        }

        // 6a. Display capacity breakdown in -vvv mode
        if ($decision->capacity !== null && $this->isVeryVerbose()) {
            $this->verbose('     ━━━ Capacity Breakdown ━━━', 'debug');
            foreach ($decision->capacity->getFormattedDetails() as $label => $detail) {
                $this->verbose("     {$label}: {$detail}", 'debug');
            }

            // Explain the capacity factor
            // Exhaustive: a new limiting factor is a compile error here
            // rather than a silently missing explanation. The fuse case used
            // to be absent, so verbose mode printed nothing at all while a
            // queue was being held down.
            $factor = $decision->capacity->limitingFactor;
            [$level, $icon] = match ($factor) {
                LimitingFactor::Cpu,
                LimitingFactor::Memory,
                LimitingFactor::Balanced,
                LimitingFactor::SystemMetricsUnavailable => ['warn', '⚠️ '],
                LimitingFactor::Fuse => ['warn', '🔌'],
                LimitingFactor::Config => ['warn', '⚠️ '],
                LimitingFactor::Strategy => ['debug', '✓'],
            };

            $this->verbose("     {$icon} ".ucfirst($factor->description()), $level);
        }

        // 6b. Store queue stats for renderer
        $slaStatus = $isBreaching ? 'breached' : ($metrics->oldestJobAge > $config->sla->targetSeconds * 0.8 ? 'warning' : 'ok');
        $this->currentQueueStats[$key] = new QueueStats(
            connection: $connection,
            queue: $queue,
            depth: $metrics->pending,
            pending: $metrics->pending,
            throughputPerMinute: $metrics->throughputPerMinute,
            oldestJobAge: $metrics->oldestJobAge,
            slaTarget: $config->sla->targetSeconds,
            slaStatus: $slaStatus,
            activeWorkers: $currentWorkers,
            targetWorkers: $decision->targetWorkers,
            reserved: $metrics->reserved,
            scheduled: $metrics->scheduled,
        );

        // 7-9. Consult the policies, act on what they return, tell them what
        // happened. Shared with the cluster path so the two cannot drift.
        $finalDecision = $this->applyDecision(
            $decision,
            fn (ScalingDecision $d) => $this->scaler->scaleUp($d),
            fn (ScalingDecision $d) => $this->scaler->scaleDown($d),
            noActionMessage: '  ✓ No scaling action needed',
        );

        // 10. Broadcast the remaining events using the final decision
        if ($finalDecision->isSlaBreachRisk()) {
            $this->verbose('  ⚠️  SLA BREACH RISK DETECTED!', 'warn');
            event(new SlaBreachPredicted($finalDecision));
        }

        // Track SLA breach state and fire breach/recovery events
        $wasBreaching = $this->workloadState->wasBreaching($key);

        if ($isBreaching && ! $wasBreaching) {
            // Entering breach state - fire SlaBreached
            event(new SlaBreached(
                connection: $config->connection,
                queue: $config->queue,
                oldestJobAge: $metrics->oldestJobAge,
                slaTarget: $config->sla->targetSeconds,
                pending: $metrics->pending,
                activeWorkers: $metrics->activeWorkers,
            ));
            $this->workloadState->setBreaching($key, true);
        } elseif (! $isBreaching && $wasBreaching) {
            // Recovering from breach - fire SlaRecovered
            event(new SlaRecovered(
                connection: $config->connection,
                queue: $config->queue,
                currentJobAge: $metrics->oldestJobAge,
                slaTarget: $config->sla->targetSeconds,
                pending: $metrics->pending,
                activeWorkers: $metrics->activeWorkers,
            ));
            $this->workloadState->setBreaching($key, false);
        } elseif ($isBreaching) {
            // Update breach state (still breaching)
            $this->workloadState->setBreaching($key, true);
        } else {
            // Update breach state (not breaching)
            $this->workloadState->setBreaching($key, false);
        }

        // 11. Update last scale time and direction
        //
        // Record what the fleet ACTUALLY did, not what the engine proposed. A
        // policy may flip the decision — a headroom rule turning a withdrawal
        // into a rise, a cost cap doing the reverse, or either escalating a
        // hold into a real move. Recording the engine's direction against the
        // policy's outcome then damps the wrong thing: after a flipped
        // down-to-up, the next genuine scale-down reads as same-direction and
        // passes undamped, killing the workers just spawned.
        if (! $finalDecision->shouldHold()) {
            $this->workloadState->recordScale($key, $finalDecision->shouldScaleUp() ? 'up' : 'down');
        }
    }

    /**
     * Evaluate a group: aggregate per-member metrics, feed the ScalingEngine
     * with the group treated as a single logical queue, then spawn/terminate
     * multi-queue workers accordingly.
     *
     * @param  array<string, QueueMetricsData>  $metricsByKey  connection:queue => metrics
     */
    private function evaluateGroup(GroupConfiguration $group, array $metricsByKey): void
    {
        $key = "group:{$group->connection}:{$group->name}";
        $this->verbose("Evaluating group: {$group->name} [{$group->queueArgument()}]", 'debug');

        $aggregated = $this->metricsAdapter->aggregateGroup($group, $metricsByKey);

        $currentWorkers = $this->pool->countGroup($group->connection, $group->name);
        $totalPoolWorkers = $this->pool->totalCount();
        $this->verbose("  Current group workers: {$currentWorkers} (total pool: {$totalPoolWorkers})", 'debug');

        $config = $group->toScalingConfiguration();
        $decision = $this->engine->evaluate($aggregated, $config, $currentWorkers, $totalPoolWorkers);

        $isBreaching = $aggregated->oldestJobAge > 0 && $aggregated->oldestJobAge >= $group->sla->targetSeconds;

        if ($isBreaching) {
            $this->verbose("  🚨 GROUP SLA BREACH: worst oldest_age={$aggregated->oldestJobAge}s >= SLA={$group->sla->targetSeconds}s", 'error');
        }

        // Anti-flapping check (same semantics as per-queue).
        $currentDirection = $decision->shouldScaleUp() ? 'up' : ($decision->shouldScaleDown() ? 'down' : 'hold');
        $scaleCooldownSeconds = Coerce::toInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;

        // Fuse-forced withdrawals bypass the damper — see evaluateQueue.
        if ($this->workloadState->holdsReversal($key, $currentDirection, $scaleCooldownSeconds)
            && ! $this->engine->isFuseConstraining($config)) {
            $remaining = $this->workloadState->cooldownRemaining($key, $scaleCooldownSeconds);
            $this->verbose("  ⏸️  Anti-flapping (group): cannot reverse into a scale-down during cooldown ({$remaining}s remaining)", 'debug');

            return;
        }

        $this->verbose("  📊 Group decision: {$currentWorkers} → {$decision->targetWorkers} workers", 'info');
        $this->verbose("     Reason: {$decision->reason}", 'info');
        $this->logFuseHold($decision);

        $slaStatus = $isBreaching ? 'breached' : ($aggregated->oldestJobAge > $group->sla->targetSeconds * 0.8 ? 'warning' : 'ok');
        $this->currentQueueStats[$key] = new QueueStats(
            connection: $group->connection,
            queue: "[group] {$group->name}",
            depth: $aggregated->pending,
            pending: $aggregated->pending,
            throughputPerMinute: $aggregated->throughputPerMinute,
            oldestJobAge: $aggregated->oldestJobAge,
            slaTarget: $group->sla->targetSeconds,
            slaStatus: $slaStatus,
            activeWorkers: $currentWorkers,
            targetWorkers: $decision->targetWorkers,
            reserved: $aggregated->reserved,
            scheduled: $aggregated->scheduled,
        );

        $finalDecision = $this->applyDecision(
            $decision,
            fn (ScalingDecision $d) => $this->scaler->scaleUpGroup($group, $d),
            fn (ScalingDecision $d) => $this->scaler->scaleDownGroup($group, $d),
            noActionMessage: '  ✓ No group scaling action needed',
        );

        if ($finalDecision->isSlaBreachRisk()) {
            $this->verbose('  ⚠️  GROUP SLA BREACH RISK DETECTED!', 'warn');
            event(new SlaBreachPredicted($finalDecision));
        }

        // SLA breach state for groups mirrors the per-queue event flow.
        $wasBreaching = $this->workloadState->wasBreaching($key);

        if ($isBreaching && ! $wasBreaching) {
            event(new SlaBreached(
                connection: $group->connection,
                queue: $group->name,
                oldestJobAge: $aggregated->oldestJobAge,
                slaTarget: $group->sla->targetSeconds,
                pending: $aggregated->pending,
                activeWorkers: $currentWorkers,
            ));
        } elseif (! $isBreaching && $wasBreaching) {
            event(new SlaRecovered(
                connection: $group->connection,
                queue: $group->name,
                currentJobAge: $aggregated->oldestJobAge,
                slaTarget: $group->sla->targetSeconds,
                pending: $aggregated->pending,
                activeWorkers: $currentWorkers,
            ));
        }

        $this->workloadState->setBreaching($key, $isBreaching);

        // The direction that actually happened — see evaluateQueue.
        if (! $finalDecision->shouldHold()) {
            $this->workloadState->recordScale($key, $finalDecision->shouldScaleUp() ? 'up' : 'down');
        }
    }

    private function superviseQueue(QueueConfiguration $config, QueueMetricsData $metrics, ?int $clusterTarget = null): void
    {
        $connection = $config->connection;
        $queue = $config->queue;
        $key = "{$connection}:{$queue}";
        $target = $clusterTarget ?? $config->workers->pinnedCount();
        // liveCount, not count: a pinned queue exists because two workers on
        // it at once would be wrong, and a worker draining toward exit is
        // still on it. Counting only non-terminating workers here would spawn
        // a replacement alongside the one still finishing its job.
        $current = $this->pool->liveCount($connection, $queue);

        // A pinned queue is still a scaling decision, so the policies see it.
        // They can rarely move it — min equals max by definition — but a
        // policy that reports, alerts or refuses on a queue's behalf should
        // not fall silent just because that queue happens to be pinned. This
        // was the last path that skipped the chain.
        $decision = $this->policies->beforeScaling(new ScalingDecision(
            connection: $connection,
            queue: $queue,
            currentWorkers: $current,
            targetWorkers: $target,
            reason: 'supervisor:pinned',
            spawnCompensation: $config->spawnCompensation,
        ));

        $target = max(0, $decision->targetWorkers);

        $this->verbose("  🔒 Exclusive/pinned queue: enforcing {$target} worker(s), current={$current}", 'debug');

        // Track SLA breach state for events, even though we cannot scale to fix it.
        $isBreaching = $metrics->oldestJobAge > 0 && $metrics->oldestJobAge >= $config->sla->targetSeconds;

        if ($isBreaching) {
            $this->verbose("  🚨 SLA BREACH on pinned queue: oldest_age={$metrics->oldestJobAge}s >= SLA={$config->sla->targetSeconds}s", 'error');
        }

        if ($current < $target) {
            // Clamped like every other spawn path. Queues are discovered, so
            // without this the host ceiling is simply not enforced here.
            $toAdd = $this->scaler->clampToHostCeiling($target - $current);

            if ($toAdd === 0) {
                $this->policies->afterScaling($decision);

                return;
            }

            $this->verbose("  ⬆️  Supervisor respawn: spawning {$toAdd} worker(s)", 'info');

            $this->scaler->recordScaling(sprintf(
                '[%s] %s:%s supervisor respawn %d -> %d',
                now()->format('H:i:s'),
                $connection,
                $queue,
                $current,
                $target
            ));

            $workers = $this->spawner->spawn(
                $connection,
                $queue,
                $toAdd,
                $config->spawnCompensation,
                workerConfig: $config->workers,
            );

            foreach ($workers as $worker) {
                $this->verbose("     ✓ Worker spawned: PID {$worker->pid()}", 'info');
            }

            $this->pool->addMany($workers);

            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Supervisor respawned pinned workers',
                [
                    'connection' => $connection,
                    'queue' => $queue,
                    'from' => $current,
                    'to' => $target,
                ]
            );

            event(new WorkersScaled(
                connection: $connection,
                queue: $queue,
                from: $current,
                to: $target,
                action: 'up',
                reason: 'supervisor:respawn',
            ));
        } elseif ($current > $target) {
            $toRemove = $current - $target;
            $this->verbose("  ⬇️  Supervisor trim: terminating {$toRemove} excess worker(s)", 'info');

            $workers = $this->pool->getTerminatable($connection, $queue, $toRemove);

            foreach ($workers as $worker) {
                $this->terminator->requestTermination($worker);
            }

            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Supervisor trimmed pinned workers',
                [
                    'connection' => $connection,
                    'queue' => $queue,
                    'from' => $current,
                    'to' => $target,
                ]
            );

            event(new WorkersScaled(
                connection: $connection,
                queue: $queue,
                from: $current,
                to: $target,
                action: 'down',
                reason: 'supervisor:trim',
            ));
        }

        // Keep queue stats fresh so the renderer shows pinned queues too.
        $slaStatus = $isBreaching ? 'breached' : ($metrics->oldestJobAge > $config->sla->targetSeconds * 0.8 ? 'warning' : 'ok');
        $this->currentQueueStats[$key] = new QueueStats(
            connection: $connection,
            queue: $queue,
            depth: $metrics->pending,
            pending: $metrics->pending,
            throughputPerMinute: $metrics->throughputPerMinute,
            oldestJobAge: $metrics->oldestJobAge,
            slaTarget: $config->sla->targetSeconds,
            slaStatus: $slaStatus,
            activeWorkers: $current,
            targetWorkers: $target,
            reserved: $metrics->reserved,
            scheduled: $metrics->scheduled,
        );

        // Fire SLA events even though we can't scale — operators need to know.
        $wasBreaching = $this->workloadState->wasBreaching($key);

        if ($isBreaching && ! $wasBreaching) {
            event(new SlaBreached(
                connection: $connection,
                queue: $queue,
                oldestJobAge: $metrics->oldestJobAge,
                slaTarget: $config->sla->targetSeconds,
                pending: $metrics->pending,
                activeWorkers: $current,
            ));
        } elseif (! $isBreaching && $wasBreaching) {
            event(new SlaRecovered(
                connection: $connection,
                queue: $queue,
                currentJobAge: $metrics->oldestJobAge,
                slaTarget: $config->sla->targetSeconds,
                pending: $metrics->pending,
                activeWorkers: $current,
            ));
        }

        $this->workloadState->setBreaching($key, $isBreaching);

        $this->policies->afterScaling($decision);
    }

    private function processWorkerOutput(): void
    {
        $workers = $this->pool->all();

        // Drain both streams unconditionally: reading is what stops each
        // worker's output accumulating inside the manager for the worker's
        // lifetime, so it must not depend on a renderer being attached.
        $outputLines = $this->outputBuffer->collectOutput($workers);
        $errorLines = $this->outputBuffer->collectErrorOutput($workers);

        if ($this->renderer !== null) {
            foreach ($outputLines as $pid => $lines) {
                foreach ($lines as $line) {
                    $this->renderer->handleWorkerOutput($pid, $line);
                }
            }
        }

        // Worker stderr carries what an operator needs during an incident:
        // job exceptions, memory warnings, and for containerized apps
        // typically the whole application log channel. Forward it to the
        // manager's log channel so it reaches the container's log stream
        // instead of dying in a buffer nothing reads.
        foreach ($errorLines as $pid => $lines) {
            foreach ($lines as $line) {
                Log::channel(AutoscaleConfiguration::logChannel())->info("[worker {$pid}] {$line}");
            }
        }
    }

    private function renderOutput(): void
    {
        if ($this->renderer === null) {
            return;
        }

        $outputData = $this->buildOutputData();
        $this->renderer->render($outputData);

        $this->scaler->clearScalingLog();
    }

    private function buildOutputData(): OutputData
    {
        $workers = [];
        $id = 1;
        foreach ($this->pool->all() as $worker) {
            $workers[$id] = new WorkerStatus(
                id: $id,
                pid: $worker->pid(),
                connection: $worker->connection,
                queue: $worker->queue,
                status: $worker->isTerminating() ? 'terminating' : ($worker->isRunning() ? 'running' : 'dead'),
                uptimeSeconds: $worker->uptimeSeconds(),
            );
            $id++;
        }

        return new OutputData(
            queueStats: $this->currentQueueStats,
            workers: $workers,
            scalingLog: $this->scaler->scalingLog(),
            timestamp: new \DateTimeImmutable,
        );
    }

    private function shutdown(): void
    {
        $workerCount = count($this->pool->all());

        $this->verbose('🛑 Shutting down autoscale manager', 'info');
        $this->verbose("   Terminating {$workerCount} worker(s)...", 'info');

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Shutting down autoscale manager, terminating all workers'
        );

        $this->terminator->terminateAll($this->pool->all(), function (WorkerProcess $worker): void {
            $this->verbose("   ✓ Terminating worker: PID {$worker->pid()}", 'info');
        });

        $this->leaveCluster();

        $this->renderer?->shutdown();

        event(new AutoscaleManagerStopped(
            managerId: AutoscaleConfiguration::managerId(),
            host: AutoscaleConfiguration::hostLabel(),
            clusterEnabled: AutoscaleConfiguration::clusterEnabled(),
            clusterId: AutoscaleConfiguration::clusterEnabled() ? AutoscaleConfiguration::clusterAppId() : '',
            startedAt: $this->startedAt,
            stoppedAt: $this->currentTimestamp(),
            reason: $this->stopReason ?? 'shutdown',
            workerCount: $workerCount,
            packageVersion: $this->packageVersion(),
        ));

        $this->verbose('✓ Shutdown complete', 'info');
    }

    private function dispatchLeaderChanged(?string $currentLeaderId): void
    {
        if ($currentLeaderId === $this->lastObservedLeaderId) {
            return;
        }

        $previousLeaderId = $this->lastObservedLeaderId;

        event(new ClusterLeaderChanged(
            clusterId: AutoscaleConfiguration::clusterAppId(),
            previousLeaderId: $this->lastObservedLeaderId,
            currentLeaderId: $currentLeaderId,
            observedByManagerId: AutoscaleConfiguration::managerId(),
            changedAt: $this->currentTimestamp(),
        ));

        $this->lastObservedLeaderId = $currentLeaderId;

        // Not the first sighting. Starting up and discovering who leads is not
        // a change, and counting it would make every restart look unstable.
        if ($previousLeaderId !== null) {
            $this->noteLeadershipChange();
        }
    }

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     */
    private function dispatchManagerPresenceChanged(array $activeManagers): void
    {
        $managerIds = array_values(array_map(static fn (ClusterManagerState $state): string => $state->managerId, $activeManagers));
        sort($managerIds);

        if ($managerIds === $this->lastObservedManagerIds) {
            return;
        }

        $addedManagerIds = array_values(array_diff($managerIds, $this->lastObservedManagerIds));
        $removedManagerIds = array_values(array_diff($this->lastObservedManagerIds, $managerIds));
        sort($addedManagerIds);
        sort($removedManagerIds);

        event(new ClusterManagerPresenceChanged(
            clusterId: AutoscaleConfiguration::clusterAppId(),
            managerIds: $managerIds,
            addedManagerIds: $addedManagerIds,
            removedManagerIds: $removedManagerIds,
            leaderId: AutoscaleConfiguration::managerId(),
            observedByManagerId: AutoscaleConfiguration::managerId(),
            observedAt: $this->currentTimestamp(),
        ));

        $this->lastObservedManagerIds = $managerIds;
    }
}
