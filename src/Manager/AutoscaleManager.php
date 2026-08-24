<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Manager;

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterCooldown;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
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
use Cbox\LaravelQueueAutoscale\Support\Coerce;
use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Cbox\LaravelQueueAutoscale\Support\WorkloadName;
use Cbox\LaravelQueueAutoscale\Workers\OrphanedWorkerReaper;
use Cbox\LaravelQueueAutoscale\Workers\WorkerOutputBuffer;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Composer\InstalledVersions;
use Illuminate\Support\Carbon;
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

    /**
     * @var array<string, Carbon>
     */
    private array $lastScaleTime = [];

    /**
     * @var array<string, string>
     */
    private array $lastScaleDirection = [];

    /**
     * @var array<string, bool>
     */
    private array $breachState = [];

    /**
     * Tri-state cache for group-config validation:
     *   null  = not yet attempted
     *   true  = validated OK, safe to evaluate groups
     *   false = validation failed — skip groups for the rest of this process.
     *
     * Validating on every cycle would spam the log when config is bad and waste
     * work when it is good. We cache the outcome and the operator restarts the
     * manager after fixing the config.
     */
    private ?bool $groupsValid = null;

    private ?OutputInterface $output = null;

    private ?OutputRendererContract $renderer = null;

    private WorkerOutputBuffer $outputBuffer;

    private int $startedAt = 0;

    /** @var array<string, QueueStats> */
    private array $currentQueueStats = [];

    /** @var array<int, string> */
    private array $scalingLog = [];

    private ?string $stopReason = null;

    private ?string $lastObservedLeaderId = null;

    /** @var list<string> */
    private array $lastObservedManagerIds = [];

    private readonly MeasuredResourceCollector $resourceCollector;

    /** Whether this manager held the lease on the previous cycle. */
    private bool $wasLeader = false;

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
        ?MeasuredResourceCollector $resourceCollector = null,
    ) {
        $this->resourceCollector = $resourceCollector ?? new MeasuredResourceCollector($resolver);

        $this->pool = new WorkerPool;
        $this->outputBuffer = new WorkerOutputBuffer;
    }

    public function configure(int $interval): void
    {
        $this->interval = $interval;
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
    /**
     * Trim a spawn request to what the host-wide ceiling still allows.
     *
     * Capacity is enforced per queue, and workers.min is applied AFTER the
     * CPU/memory clamp so a floor always beats measured capacity. That is
     * deliberate for one queue, but queues are DISCOVERED from metrics rather
     * than only read from config: an app with per-tenant queue names presents
     * thousands of queues, each of which is then raised to its floor. Nothing
     * bounded the sum. This is that bound.
     */
    private function clampToHostCeiling(int $requested): int
    {
        $ceiling = AutoscaleConfiguration::maxTotalWorkers();

        if ($ceiling === null || $requested <= 0) {
            return max(0, $requested);
        }

        $headroom = max(0, $ceiling - $this->pool->totalCount());

        if ($headroom >= $requested) {
            return $requested;
        }

        if ($this->alerts->allow('host_ceiling:'.AutoscaleConfiguration::hostLabel())) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Host worker ceiling reached; spawn request trimmed',
                [
                    'ceiling' => $ceiling,
                    'running' => $this->pool->totalCount(),
                    'requested' => $requested,
                    'granted' => $headroom,
                ]
            );
        }

        return $headroom;
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
        $this->output = $output;
    }

    public function setRenderer(OutputRendererContract $renderer): void
    {
        $this->renderer = $renderer;
    }

    private function verbose(string $message, string $level = 'info'): void
    {
        if (! $this->output) {
            return;
        }

        if (! $this->output->isVerbose()) {
            return;
        }

        $timestamp = now()->format('H:i:s');
        $prefix = (string) match ($level) {
            'debug' => '<fg=gray>[DEBUG]</>',
            'info' => '<fg=cyan>[INFO]</>',
            'warn' => '<fg=yellow>[WARN]</>',
            'error' => '<fg=red>[ERROR]</>',
            default => '[INFO]',
        };

        $this->output->writeln("[$timestamp] {$prefix} {$message}");
    }

    private function isVeryVerbose(): bool
    {
        if (! $this->output) {
            return false;
        }

        return $this->output->isVeryVerbose();
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
                $this->enforceTerminationDeadlines();
                $this->cleanupDeadWorkers();

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
        $capacityDetails = $capacity->details;
        $cpuDetails = is_array($capacityDetails['cpu_details'] ?? null) ? $capacityDetails['cpu_details'] : [];
        $memoryDetails = is_array($capacityDetails['memory_details'] ?? null) ? $capacityDetails['memory_details'] : [];
        $memoryTotalMb = Coerce::toFloat($memoryDetails['total_memory_mb'] ?? 0.0);
        $memoryUsedMb = round($memoryTotalMb * (Coerce::toFloat($memoryDetails['current_memory_percent'] ?? 0.0) / 100), 1);
        $memoryFreeMb = round(max($memoryTotalMb - $memoryUsedMb, 0.0), 1);
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
            cpuPercent: Coerce::toFloat($cpuDetails['current_cpu_percent'] ?? 0.0),
            cpuCores: is_numeric($cpuDetails['total_cores'] ?? null) ? (float) $cpuDetails['total_cores'] : 0.0,
            cpuUsableCores: is_numeric($cpuDetails['usable_cores'] ?? null) ? (float) $cpuDetails['usable_cores'] : 0.0,
            cpuReservedCores: is_numeric($cpuDetails['reserve_cores'] ?? null) ? (float) $cpuDetails['reserve_cores'] : 0.0,
            memoryPercent: Coerce::toFloat($memoryDetails['current_memory_percent'] ?? 0.0),
            memoryTotalMb: $memoryTotalMb,
            memoryUsedMb: $memoryUsedMb,
            memoryFreeMb: $memoryFreeMb,
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
            $this->evaluateAndPublishClusterRecommendations();
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

    private function evaluateAndPublishClusterRecommendations(): void
    {
        app(CalculateQueueMetricsAction::class)->executeForAllQueues();

        $this->reportMeasuredResources($this->resourceCollector->collect());

        $allQueues = QueueMetrics::getAllQueuesWithMetrics();

        $configuredQueues = AutoscaleConfiguration::configuredQueues();
        foreach ($configuredQueues as $queueKey => $queueInfo) {
            if (! isset($allQueues[$queueKey])) {
                $allQueues[$queueKey] = $this->metricsAdapter->forQueue($queueInfo['connection'], $queueInfo['queue']);
            }
        }

        $groups = GroupConfiguration::allFromConfig();

        foreach ($groups as $group) {
            foreach ($group->queues as $memberQueue) {
                $queueKey = "{$group->connection}:{$memberQueue}";

                if (! isset($allQueues[$queueKey])) {
                    $allQueues[$queueKey] = $this->metricsAdapter->forQueue($group->connection, $memberQueue);
                }
            }
        }

        if ($groups !== [] && $this->groupsValid === null) {
            try {
                GroupConfiguration::assertNoQueueConflicts($groups);
                $this->groupsValid = true;
            } catch (\Throwable $e) {
                $this->groupsValid = false;
                Log::channel(AutoscaleConfiguration::logChannel())->critical(
                    'Group configuration is invalid — groups disabled until manager restart',
                    ['error' => $e->getMessage()]
                );
            }
        }

        if ($this->groupsValid === false) {
            $groups = [];
        }

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

            $config = QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);
            $workloadKey = ClusterRecommendation::queueWorkloadKey($metrics->connection, $metrics->queue);
            $currentWorkers = $this->clusterCurrentWorkers($activeManagers, $workloadKey);
            $targetWorkers = $this->clusterTargetWorkers($config, $metrics, $currentWorkers, $clusterTotalWorkers);
            $targetWorkers = $this->applyClusterScopedPolicies($metrics->connection, $metrics->queue, $currentWorkers, $targetWorkers, $config);

            $demands[$workloadKey] = $targetWorkers;
            $workerConfigs[$workloadKey] = ['min' => $config->workers->min, 'max' => $config->workers->max];
            $workloadMeta[$workloadKey] = [
                'type' => 'queue',
                'connection' => $metrics->connection,
                'name' => $metrics->queue,
                'driver' => $metrics->driver,
                'config' => $config,
                'current_workers' => $currentWorkers,
                'metrics' => $metrics,
                'scalable' => $config->workers->scalable,
                'member_queues' => [$metrics->queue],
            ];
        }

        foreach ($groups as $group) {
            $aggregated = $this->metricsAdapter->aggregateGroup($group, $metricsByKey);
            $config = $group->toScalingConfiguration();
            $workloadKey = ClusterRecommendation::groupWorkloadKey($group->connection, $group->name);
            $currentWorkers = $this->clusterCurrentWorkers($activeManagers, $workloadKey);
            $targetWorkers = $this->clusterTargetWorkers($config, $aggregated, $currentWorkers, $clusterTotalWorkers);
            $targetWorkers = $this->applyClusterScopedPolicies($group->connection, $group->name, $currentWorkers, $targetWorkers, $config);

            $demands[$workloadKey] = $targetWorkers;
            $workerConfigs[$workloadKey] = ['min' => $config->workers->min, 'max' => $config->workers->max];
            $workloadMeta[$workloadKey] = [
                'type' => 'group',
                'connection' => $group->connection,
                'name' => $group->name,
                'driver' => $aggregated->driver,
                'config' => $config,
                'current_workers' => $currentWorkers,
                'metrics' => $aggregated,
                'scalable' => $config->workers->scalable,
                'member_queues' => array_values($group->queues),
            ];
        }

        // Phase B: Fair-share allocation
        $pinnedDemands = [];
        $scalableDemands = [];
        $scalableConfigs = [];

        foreach ($demands as $workloadKey => $demand) {
            if (! $workloadMeta[$workloadKey]['scalable']) {
                $pinnedDemands[$workloadKey] = $demand;
            } else {
                $scalableDemands[$workloadKey] = $demand;
                $scalableConfigs[$workloadKey] = $workerConfigs[$workloadKey];
            }
        }

        $scalableCapacity = max($clusterCapacity - array_sum($pinnedDemands), 0);
        $allocator = new FairShareAllocator;
        $scalableTargets = $allocator->allocate($scalableDemands, $scalableConfigs, $scalableCapacity);
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

        foreach ($adjustedTargets as $workloadKey => $targetWorkers) {
            $meta = $workloadMeta[$workloadKey];
            $currentWorkers = $meta['current_workers'];
            $slaTarget = $meta['config']->sla->targetSeconds;
            $isBreaching = $meta['metrics']->oldestJobAge > 0 && $meta['metrics']->oldestJobAge >= $slaTarget;
            $cooldownSeconds = Coerce::toInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;
            $damped = $this->cooldown->apply($workloadKey, $currentWorkers, $targetWorkers, $isBreaching, $cooldownSeconds);

            if ($damped->wasHeld) {
                $this->verbose("  ⏸️  Anti-flapping: holding {$workloadKey} at {$damped->targetWorkers} during cooldown", 'debug');
            }

            if ($damped->breachOverride) {
                $this->verbose('  🚨 SLA breach override: bypassing anti-flapping cooldown for cluster scale-up', 'warn');
            }

            $targetWorkers = $damped->targetWorkers;
            $workloadAssignments = $this->distributor->distribute($activeManagers, $workloadKey, $targetWorkers, $assignedTotals);

            foreach ($workloadAssignments as $managerId => $target) {
                $assignments[$managerId][$workloadKey] = $target;
            }

            $workloads[] = [
                'type' => $meta['type'],
                'connection' => $meta['connection'],
                'name' => $meta['name'],
                'driver' => $meta['driver'],
                'current_workers' => $currentWorkers,
                'demand' => $demands[$workloadKey],
                'target_workers' => $targetWorkers,
                'worker_min' => $meta['config']->workers->min,
                'worker_max' => $meta['config']->workers->max,
                'sla_target_seconds' => $meta['config']->sla->targetSeconds,
                'pending' => $meta['metrics']->pending,
                'oldest_job_age' => $meta['metrics']->oldestJobAge,
                'oldest_job_age_status' => $meta['metrics']->ageStatus,
                'throughput_per_minute' => $meta['metrics']->throughputPerMinute,
                'active_workers' => $meta['metrics']->activeWorkers,
                'utilization_percent' => round($meta['metrics']->utilizationRate, 1),
                'member_queues' => $meta['member_queues'],
                'action' => $targetWorkers <=> $currentWorkers,
            ];

            // Record scaling decision and fire events for this workload
            $reason = $targetWorkers > $currentWorkers ? 'cluster:scale_up' : ($targetWorkers < $currentWorkers ? 'cluster:scale_down' : 'cluster:hold');

            $decision = new ScalingDecision(
                connection: $meta['connection'],
                queue: $meta['name'],
                currentWorkers: $currentWorkers,
                targetWorkers: $targetWorkers,
                reason: $reason,
                slaTarget: $meta['config']->sla->targetSeconds,
                scope: ScalingScope::Cluster,
            );

            if (! $decision->shouldHold()) {
                $decisionEntry = [
                    'workload_key' => $workloadKey,
                    'type' => $meta['type'],
                    'connection' => $meta['connection'],
                    'name' => $meta['name'],
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
            $breachKey = ($meta['type'] === 'group' ? 'group:' : '')."{$meta['connection']}:{$meta['name']}";
            $wasBreaching = $this->breachState[$breachKey] ?? false;

            if ($isBreaching && ! $wasBreaching) {
                event(new SlaBreached(
                    connection: $meta['connection'],
                    queue: $meta['name'],
                    oldestJobAge: $meta['metrics']->oldestJobAge,
                    slaTarget: $slaTarget,
                    pending: $meta['metrics']->pending,
                    activeWorkers: $meta['metrics']->activeWorkers,
                ));
            } elseif (! $isBreaching && $wasBreaching) {
                event(new SlaRecovered(
                    connection: $meta['connection'],
                    queue: $meta['name'],
                    currentJobAge: $meta['metrics']->oldestJobAge,
                    slaTarget: $slaTarget,
                    pending: $meta['metrics']->pending,
                    activeWorkers: $meta['metrics']->activeWorkers,
                ));
            }

            $this->breachState[$breachKey] = $isBreaching;

            if ($decision->isSlaBreachRisk()) {
                event(new SlaBreachPredicted($decision));
            }
        }

        // Prune cached distributions and cooldown state for workloads no
        // longer present
        $this->distributor->pruneTo($adjustedTargets);
        $this->cooldown->pruneTo($adjustedTargets);

        $issuedAt = $this->currentTimestamp();
        $leaderToken = $this->clusterStore->leaderToken();

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

        $recentDecisions = $this->clusterStore->recentDecisions(
            AutoscaleConfiguration::decisionHistorySeconds()
        );
        $summary = $this->buildClusterSummary($activeManagers, $workloads, $recentDecisions);
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
                    $this->superviseQueue($config, $metrics, $target);

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

    private function reconcileQueueTarget(QueueConfiguration $config, int $targetWorkers): void
    {
        $currentWorkers = $this->pool->count($config->connection, $config->queue);
        $targetWorkers = max(0, $targetWorkers);

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
            fn (ScalingDecision $d) => $this->scaleUp($d),
            fn (ScalingDecision $d) => $this->scaleDown($d),
        );
    }

    private function reconcileGroupTarget(GroupConfiguration $group, int $targetWorkers): void
    {
        $currentWorkers = $this->pool->countGroup($group->connection, $group->name);
        $targetWorkers = max(0, $targetWorkers);

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
            fn (ScalingDecision $d) => $this->scaleUpGroup($group, $d),
            fn (ScalingDecision $d) => $this->scaleDownGroup($group, $d),
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

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     * @param  array<int, array<string, int|float|string|list<string>>>  $workloads
     * @param  array<int, array<string, mixed>>  $scalingDecisions
     * @return array<string, mixed>
     */
    private function buildClusterSummary(array $activeManagers, array $workloads, array $scalingDecisions = []): array
    {
        $workloadSortKey = static function (array $workload): string {
            $type = is_string($workload['type'] ?? null) ? $workload['type'] : '';
            $connection = is_string($workload['connection'] ?? null) ? $workload['connection'] : '';
            $name = is_string($workload['name'] ?? null) ? $workload['name'] : '';

            return "{$type}:{$connection}:{$name}";
        };

        usort(
            $workloads,
            static fn (array $a, array $b): int => strcmp($workloadSortKey($a), $workloadSortKey($b)),
        );

        $currentHosts = count($activeManagers);
        $totalWorkerCapacity = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->maxWorkers, $activeManagers));
        $requiredWorkers = array_sum(array_map(static fn (array $workload): int => (int) $workload['demand'], $workloads));
        $totalWorkers = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->totalWorkers, $activeManagers));
        $recommendedHosts = $this->recommendedHostCount($activeManagers, $requiredWorkers);
        $signal = $this->clusterScaleSignal($currentHosts, $recommendedHosts, $requiredWorkers, $totalWorkerCapacity, $totalWorkers, $workloads);
        $generatedAt = now();
        $generatedAtMs = $this->currentTimestamp();
        $leaderLeaseTtlSeconds = AutoscaleConfiguration::clusterLeaderLeaseSeconds();
        $leaderExpiresAt = $generatedAt->copy()->addSeconds($leaderLeaseTtlSeconds);

        $managers = array_map(function (ClusterManagerState $state): array {
            return [
                'manager_id' => $state->managerId,
                'host' => $state->host,
                'is_leader' => $state->managerId === AutoscaleConfiguration::managerId(),
                'last_seen_at' => $state->lastSeenAt,
                'last_seen_human' => now()->setTimestamp((int) floor($state->lastSeenAt / 1000))->diffForHumans(),
                'total_workers' => $state->totalWorkers,
                'max_workers' => $state->maxWorkers,
                'available_worker_capacity' => $state->availableWorkerCapacity,
                'capacity_limiter' => $state->capacityLimiter,
                'cpu_percent' => round($state->cpuPercent, 1),
                'cpu_cores' => $state->cpuCores,
                'cpu_usable_cores' => $state->cpuUsableCores,
                'cpu_reserved_cores' => $state->cpuReservedCores,
                'memory_percent' => round($state->memoryPercent, 1),
                'memory_total_mb' => round($state->memoryTotalMb, 1),
                'memory_used_mb' => round($state->memoryUsedMb, 1),
                'memory_free_mb' => round($state->memoryFreeMb, 1),
                'queue_count' => $state->queueCount,
                'group_count' => $state->groupCount,
                'package_version' => $state->packageVersion,
                'queue_workers' => $state->queueWorkers,
                'group_workers' => $state->groupWorkers,
            ];
        }, $activeManagers);

        return [
            'cluster_id' => AutoscaleConfiguration::clusterAppId(),
            'generated_at' => $generatedAt->toIso8601String(),
            'generated_at_unix_ms' => $generatedAtMs,
            'leader_id' => AutoscaleConfiguration::managerId(),
            'leader_renewed_at' => $generatedAt->toIso8601String(),
            'leader_renewed_at_unix_ms' => $generatedAtMs,
            'leader_lease_ttl_seconds' => $leaderLeaseTtlSeconds,
            'leader_expires_at' => $leaderExpiresAt->toIso8601String(),
            'manager_count' => $currentHosts,
            'total_workers' => $totalWorkers,
            'required_workers' => $requiredWorkers,
            'total_worker_capacity' => $totalWorkerCapacity,
            'utilization_percent' => $totalWorkerCapacity > 0 ? round(($requiredWorkers / $totalWorkerCapacity) * 100, 1) : 0.0,
            'scale_signal' => $signal,
            'managers' => $managers,
            'workloads' => array_map(function (array $workload): array {
                $workload['action'] = match ((int) $workload['action']) {
                    1 => 'scale_up',
                    -1 => 'scale_down',
                    default => 'hold',
                };

                return $workload;
            }, $workloads),
            'scaling_decisions' => $scalingDecisions,
        ];
    }

    /**
     * @param  array<int, ClusterManagerState>  $activeManagers
     */
    private function recommendedHostCount(array $activeManagers, int $requiredWorkers): int
    {
        if ($activeManagers === []) {
            return 0;
        }

        if ($requiredWorkers <= 0) {
            return 1;
        }

        $capacities = array_map(static fn (ClusterManagerState $state): int => max($state->maxWorkers, 1), $activeManagers);
        rsort($capacities);

        $accumulated = 0;
        foreach ($capacities as $index => $capacity) {
            $accumulated += $capacity;

            if ($accumulated >= $requiredWorkers) {
                return $index + 1;
            }
        }

        $currentHosts = count($capacities);
        $averageCapacity = max((int) floor(array_sum($capacities) / max($currentHosts, 1)), 1);
        $remaining = max($requiredWorkers - $accumulated, 0);

        return $currentHosts + (int) ceil($remaining / $averageCapacity);
    }

    /**
     * @param  array<int, array<string, mixed>>  $workloads
     * @return array<string, int|string>
     */
    private function clusterScaleSignal(
        int $currentHosts,
        int $recommendedHosts,
        int $requiredWorkers,
        int $totalWorkerCapacity,
        int $totalWorkers,
        array $workloads,
    ): array {
        if ($requiredWorkers > $totalWorkerCapacity) {
            return [
                'action' => 'scale_up',
                'reason' => 'required workers exceed observed cluster capacity',
                'current_hosts' => $currentHosts,
                'recommended_hosts' => max($recommendedHosts, $currentHosts + 1),
            ];
        }

        if ($recommendedHosts < $currentHosts) {
            // Do not recommend scale-down when the cluster is under pressure.
            $utilizationPercent = $totalWorkerCapacity > 0
                ? ($totalWorkers / $totalWorkerCapacity) * 100
                : 0.0;

            $hasScaleUpPressure = false;
            foreach ($workloads as $workload) {
                $target = is_numeric($workload['target_workers'] ?? null) ? (int) $workload['target_workers'] : 0;
                $current = is_numeric($workload['current_workers'] ?? null) ? (int) $workload['current_workers'] : 0;
                $pending = is_numeric($workload['pending'] ?? null) ? (int) $workload['pending'] : 0;

                if ($target > $current || $pending > 0) {
                    $hasScaleUpPressure = true;

                    break;
                }
            }

            if ($utilizationPercent >= 80.0 || $hasScaleUpPressure) {
                return [
                    'action' => 'hold',
                    'reason' => $utilizationPercent >= 80.0
                        ? sprintf('high utilization (%.0f%%) prevents scale-down', $utilizationPercent)
                        : 'pending workload prevents scale-down',
                    'current_hosts' => $currentHosts,
                    'recommended_hosts' => $currentHosts,
                ];
            }

            return [
                'action' => 'scale_down',
                'reason' => 'required workers fit on fewer hosts',
                'current_hosts' => $currentHosts,
                'recommended_hosts' => max($recommendedHosts, 1),
            ];
        }

        return [
            'action' => 'hold',
            'reason' => 'current host count matches required worker capacity',
            'current_hosts' => $currentHosts,
            'recommended_hosts' => max($recommendedHosts, 1),
        ];
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
     * would defeat both. A queue that has not been scaled within the retention
     * window has nothing left worth remembering.
     */
    private function forgetQueuesNotSeenRecently(): void
    {
        $cutoff = now()->subSeconds(self::QUEUE_STATE_RETENTION_SECONDS);

        foreach ($this->lastScaleTime as $key => $at) {
            if ($at->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            unset(
                $this->lastScaleTime[$key],
                $this->lastScaleDirection[$key],
                $this->breachState[$key],
            );
        }
    }

    private function evaluateAndScale(): void
    {
        $this->beginEvaluationCycle();

        // Recalculate metrics first to ensure throughput uses current sliding window
        app(CalculateQueueMetricsAction::class)->executeForAllQueues();

        $this->reportMeasuredResources($this->resourceCollector->collect());

        // Get ALL queues with metrics from laravel-queue-metrics
        // Returns: ['redis:default' => [...metrics array...], ...]
        $allQueues = QueueMetrics::getAllQueuesWithMetrics();

        // Also include configured queues that might not have historical data yet
        // This ensures newly configured queues are monitored from the start
        $configuredQueues = AutoscaleConfiguration::configuredQueues();
        foreach ($configuredQueues as $queueKey => $queueInfo) {
            if (! isset($allQueues[$queueKey])) {
                // Fetch fresh metrics for this queue directly
                $allQueues[$queueKey] = $this->metricsAdapter->forQueue($queueInfo['connection'], $queueInfo['queue']);
            }
        }

        // Load groups. Validation is cheap: skip entirely when there are no groups.
        $groups = GroupConfiguration::allFromConfig();

        // Force-fetch metrics for group member queues too. Without this, a brand-new
        // group whose members have never seen traffic would be invisible until the
        // metrics package happens to discover them independently, delaying first
        // scale-up.
        foreach ($groups as $group) {
            foreach ($group->queues as $memberQueue) {
                $k = "{$group->connection}:{$memberQueue}";

                if (! isset($allQueues[$k])) {
                    $allQueues[$k] = $this->metricsAdapter->forQueue($group->connection, $memberQueue);
                }
            }
        }

        // Validate group config exactly once per manager process. Cache the outcome
        // so a bad config doesn't spam the log every eval cycle, and a good config
        // doesn't re-run the O(groups × members) conflict check forever.
        if ($groups !== [] && $this->groupsValid === null) {
            try {
                GroupConfiguration::assertNoQueueConflicts($groups);
                $this->groupsValid = true;
            } catch (\Throwable $e) {
                $this->groupsValid = false;
                Log::channel(AutoscaleConfiguration::logChannel())->critical(
                    'Group configuration is invalid — groups disabled until manager restart',
                    ['error' => $e->getMessage()]
                );
            }
        }

        // If group validation failed earlier in this process, don't attempt group
        // evaluation. Per-queue autoscaling still runs normally.
        if ($this->groupsValid === false) {
            $groups = [];
        }

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

        // 5. Anti-flapping check: prevent direction reversals within cooldown
        // Exception: scale-up during SLA breach is always allowed to protect SLA
        $key = "{$connection}:{$queue}";
        $currentDirection = $decision->shouldScaleUp() ? 'up' : ($decision->shouldScaleDown() ? 'down' : 'hold');
        $lastDirection = $this->lastScaleDirection[$key] ?? null;

        // Clear stale direction: once cooldown has fully elapsed, the last direction
        // is no longer relevant. This prevents HOLD→HOLD→...→DOWN from being blocked
        // by an UP that happened minutes ago.
        $scaleCooldownSeconds = Coerce::toInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;
        if ($lastDirection !== null && ! $this->inCooldown($key, $scaleCooldownSeconds)) {
            unset($this->lastScaleDirection[$key]);
            $lastDirection = null;
        }

        // Only apply cooldown if direction is reversing (prevents flapping)
        if ($currentDirection !== 'hold' && $lastDirection !== null && $currentDirection !== $lastDirection) {
            // Always allow scale-up during SLA breach - protecting SLA takes priority over anti-flapping
            $isBreachScaleUp = $currentDirection === 'up' && $isBreaching;

            if (! $isBreachScaleUp && $this->inCooldown($key, $scaleCooldownSeconds)) {
                $remaining = $this->getCooldownRemaining($key, $scaleCooldownSeconds);
                $this->verbose("  ⏸️  Anti-flapping: cannot reverse direction during cooldown ({$remaining}s remaining)", 'debug');

                return;
            }

            if ($isBreachScaleUp) {
                $this->verbose('  🚨 SLA breach override: bypassing anti-flapping cooldown for scale-up', 'warn');
            }
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
            fn (ScalingDecision $d) => $this->scaleUp($d),
            fn (ScalingDecision $d) => $this->scaleDown($d),
            noActionMessage: '  ✓ No scaling action needed',
        );

        // 10. Broadcast the remaining events using the final decision
        if ($finalDecision->isSlaBreachRisk()) {
            $this->verbose('  ⚠️  SLA BREACH RISK DETECTED!', 'warn');
            event(new SlaBreachPredicted($finalDecision));
        }

        // Track SLA breach state and fire breach/recovery events
        $wasBreaching = $this->breachState[$key] ?? false;

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
            $this->breachState[$key] = true;
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
            $this->breachState[$key] = false;
        } elseif ($isBreaching) {
            // Update breach state (still breaching)
            $this->breachState[$key] = true;
        } else {
            // Update breach state (not breaching)
            $this->breachState[$key] = false;
        }

        // 11. Update last scale time and direction
        if (! $finalDecision->shouldHold()) {
            $this->lastScaleTime[$key] = now();
            $this->lastScaleDirection[$key] = $currentDirection;
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
        $lastDirection = $this->lastScaleDirection[$key] ?? null;
        $scaleCooldownSeconds = Coerce::toInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;

        if ($lastDirection !== null && ! $this->inCooldown($key, $scaleCooldownSeconds)) {
            unset($this->lastScaleDirection[$key]);
            $lastDirection = null;
        }

        if ($currentDirection !== 'hold' && $lastDirection !== null && $currentDirection !== $lastDirection) {
            $isBreachScaleUp = $currentDirection === 'up' && $isBreaching;

            if (! $isBreachScaleUp && $this->inCooldown($key, $scaleCooldownSeconds)) {
                $remaining = $this->getCooldownRemaining($key, $scaleCooldownSeconds);
                $this->verbose("  ⏸️  Anti-flapping (group): cannot reverse direction during cooldown ({$remaining}s remaining)", 'debug');

                return;
            }
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
            fn (ScalingDecision $d) => $this->scaleUpGroup($group, $d),
            fn (ScalingDecision $d) => $this->scaleDownGroup($group, $d),
            noActionMessage: '  ✓ No group scaling action needed',
        );

        if ($finalDecision->isSlaBreachRisk()) {
            $this->verbose('  ⚠️  GROUP SLA BREACH RISK DETECTED!', 'warn');
            event(new SlaBreachPredicted($finalDecision));
        }

        // SLA breach state for groups mirrors the per-queue event flow.
        $wasBreaching = $this->breachState[$key] ?? false;

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

        $this->breachState[$key] = $isBreaching;

        if (! $finalDecision->shouldHold()) {
            $this->lastScaleTime[$key] = now();
            $this->lastScaleDirection[$key] = $currentDirection;
        }
    }

    private function scaleUpGroup(GroupConfiguration $group, ScalingDecision $decision): void
    {
        $draining = $this->pool->liveCountGroup($group->connection, $group->name)
            - $this->pool->countGroup($group->connection, $group->name);

        $toAdd = $this->clampToHostCeiling(max($decision->workersToAdd() - $draining, 0));

        if ($toAdd === 0) {
            return;
        }

        $this->verbose("  ⬆️  Scaling group UP: spawning {$toAdd} worker(s) for [{$group->queueArgument()}]", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] group:%s scaled UP %d -> %d (%s)',
            now()->format('H:i:s'),
            $group->name,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $workers = $this->spawner->spawn(
            $group->connection,
            $group->queueArgument(),
            $toAdd,
            $group->spawnCompensation,
            group: $group->name,
            workerConfig: $group->workers,
        );

        foreach ($workers as $worker) {
            $this->verbose("     ✓ Group worker spawned: PID {$worker->pid()}", 'info');
        }

        $this->pool->addMany($workers);

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled up group workers',
            [
                'group' => $group->name,
                'queues' => $group->queues,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'added' => $toAdd,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $group->connection,
            queue: $group->name,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
            action: 'up',
            reason: $decision->reason,
        ));
    }

    private function scaleDownGroup(GroupConfiguration $group, ScalingDecision $decision): void
    {
        $toRemove = $decision->workersToRemove();

        $this->verbose("  ⬇️  Scaling group DOWN: terminating {$toRemove} worker(s) in '{$group->name}'", 'info');

        $workers = $this->pool->getTerminatableFromGroup($group->connection, $group->name, $toRemove);

        foreach ($workers as $worker) {
            $this->terminator->requestTermination($worker);
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled down group workers',
            [
                'group' => $group->name,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'removed' => $toRemove,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $group->connection,
            queue: $group->name,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
            action: 'down',
            reason: $decision->reason,
        ));
    }

    /**
     * Supervise a non-scalable (pinned) queue: maintain the target worker
     * count. In non-cluster mode the target is always pinnedCount(). In
     * cluster mode the leader distributes the pinned count across managers,
     * so the local target may be 0 (not assigned) or pinnedCount() (assigned).
     *
     * Respawns on death, terminates excess. Never evaluates scaling.
     * Still tracks SLA breach state for observability parity.
     */
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
            $toAdd = $this->clampToHostCeiling($target - $current);

            if ($toAdd === 0) {
                $this->policies->afterScaling($decision);

                return;
            }

            $this->verbose("  ⬆️  Supervisor respawn: spawning {$toAdd} worker(s)", 'info');

            $this->scalingLog[] = sprintf(
                '[%s] %s:%s supervisor respawn %d -> %d',
                now()->format('H:i:s'),
                $connection,
                $queue,
                $current,
                $target
            );

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
        $wasBreaching = $this->breachState[$key] ?? false;

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

        $this->breachState[$key] = $isBreaching;

        $this->policies->afterScaling($decision);
    }

    private function scaleUp(ScalingDecision $decision): void
    {
        // A worker draining toward exit is invisible to count(), which is
        // right for scale-down and wrong here: it is still a live process
        // still polling the queue, so spawning against the smaller number
        // puts a second worker on a queue that already has one.
        $draining = $this->pool->liveCount($decision->connection, $decision->queue)
            - $this->pool->count($decision->connection, $decision->queue);

        $toAdd = $this->clampToHostCeiling(max($decision->workersToAdd() - $draining, 0));

        if ($toAdd === 0) {
            return;
        }

        $this->verbose("  ⬆️  Scaling UP: spawning {$toAdd} worker(s)", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] %s:%s scaled UP %d -> %d (%s)',
            now()->format('H:i:s'),
            $decision->connection,
            $decision->queue,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $spawnConfig = $decision->spawnCompensation
            ?? QueueConfiguration::fromConfig($decision->connection, $decision->queue)->spawnCompensation;

        $workers = $this->spawner->spawn(
            $decision->connection,
            $decision->queue,
            $toAdd,
            $spawnConfig,
            workerConfig: QueueConfiguration::fromConfig($decision->connection, $decision->queue)->workers,
        );

        foreach ($workers as $worker) {
            $this->verbose("     ✓ Worker spawned: PID {$worker->pid()}", 'info');
        }

        $this->pool->addMany($workers);

        // Report what actually started, not what was asked for. The spawner
        // drops workers that fail to launch, so trusting the requested count
        // meant a run where every spawn failed still logged and emitted
        // "scaled 0 -> 5" while the pool gained nothing.
        $spawned = $workers->count();
        $reached = $decision->currentWorkers + $spawned;

        if ($spawned < $toAdd) {
            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Fewer workers started than requested',
                [
                    'connection' => $decision->connection,
                    'queue' => $decision->queue,
                    'requested' => $toAdd,
                    'started' => $spawned,
                ]
            );
        }

        if ($spawned === 0) {
            return;
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled up workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $reached,
                'added' => $spawned,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $reached,
            action: 'up',
            reason: $decision->reason
        ));
    }

    private function scaleDown(ScalingDecision $decision): void
    {
        $toRemove = $decision->workersToRemove();

        $this->verbose("  ⬇️  Scaling DOWN: terminating {$toRemove} worker(s)", 'info');

        $this->scalingLog[] = sprintf(
            '[%s] %s:%s scaled DOWN %d -> %d (%s)',
            now()->format('H:i:s'),
            $decision->connection,
            $decision->queue,
            $decision->currentWorkers,
            $decision->targetWorkers,
            $decision->reason
        );

        $workers = $this->pool->getTerminatable(
            $decision->connection,
            $decision->queue,
            $toRemove
        );

        foreach ($workers as $worker) {
            $this->verbose("     ✓ Requesting worker termination: PID {$worker->pid()}", 'info');
            $this->terminator->requestTermination($worker);
        }

        // Report what was actually terminated. getTerminatable() can return
        // fewer workers than asked for — some may already be draining — and
        // reporting the request instead made the log and the event describe a
        // pool state that was never reached. scaleUp was fixed for exactly
        // this; the down path was missed.
        $removed = $workers->count();
        $reached = $decision->currentWorkers - $removed;

        if ($removed < $toRemove) {
            $this->verbose(
                "  ⚠️  Only {$removed} of {$toRemove} worker(s) could be terminated; the rest are already draining",
                'warn'
            );
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled down workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $reached,
                'requested' => $decision->targetWorkers,
                'removed' => $removed,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $reached,
            action: 'down',
            reason: $decision->reason
        ));
    }

    private function cleanupDeadWorkers(): void
    {
        $dead = $this->pool->getDeadWorkers();

        if (count($dead) > 0) {
            $this->verbose('🔧 Cleaning up '.count($dead).' dead worker(s)', 'warn');
        }

        foreach ($dead as $worker) {
            $this->pool->removeWorker($worker);

            // The output buffer keeps a partial-line fragment per PID and
            // nothing ever cleared it, so a long-lived manager accumulated one
            // entry per worker it had ever run — and a recycled PID inherited
            // the previous worker's dangling line.
            $pid = $worker->pid();

            if ($pid !== null) {
                $this->outputBuffer->clearBuffer($pid);
            }

            $this->verbose("   💀 Removed dead worker: PID {$worker->pid()}", 'warn');

            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Removed dead worker',
                ['pid' => $worker->pid()]
            );
        }
    }

    private function enforceTerminationDeadlines(): void
    {
        foreach ($this->pool->getTerminatingWorkers() as $worker) {
            $this->terminator->forceKillIfExpired($worker);
        }
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

        $this->scalingLog = [];
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
            scalingLog: $this->scalingLog,
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

        event(new ClusterLeaderChanged(
            clusterId: AutoscaleConfiguration::clusterAppId(),
            previousLeaderId: $this->lastObservedLeaderId,
            currentLeaderId: $currentLeaderId,
            observedByManagerId: AutoscaleConfiguration::managerId(),
            changedAt: $this->currentTimestamp(),
        ));

        $this->lastObservedLeaderId = $currentLeaderId;
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

    private function inCooldown(string $key, int $cooldownSeconds): bool
    {
        if (! isset($this->lastScaleTime[$key])) {
            return false;
        }

        /** @var Carbon $lastScale */
        $lastScale = $this->lastScaleTime[$key];

        return $lastScale->diffInSeconds(now()) < $cooldownSeconds;
    }

    private function getCooldownRemaining(string $key, int $cooldownSeconds): int
    {
        if (! isset($this->lastScaleTime[$key])) {
            return 0;
        }

        /** @var Carbon $lastScale */
        $lastScale = $this->lastScaleTime[$key];
        $elapsed = $lastScale->diffInSeconds(now());

        return (int) max(0, $cooldownSeconds - $elapsed);
    }
}
