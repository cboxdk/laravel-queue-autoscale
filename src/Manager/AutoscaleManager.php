<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Manager;

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterRecommendation;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Events\ClusterScalingSignalUpdated;
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
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Cbox\LaravelQueueAutoscale\Workers\WorkerOutputBuffer;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

final class AutoscaleManager
{
    private WorkerPool $pool;

    private int $interval = 5;

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

    public function __construct(
        private readonly ScalingEngine $engine,
        private readonly WorkerSpawner $spawner,
        private readonly WorkerTerminator $terminator,
        private readonly PolicyExecutor $policies,
        private readonly SignalHandler $signals,
        private readonly RestartSignal $restartSignal,
        private readonly ClusterStore $clusterStore,
        private readonly CapacityCalculator $capacity,
    ) {
        $this->pool = new WorkerPool;
        $this->outputBuffer = new WorkerOutputBuffer;
    }

    public function configure(int $interval): void
    {
        $this->interval = $interval;
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

        $this->signals->register(function () {
            Log::channel(AutoscaleConfiguration::logChannel())->info(
                'Shutdown signal received'
            );
        });

        $this->renderer?->initialize();

        $this->runLoop();

        $this->shutdown();

        return 0;
    }

    private function runLoop(): void
    {
        while (! $this->signals->shouldStop()) {
            if ($this->restartSignal->requestedAfter($this->startedAt)) {
                $this->verbose('Restart signal detected; shutting down manager for supervised restart.', 'info');

                Log::channel(AutoscaleConfiguration::logChannel())->info(
                    'Restart signal detected; shutting down manager for supervised restart'
                );

                $this->signals->requestStop();

                continue;
            }

            $startTime = microtime(true);
            $this->signals->dispatch();

            try {
                $this->processWorkerOutput();
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
        $capacity = $this->capacity->calculateMaxWorkers($this->pool->totalCount());
        $capacityDetails = $capacity->details;
        $cpuDetails = is_array($capacityDetails['cpu_details'] ?? null) ? $capacityDetails['cpu_details'] : [];
        $memoryDetails = is_array($capacityDetails['memory_details'] ?? null) ? $capacityDetails['memory_details'] : [];
        $state = new ClusterManagerState(
            managerId: AutoscaleConfiguration::managerId(),
            host: AutoscaleConfiguration::hostLabel(),
            lastSeenAt: $this->currentTimestamp(),
            totalWorkers: $this->pool->totalCount(),
            maxWorkers: $capacity->finalMaxWorkers,
            availableWorkerCapacity: max($capacity->finalMaxWorkers - $this->pool->totalCount(), 0),
            cpuPercent: $this->clusterFloat($cpuDetails['current_cpu_percent'] ?? 0.0),
            memoryPercent: $this->clusterFloat($memoryDetails['current_memory_percent'] ?? 0.0),
            queueWorkers: $this->pool->queueCounts(),
            groupWorkers: $this->pool->groupCounts(),
        );

        $this->clusterStore->heartbeat($state);

        $isLeader = $this->clusterStore->isLeader($state->managerId);

        if ($isLeader) {
            $this->verbose('Cluster leader lease active on this manager', 'debug');
            $this->evaluateAndPublishClusterRecommendations();
        } else {
            $leaderId = $this->clusterStore->leaderId();
            $leaderText = $leaderId !== null ? $leaderId : 'none';
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

        $allQueues = QueueMetrics::getAllQueuesWithMetrics();

        $configuredQueues = AutoscaleConfiguration::configuredQueues();
        foreach ($configuredQueues as $queueKey => $queueInfo) {
            if (! isset($allQueues[$queueKey])) {
                $allQueues[$queueKey] = $this->getMetricsForQueue($queueInfo['connection'], $queueInfo['queue']);
            }
        }

        $groups = GroupConfiguration::allFromConfig();

        foreach ($groups as $group) {
            foreach ($group->queues as $memberQueue) {
                $queueKey = "{$group->connection}:{$memberQueue}";

                if (! isset($allQueues[$queueKey])) {
                    $allQueues[$queueKey] = $this->getMetricsForQueue($group->connection, $memberQueue);
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
            $mappedData = $this->mapMetricsFields($metricsArray);
            $metrics = QueueMetricsData::fromArray($mappedData);
            $metricsByKey["{$metrics->connection}:{$metrics->queue}"] = $metrics;
        }

        $activeManagers = $this->clusterStore->activeManagers();
        $managerIds = array_map(static fn (ClusterManagerState $state): string => $state->managerId, $activeManagers);
        $assignedTotals = array_fill_keys($managerIds, 0);
        $assignments = array_fill_keys($managerIds, []);
        $clusterTotalWorkers = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->totalWorkers, $activeManagers));
        $workloads = [];

        foreach ($metricsByKey as $queueKey => $metrics) {
            if (AutoscaleConfiguration::isExcluded($metrics->queue) || isset($groupedQueueKeys[$queueKey])) {
                continue;
            }

            $config = QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);
            $workloadKey = ClusterRecommendation::queueWorkloadKey($metrics->connection, $metrics->queue);
            $currentWorkers = $this->clusterCurrentWorkers($activeManagers, $workloadKey);

            $targetWorkers = $this->clusterTargetWorkers($config, $metrics, $currentWorkers, $clusterTotalWorkers);
            $workloadAssignments = $this->distributeClusterTarget($activeManagers, $workloadKey, $targetWorkers, $assignedTotals);

            foreach ($workloadAssignments as $managerId => $target) {
                $assignments[$managerId][$workloadKey] = $target;
            }

            $workloads[] = [
                'type' => 'queue',
                'connection' => $metrics->connection,
                'name' => $metrics->queue,
                'current_workers' => $currentWorkers,
                'target_workers' => $targetWorkers,
                'pending' => $metrics->pending,
                'oldest_job_age' => $metrics->oldestJobAge,
                'throughput_per_minute' => $metrics->throughputPerMinute,
                'action' => $targetWorkers <=> $currentWorkers,
            ];
        }

        foreach ($groups as $group) {
            $aggregated = $this->aggregateGroupMetrics($group, $metricsByKey);
            $config = $group->toScalingConfiguration();
            $workloadKey = ClusterRecommendation::groupWorkloadKey($group->connection, $group->name);
            $currentWorkers = $this->clusterCurrentWorkers($activeManagers, $workloadKey);
            $targetWorkers = $this->clusterTargetWorkers($config, $aggregated, $currentWorkers, $clusterTotalWorkers);
            $workloadAssignments = $this->distributeClusterTarget($activeManagers, $workloadKey, $targetWorkers, $assignedTotals);

            foreach ($workloadAssignments as $managerId => $target) {
                $assignments[$managerId][$workloadKey] = $target;
            }

            $workloads[] = [
                'type' => 'group',
                'connection' => $group->connection,
                'name' => $group->name,
                'current_workers' => $currentWorkers,
                'target_workers' => $targetWorkers,
                'pending' => $aggregated->pending,
                'oldest_job_age' => $aggregated->oldestJobAge,
                'throughput_per_minute' => $aggregated->throughputPerMinute,
                'action' => $targetWorkers <=> $currentWorkers,
            ];
        }

        $issuedAt = $this->currentTimestamp();

        foreach ($managerIds as $managerId) {
            $this->clusterStore->publishRecommendation(
                new ClusterRecommendation(
                    managerId: $managerId,
                    issuedAt: $issuedAt,
                    workloads: $assignments[$managerId],
                )
            );
        }

        $summary = $this->buildClusterSummary($activeManagers, $workloads);
        $this->clusterStore->publishSummary($summary);
        $scaleSignal = is_array($summary['scale_signal'] ?? null) ? $summary['scale_signal'] : [];

        event(new ClusterScalingSignalUpdated(
            clusterId: $this->clusterString($summary['cluster_id'] ?? null),
            leaderId: $this->clusterString($summary['leader_id'] ?? null),
            currentHosts: $this->clusterInt($scaleSignal['current_hosts'] ?? 0),
            recommendedHosts: $this->clusterInt($scaleSignal['recommended_hosts'] ?? 0),
            currentCapacity: $this->clusterInt($summary['total_worker_capacity'] ?? 0),
            requiredWorkers: $this->clusterInt($summary['required_workers'] ?? 0),
            action: $this->clusterString($scaleSignal['action'] ?? null, 'hold'),
            reason: $this->clusterString($scaleSignal['reason'] ?? null),
        ));
    }

    private function applyClusterRecommendation(ClusterRecommendation $recommendation): void
    {
        $groups = GroupConfiguration::allFromConfig();
        $groupedQueueKeys = $this->groupedQueueKeys($groups);

        foreach (AutoscaleConfiguration::configuredQueues() as $queueKey => $queueInfo) {
            if (isset($groupedQueueKeys[$queueKey])) {
                continue;
            }

            $connection = $queueInfo['connection'];
            $queue = $queueInfo['queue'];

            if (AutoscaleConfiguration::isExcluded($queue)) {
                continue;
            }

            $config = QueueConfiguration::fromConfig($connection, $queue);
            $target = $recommendation->targetForQueue($connection, $queue);
            $this->reconcileQueueTarget($config, $target);
        }

        foreach ($groups as $group) {
            $target = $recommendation->targetForGroup($group->connection, $group->name);
            $this->reconcileGroupTarget($group, $target);
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

        $decision = $this->engine->evaluate($metrics, $config, $currentWorkers, $clusterTotalWorkers);
        $decision = $this->policies->beforeScaling($decision);

        return $decision->targetWorkers;
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
     * @param  array<int, ClusterManagerState>  $activeManagers
     * @param  array<string, int>  $assignedTotals
     * @return array<string, int>
     */
    private function distributeClusterTarget(
        array $activeManagers,
        string $workloadKey,
        int $targetWorkers,
        array &$assignedTotals,
    ): array {
        $targets = [];

        foreach ($activeManagers as $state) {
            $targets[$state->managerId] = 0;
        }

        if ($targetWorkers <= 0 || $activeManagers === []) {
            return $targets;
        }

        [$type, $connection, $name] = explode(':', $workloadKey, 3);
        $currentCounts = [];

        foreach ($activeManagers as $state) {
            $counts = $type === 'group' ? $state->groupWorkers : $state->queueWorkers;
            $currentCounts[$state->managerId] = (int) ($counts["{$connection}:{$name}"] ?? 0);
        }

        $preserveOrder = $activeManagers;
        usort(
            $preserveOrder,
            fn (ClusterManagerState $a, ClusterManagerState $b): int => ($currentCounts[$b->managerId] <=> $currentCounts[$a->managerId])
                ?: strcmp($a->managerId, $b->managerId),
        );

        $remaining = $targetWorkers;

        foreach ($preserveOrder as $state) {
            if ($remaining <= 0) {
                break;
            }

            $keep = min($currentCounts[$state->managerId], $remaining);
            $targets[$state->managerId] = $keep;
            $assignedTotals[$state->managerId] += $keep;
            $remaining -= $keep;
        }

        while ($remaining > 0) {
            $candidates = $activeManagers;
            usort(
                $candidates,
                fn (ClusterManagerState $a, ClusterManagerState $b): int => ($assignedTotals[$a->managerId] <=> $assignedTotals[$b->managerId])
                    ?: ($a->totalWorkers <=> $b->totalWorkers)
                    ?: strcmp($a->managerId, $b->managerId),
            );

            $chosen = $candidates[0];

            $targets[$chosen->managerId]++;
            $assignedTotals[$chosen->managerId]++;
            $remaining--;
        }

        return $targets;
    }

    private function reconcileQueueTarget(QueueConfiguration $config, int $targetWorkers): void
    {
        $currentWorkers = $this->pool->count($config->connection, $config->queue);
        $targetWorkers = max(0, $targetWorkers);

        if ($currentWorkers === $targetWorkers) {
            return;
        }

        $decision = new ScalingDecision(
            connection: $config->connection,
            queue: $config->queue,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: 'cluster:recommendation',
            spawnCompensation: $config->spawnCompensation,
        );

        if ($decision->shouldScaleUp()) {
            $this->scaleUp($decision);
        } elseif ($decision->shouldScaleDown()) {
            $this->scaleDown($decision);
        }
    }

    private function reconcileGroupTarget(GroupConfiguration $group, int $targetWorkers): void
    {
        $currentWorkers = $this->pool->countGroup($group->connection, $group->name);
        $targetWorkers = max(0, $targetWorkers);

        if ($currentWorkers === $targetWorkers) {
            return;
        }

        $decision = new ScalingDecision(
            connection: $group->connection,
            queue: $group->name,
            currentWorkers: $currentWorkers,
            targetWorkers: $targetWorkers,
            reason: 'cluster:recommendation',
            spawnCompensation: $group->spawnCompensation,
        );

        if ($decision->shouldScaleUp()) {
            $this->scaleUpGroup($group, $decision);
        } elseif ($decision->shouldScaleDown()) {
            $this->scaleDownGroup($group, $decision);
        }
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
     * @param  array<int, array<string, int|float|string>>  $workloads
     * @return array<string, mixed>
     */
    private function buildClusterSummary(array $activeManagers, array $workloads): array
    {
        usort(
            $workloads,
            static fn (array $a, array $b): int => strcmp((string) $a['type'].':'.(string) $a['connection'].':'.(string) $a['name'], (string) $b['type'].':'.(string) $b['connection'].':'.(string) $b['name']),
        );

        $currentHosts = count($activeManagers);
        $totalWorkerCapacity = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->maxWorkers, $activeManagers));
        $requiredWorkers = array_sum(array_map(static fn (array $workload): int => (int) $workload['target_workers'], $workloads));
        $totalWorkers = array_sum(array_map(static fn (ClusterManagerState $state): int => $state->totalWorkers, $activeManagers));
        $recommendedHosts = $this->recommendedHostCount($activeManagers, $requiredWorkers);
        $signal = $this->clusterScaleSignal($currentHosts, $recommendedHosts, $requiredWorkers, $totalWorkerCapacity);

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
                'cpu_percent' => round($state->cpuPercent, 1),
                'memory_percent' => round($state->memoryPercent, 1),
                'queue_workers' => $state->queueWorkers,
                'group_workers' => $state->groupWorkers,
            ];
        }, $activeManagers);

        return [
            'cluster_id' => AutoscaleConfiguration::clusterAppId(),
            'generated_at' => now()->toIso8601String(),
            'leader_id' => AutoscaleConfiguration::managerId(),
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
     * @return array<string, int|string>
     */
    private function clusterScaleSignal(
        int $currentHosts,
        int $recommendedHosts,
        int $requiredWorkers,
        int $totalWorkerCapacity,
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

    private function clusterFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function clusterInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function clusterString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    private function evaluateAndScale(): void
    {
        // Recalculate metrics first to ensure throughput uses current sliding window
        app(CalculateQueueMetricsAction::class)->executeForAllQueues();

        // Get ALL queues with metrics from laravel-queue-metrics
        // Returns: ['redis:default' => [...metrics array...], ...]
        $allQueues = QueueMetrics::getAllQueuesWithMetrics();

        // Also include configured queues that might not have historical data yet
        // This ensures newly configured queues are monitored from the start
        $configuredQueues = AutoscaleConfiguration::configuredQueues();
        foreach ($configuredQueues as $queueKey => $queueInfo) {
            if (! isset($allQueues[$queueKey])) {
                // Fetch fresh metrics for this queue directly
                $allQueues[$queueKey] = $this->getMetricsForQueue($queueInfo['connection'], $queueInfo['queue']);
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
                    $allQueues[$k] = $this->getMetricsForQueue($group->connection, $memberQueue);
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
            $mappedData = $this->mapMetricsFields($metricsArray);

            // Convert array to QueueMetricsData DTO
            $metrics = QueueMetricsData::fromArray($mappedData);
            $metricsByKey["{$metrics->connection}:{$metrics->queue}"] = $metrics;

            // Skip queues the operator has explicitly excluded from autoscaling.
            if (AutoscaleConfiguration::isExcluded($metrics->queue)) {
                $this->announceExclusion($metrics->connection, $metrics->queue);

                continue;
            }

            // Skip queues that are managed by a group — evaluateGroup handles them.
            if (isset($groupedQueueKeys["{$metrics->connection}:{$metrics->queue}"])) {
                continue;
            }

            $this->evaluateQueue($metrics->connection, $metrics->queue, $metrics);
        }

        // Evaluate each group exactly once per cycle using aggregated metrics.
        foreach ($groups as $group) {
            $this->evaluateGroup($group, $metricsByKey);
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

    /**
     * Get metrics for a specific queue directly (bypasses discovery).
     *
     * @return array<string, mixed>
     */
    private function getMetricsForQueue(string $connection, string $queue): array
    {
        // Get queue depth directly from the queue inspector
        $depth = QueueMetrics::getQueueDepth($connection, $queue);
        $queueMetrics = QueueMetrics::getQueueMetrics($connection, $queue);

        // Calculate oldest job age in seconds from Carbon instance
        $oldestJobAgeSeconds = 0;
        if ($depth->oldestPendingJobAge !== null) {
            $oldestJobAgeSeconds = (int) $depth->oldestPendingJobAge->diffInSeconds(now());
        }

        $total = $depth->pendingJobs + $depth->delayedJobs + $depth->reservedJobs;

        return [
            'connection' => $connection,
            'queue' => $queue,
            'driver' => $this->clusterString(config("queue.connections.{$connection}.driver", 'unknown'), 'unknown'),
            'depth' => [
                'total' => $total,
                'pending' => $depth->pendingJobs,
                'scheduled' => $depth->delayedJobs,
                'reserved' => $depth->reservedJobs,
                'oldest_job_age_seconds' => $oldestJobAgeSeconds,
                'oldest_job_age_status' => $queueMetrics->ageStatus,
            ],
            'performance_60s' => [
                'throughput_per_minute' => $queueMetrics->throughputPerMinute,
                'avg_duration_ms' => $queueMetrics->avgDuration, // Already in ms from metrics package
                'window_seconds' => 60,
            ],
            'lifetime' => [
                'failure_rate_percent' => $queueMetrics->failureRate,
            ],
            'workers' => [
                'active_count' => $queueMetrics->activeWorkers,
                'current_busy_percent' => $queueMetrics->utilizationRate,
                'lifetime_busy_percent' => 0,
            ],
            'baseline' => null,
            'trends' => [],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Map field names from getAllQueuesWithMetrics() to QueueMetricsData::fromArray() format
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapMetricsFields(array $data): array
    {
        // Merge baseline and trends data into health array
        // These will be passed through to HealthStats::fromArray() but ignored by it
        // We'll access them as raw array data in the strategy
        $healthBase = $data['health'] ?? [];
        $healthData = array_merge(
            is_array($healthBase) ? $healthBase : [],
            [
                'baseline' => $data['baseline'] ?? null,
                'trend' => $data['trends'] ?? null,
                'percentiles' => $data['percentiles'] ?? null,
            ]
        );

        // Extract nested depth data
        /** @var array<string, mixed>|int $depthData */
        $depthData = $data['depth'] ?? [];
        $depth = is_array($depthData) ? $this->clusterInt($depthData['total'] ?? 0) : $this->clusterInt($depthData);
        $pending = is_array($depthData) ? $this->clusterInt($depthData['pending'] ?? 0) : 0;
        $scheduled = is_array($depthData) ? $this->clusterInt($depthData['scheduled'] ?? 0) : 0;
        $reserved = is_array($depthData) ? $this->clusterInt($depthData['reserved'] ?? 0) : 0;
        $oldestJobAge = is_array($depthData) ? $this->clusterInt($depthData['oldest_job_age_seconds'] ?? 0) : 0;

        // Extract nested performance data
        /** @var array<string, mixed> $perfData */
        $perfData = is_array($data['performance_60s'] ?? null) ? $data['performance_60s'] : [];
        $throughput = $this->clusterFloat($perfData['throughput_per_minute'] ?? 0.0);
        $avgDurationMs = $this->clusterFloat($perfData['avg_duration_ms'] ?? 0.0);

        // Extract nested lifetime data
        /** @var array<string, mixed> $lifetimeData */
        $lifetimeData = is_array($data['lifetime'] ?? null) ? $data['lifetime'] : [];
        $failureRate = $this->clusterFloat($lifetimeData['failure_rate_percent'] ?? 0.0);

        // Extract nested workers data
        /** @var array<string, mixed> $workersData */
        $workersData = is_array($data['workers'] ?? null) ? $data['workers'] : [];
        $activeWorkers = $this->clusterInt($workersData['active_count'] ?? 0);
        $utilizationRate = $this->clusterFloat($workersData['current_busy_percent'] ?? 0.0);

        return [
            'connection' => $this->clusterString($data['connection'] ?? null, 'default'),
            'queue' => $this->clusterString($data['queue'] ?? null, 'default'),
            'depth' => $depth,
            'pending' => $pending,
            'scheduled' => $scheduled,
            'reserved' => $reserved,
            'oldest_job_age' => $oldestJobAge,
            'age_status' => $this->clusterString($data['oldest_job_age_status'] ?? null, 'normal'),
            'throughput_per_minute' => $throughput,
            'avg_duration' => $avgDurationMs / 1000.0, // Convert ms to seconds
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilizationRate,
            'active_workers' => $activeWorkers,
            'driver' => $this->clusterString($data['driver'] ?? null, 'unknown'),
            'health' => $healthData,
            'calculated_at' => $data['timestamp'] ?? now()->toIso8601String(),
        ];
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
        $scaleCooldownSeconds = $this->clusterInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;
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
            $factor = $decision->capacity->limitingFactor;
            if ($factor === 'cpu' || $factor === 'memory') {
                $this->verbose("     ⚠️  Constrained by system capacity: {$factor}", 'warn');
            } elseif ($factor === 'config') {
                $this->verbose('     ⚠️  Constrained by workers.max config limit', 'warn');
            } elseif ($factor === 'strategy') {
                $this->verbose('     ✓ Optimal worker count determined by demand analysis', 'debug');
            }
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

        // 7. Execute policies (before) - policies can modify the decision
        $finalDecision = $this->policies->beforeScaling($decision);

        // Log if decision was modified by policies
        if ($finalDecision->targetWorkers !== $decision->targetWorkers) {
            $this->verbose("  🔧 Policy modified decision: {$decision->targetWorkers} → {$finalDecision->targetWorkers} workers", 'info');
        }

        // 8. Execute scaling action using potentially modified decision
        if ($finalDecision->shouldScaleUp()) {
            $this->scaleUp($finalDecision);
        } elseif ($finalDecision->shouldScaleDown()) {
            $this->scaleDown($finalDecision);
        } else {
            $this->verbose('  ✓ No scaling action needed', 'debug');
        }

        // 9. Execute policies (after)
        $this->policies->afterScaling($finalDecision);

        // 10. Broadcast events using final decision
        event(new ScalingDecisionMade($finalDecision));

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

        $aggregated = $this->aggregateGroupMetrics($group, $metricsByKey);

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
        $scaleCooldownSeconds = $this->clusterInt(config('queue-autoscale.scaling.cooldown_seconds', 60)) ?: 60;

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

        $finalDecision = $this->policies->beforeScaling($decision);

        if ($finalDecision->shouldScaleUp()) {
            $this->scaleUpGroup($group, $finalDecision);
        } elseif ($finalDecision->shouldScaleDown()) {
            $this->scaleDownGroup($group, $finalDecision);
        } else {
            $this->verbose('  ✓ No group scaling action needed', 'debug');
        }

        $this->policies->afterScaling($finalDecision);
        event(new ScalingDecisionMade($finalDecision));

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

    /**
     * Combine per-member metrics into a single synthetic QueueMetricsData for
     * the group.
     *
     * Aggregation rules (conservative — members must not starve):
     * - pending/scheduled/reserved/throughput: SUM across members
     * - oldestJobAge: MAX across members (worst case drives SLA)
     * - avgDuration: throughput-weighted mean (falls back to simple mean)
     * - utilizationRate: MAX across members
     * - activeWorkers: SUM (informational — ours is derived from pool count)
     * - failureRate: MAX across members
     *
     * @param  array<string, QueueMetricsData>  $metricsByKey
     */
    private function aggregateGroupMetrics(GroupConfiguration $group, array $metricsByKey): QueueMetricsData
    {
        $pending = 0;
        $scheduled = 0;
        $reserved = 0;
        $oldestJobAge = 0;
        $throughput = 0.0;
        $weightedDurationNumer = 0.0;
        $weightedDurationDenom = 0.0;
        $rawDurations = [];
        $utilization = 0.0;
        $activeWorkers = 0;
        $failureRate = 0.0;
        $driver = 'unknown';

        foreach ($group->queues as $queue) {
            $k = "{$group->connection}:{$queue}";

            if (! isset($metricsByKey[$k])) {
                continue;
            }

            $m = $metricsByKey[$k];

            $pending += $m->pending;
            $scheduled += $m->scheduled;
            $reserved += $m->reserved;
            $oldestJobAge = max($oldestJobAge, $m->oldestJobAge);
            $throughput += $m->throughputPerMinute;
            $utilization = max($utilization, $m->utilizationRate);
            $activeWorkers += $m->activeWorkers;
            $failureRate = max($failureRate, $m->failureRate);

            if ($driver === 'unknown') {
                $driver = $m->driver;
            }

            if ($m->avgDuration > 0.0) {
                $rawDurations[] = $m->avgDuration;

                if ($m->throughputPerMinute > 0.0) {
                    $weightedDurationNumer += $m->avgDuration * $m->throughputPerMinute;
                    $weightedDurationDenom += $m->throughputPerMinute;
                }
            }
        }

        $avgDuration = 0.0;

        if ($weightedDurationDenom > 0.0) {
            $avgDuration = $weightedDurationNumer / $weightedDurationDenom;
        } elseif ($rawDurations !== []) {
            $avgDuration = array_sum($rawDurations) / count($rawDurations);
        }

        $depth = $pending + $scheduled + $reserved;
        $ageStatus = $oldestJobAge > $group->sla->targetSeconds ? 'breached'
            : ($oldestJobAge > $group->sla->targetSeconds * 0.8 ? 'warning' : 'normal');

        return QueueMetricsData::fromArray([
            'connection' => $group->connection,
            'queue' => $group->name,
            'depth' => $depth,
            'pending' => $pending,
            'scheduled' => $scheduled,
            'reserved' => $reserved,
            'oldest_job_age' => $oldestJobAge,
            'age_status' => $ageStatus,
            'throughput_per_minute' => $throughput,
            'avg_duration' => $avgDuration,
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilization,
            'active_workers' => $activeWorkers,
            'driver' => $driver,
            'health' => [],
            'calculated_at' => now()->toIso8601String(),
        ]);
    }

    private function scaleUpGroup(GroupConfiguration $group, ScalingDecision $decision): void
    {
        $toAdd = $decision->workersToAdd();

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

        $workers = $this->pool->removeFromGroup($group->connection, $group->name, $toRemove);

        foreach ($workers as $worker) {
            $this->terminator->terminate($worker);
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
     * Supervise a non-scalable (pinned) queue: maintain exactly the pinned
     * worker count. Respawn on death, terminate excess. Never evaluate
     * scaling. Still tracks SLA breach state for observability parity.
     */
    private function superviseQueue(QueueConfiguration $config, QueueMetricsData $metrics): void
    {
        $connection = $config->connection;
        $queue = $config->queue;
        $key = "{$connection}:{$queue}";
        $target = $config->workers->pinnedCount();
        $current = $this->pool->count($connection, $queue);

        $this->verbose("  🔒 Exclusive/pinned queue: enforcing {$target} worker(s), current={$current}", 'debug');

        // Track SLA breach state for events, even though we cannot scale to fix it.
        $isBreaching = $metrics->oldestJobAge > 0 && $metrics->oldestJobAge >= $config->sla->targetSeconds;

        if ($isBreaching) {
            $this->verbose("  🚨 SLA BREACH on pinned queue: oldest_age={$metrics->oldestJobAge}s >= SLA={$config->sla->targetSeconds}s", 'error');
        }

        if ($current < $target) {
            $toAdd = $target - $current;
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

            $workers = $this->pool->remove($connection, $queue, $toRemove);

            foreach ($workers as $worker) {
                $this->terminator->terminate($worker);
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
    }

    private function scaleUp(ScalingDecision $decision): void
    {
        $toAdd = $decision->workersToAdd();

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
        );

        foreach ($workers as $worker) {
            $this->verbose("     ✓ Worker spawned: PID {$worker->pid()}", 'info');
        }

        $this->pool->addMany($workers);

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled up workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'added' => $toAdd,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
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

        $workers = $this->pool->remove(
            $decision->connection,
            $decision->queue,
            $toRemove
        );

        foreach ($workers as $worker) {
            $this->verbose("     ✓ Terminating worker: PID {$worker->pid()}", 'info');
            $this->terminator->terminate($worker);
        }

        Log::channel(AutoscaleConfiguration::logChannel())->info(
            'Scaled down workers',
            [
                'connection' => $decision->connection,
                'queue' => $decision->queue,
                'from' => $decision->currentWorkers,
                'to' => $decision->targetWorkers,
                'removed' => $toRemove,
                'reason' => $decision->reason,
            ]
        );

        event(new WorkersScaled(
            connection: $decision->connection,
            queue: $decision->queue,
            from: $decision->currentWorkers,
            to: $decision->targetWorkers,
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

            $this->verbose("   💀 Removed dead worker: PID {$worker->pid()}", 'warn');

            Log::channel(AutoscaleConfiguration::logChannel())->warning(
                'Removed dead worker',
                ['pid' => $worker->pid()]
            );
        }
    }

    private function processWorkerOutput(): void
    {
        if ($this->renderer === null) {
            return;
        }

        $outputLines = $this->outputBuffer->collectOutput($this->pool->all());

        foreach ($outputLines as $pid => $lines) {
            foreach ($lines as $line) {
                $this->renderer->handleWorkerOutput($pid, $line);
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
                status: $worker->isRunning() ? 'running' : 'dead',
                uptimeSeconds: $worker->uptimeSeconds(),
            );
            $id++;
        }

        return new OutputData(
            queueStats: $this->currentQueueStats,
            workers: $workers,
            recentJobs: [],
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

        foreach ($this->pool->all() as $worker) {
            $this->verbose("   ✓ Terminating worker: PID {$worker->pid()}", 'info');
            $this->terminator->terminate($worker);
        }

        $this->renderer?->shutdown();

        $this->verbose('✓ Shutdown complete', 'info');
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
