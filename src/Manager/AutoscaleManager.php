<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Manager;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
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
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
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
     * @var array<string, \Illuminate\Support\Carbon>
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

    private ?OutputInterface $output = null;

    private ?OutputRendererContract $renderer = null;

    private WorkerOutputBuffer $outputBuffer;

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
            $startTime = microtime(true);
            $this->signals->dispatch();

            try {
                $this->processWorkerOutput();
                $this->evaluateAndScale();
                $this->cleanupDeadWorkers();
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

        foreach ($allQueues as $queueKey => $metricsArray) {
            // Map field names from API response to DTO format
            $mappedData = $this->mapMetricsFields($metricsArray);

            // Convert array to QueueMetricsData DTO
            $metrics = QueueMetricsData::fromArray($mappedData);

            // Extract connection and queue from the DTO
            $this->evaluateQueue($metrics->connection, $metrics->queue, $metrics);
        }
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
            'driver' => (string) config("queue.connections.{$connection}.driver", 'unknown'),
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
        $depth = is_array($depthData) ? (int) ($depthData['total'] ?? 0) : (int) $depthData;
        $pending = is_array($depthData) ? (int) ($depthData['pending'] ?? 0) : 0;
        $scheduled = is_array($depthData) ? (int) ($depthData['scheduled'] ?? 0) : 0;
        $reserved = is_array($depthData) ? (int) ($depthData['reserved'] ?? 0) : 0;
        $oldestJobAge = is_array($depthData) ? (int) ($depthData['oldest_job_age_seconds'] ?? 0) : 0;

        // Extract nested performance data
        /** @var array<string, mixed> $perfData */
        $perfData = is_array($data['performance_60s'] ?? null) ? $data['performance_60s'] : [];
        $throughput = (float) ($perfData['throughput_per_minute'] ?? 0.0);
        $avgDurationMs = (float) ($perfData['avg_duration_ms'] ?? 0.0);

        // Extract nested lifetime data
        /** @var array<string, mixed> $lifetimeData */
        $lifetimeData = is_array($data['lifetime'] ?? null) ? $data['lifetime'] : [];
        $failureRate = (float) ($lifetimeData['failure_rate_percent'] ?? 0.0);

        // Extract nested workers data
        /** @var array<string, mixed> $workersData */
        $workersData = is_array($data['workers'] ?? null) ? $data['workers'] : [];
        $activeWorkers = (int) ($workersData['active_count'] ?? 0);
        $utilizationRate = (float) ($workersData['current_busy_percent'] ?? 0.0);

        return [
            'connection' => $data['connection'] ?? 'default',
            'queue' => $data['queue'] ?? 'default',
            'depth' => $depth,
            'pending' => $pending,
            'scheduled' => $scheduled,
            'reserved' => $reserved,
            'oldest_job_age' => $oldestJobAge,
            'age_status' => $data['oldest_job_age_status'] ?? 'normal',
            'throughput_per_minute' => $throughput,
            'avg_duration' => $avgDurationMs / 1000.0, // Convert ms to seconds
            'failure_rate' => $failureRate,
            'utilization_rate' => $utilizationRate,
            'active_workers' => $activeWorkers,
            'driver' => $data['driver'] ?? 'unknown',
            'health' => $healthData,
            'calculated_at' => $data['timestamp'] ?? now()->toIso8601String(),
        ];
    }

    private function evaluateQueue(string $connection, string $queue, QueueMetricsData $metrics): void
    {
        $this->verbose("Evaluating queue: {$connection}:{$queue}", 'debug');
        $this->verbose("  Metrics: pending={$metrics->pending}, oldest_age={$metrics->oldestJobAge}s, active_workers={$metrics->activeWorkers}, throughput={$metrics->throughputPerMinute}/min", 'debug');

        // Warn if throughput data unavailable (needs historical data)
        if ($metrics->throughputPerMinute === 0.0 && $metrics->activeWorkers > 0) {
            $this->verbose('  ⚠️  Throughput=0 despite active workers - metrics package needs more historical data', 'debug');
        }

        // 1. Get configuration
        $config = QueueConfiguration::fromConfig($connection, $queue);

        // 2. Count current workers (per-queue and total pool)
        $currentWorkers = $this->pool->count($connection, $queue);
        $totalPoolWorkers = $this->pool->totalCount();
        $this->verbose("  Current workers: {$currentWorkers} (total pool: {$totalPoolWorkers})", 'debug');

        // 3. Calculate scaling decision (total pool count ensures capacity is shared across queues)
        $decision = $this->engine->evaluate($metrics, $config, $currentWorkers, $totalPoolWorkers);

        // 4. Check for SLA breach
        $isBreaching = $metrics->oldestJobAge > 0 && $metrics->oldestJobAge >= $config->maxPickupTimeSeconds;

        if ($isBreaching) {
            $this->verbose("  🚨 SLA BREACH: oldest_age={$metrics->oldestJobAge}s >= SLA={$config->maxPickupTimeSeconds}s", 'error');
        }

        // 5. Anti-flapping check: prevent direction reversals within cooldown
        // Exception: scale-up during SLA breach is always allowed to protect SLA
        $key = "{$connection}:{$queue}";
        $currentDirection = $decision->shouldScaleUp() ? 'up' : ($decision->shouldScaleDown() ? 'down' : 'hold');
        $lastDirection = $this->lastScaleDirection[$key] ?? null;

        // Clear stale direction: once cooldown has fully elapsed, the last direction
        // is no longer relevant. This prevents HOLD→HOLD→...→DOWN from being blocked
        // by an UP that happened minutes ago.
        if ($lastDirection !== null && ! $this->inCooldown($key, $config->scaleCooldownSeconds)) {
            unset($this->lastScaleDirection[$key]);
            $lastDirection = null;
        }

        // Only apply cooldown if direction is reversing (prevents flapping)
        if ($currentDirection !== 'hold' && $lastDirection !== null && $currentDirection !== $lastDirection) {
            // Always allow scale-up during SLA breach - protecting SLA takes priority over anti-flapping
            $isBreachScaleUp = $currentDirection === 'up' && $isBreaching;

            if (! $isBreachScaleUp && $this->inCooldown($key, $config->scaleCooldownSeconds)) {
                $remaining = $this->getCooldownRemaining($key, $config->scaleCooldownSeconds);
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
                $this->verbose('     ⚠️  Constrained by max_workers config limit', 'warn');
            } elseif ($factor === 'strategy') {
                $this->verbose('     ✓ Optimal worker count determined by demand analysis', 'debug');
            }
        }

        // 6b. Store queue stats for renderer
        $slaStatus = $isBreaching ? 'breached' : ($metrics->oldestJobAge > $config->maxPickupTimeSeconds * 0.8 ? 'warning' : 'ok');
        $this->currentQueueStats[$key] = new QueueStats(
            connection: $connection,
            queue: $queue,
            depth: $metrics->pending,
            pending: $metrics->pending,
            throughputPerMinute: $metrics->throughputPerMinute,
            oldestJobAge: $metrics->oldestJobAge,
            slaTarget: $config->maxPickupTimeSeconds,
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
                slaTarget: $config->maxPickupTimeSeconds,
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
                slaTarget: $config->maxPickupTimeSeconds,
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

        $workers = $this->spawner->spawn(
            $decision->connection,
            $decision->queue,
            $toAdd
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
