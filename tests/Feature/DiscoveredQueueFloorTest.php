<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Illuminate\Support\Collection;

/**
 * Queues are DISCOVERED from metrics, not registered. An app that mints a queue
 * name per tenant therefore presents thousands of them, and every one matching
 * no configured rule used to inherit the default profile's worker floor — a
 * floor the engine deliberately applies AFTER the CPU/memory clamp, so measured
 * capacity could not save you. `limits.max_total_workers`, the backstop written
 * for exactly this, ships unset.
 *
 * The result was one permanently-running `queue:work` process per queue name
 * ever seen, at roughly 50-100 MB each, bounded by nothing — the OOM the
 * orphaned-worker reaper exists to clean up after.
 *
 * No unit test catches this: discovery, config fall-through, the floor's
 * placement after the clamp, and the unset ceiling are each individually
 * correct. Only the composition is wrong, so the guard runs a real cycle and
 * counts what the manager actually asks to be spawned.
 */
final class SpawnRequests
{
    /** @var array<string, int> */
    public array $byQueue = [];

    public function total(): int
    {
        return array_sum($this->byQueue);
    }
}

function recordingSpawner(SpawnRequests $requests): void
{
    app()->instance(WorkerSpawner::class, new readonly class(app(SpawnLatencyTrackerContract::class), $requests) extends WorkerSpawner
    {
        public function __construct(
            SpawnLatencyTrackerContract $tracker,
            private SpawnRequests $requests,
        ) {
            parent::__construct($tracker);
        }

        public function spawn(
            string $connection,
            string $queue,
            int $count,
            SpawnCompensationConfiguration $spawnConfig,
            ?string $group = null,
            ?WorkerConfiguration $workerConfig = null,
        ): Collection {
            $key = "{$connection}:{$queue}";
            $this->requests->byQueue[$key] = ($this->requests->byQueue[$key] ?? 0) + $count;

            // No real processes: this spec is about what the manager DEMANDS,
            // and starting fifty of them would measure the OS, not the fix.
            return new Collection;
        }
    });

    app()->forgetInstance(AutoscaleManager::class);
}

function runOneEvaluation(): void
{
    $manager = app(AutoscaleManager::class);
    (new ReflectionMethod($manager, 'evaluateAndScale'))->invoke($manager);
}

beforeEach(function (): void {
    config()->set('queue-autoscale.sla_defaults', BalancedProfile::class);
    config()->set('queue-autoscale.cluster.enabled', false);
    config()->set('queue-autoscale.limits.max_total_workers', null);

    stubMetricsRecalculation();

    $this->requests = new SpawnRequests;
    recordingSpawner($this->requests);
});

test('fifty idle discovered queues nobody configured demand no workers', function (): void {
    config()->set('queue-autoscale.queues', []);

    $discovered = [];
    for ($i = 0; $i < 50; $i++) {
        $discovered["redis:tenant-{$i}-exports"] = rawDiscoveredMetrics('redis', "tenant-{$i}-exports", pending: 0);
    }
    fakeDiscoveredQueues($discovered);

    runOneEvaluation();

    // Before the floor was withdrawn this was 50 — one permanent worker per
    // tenant queue name, each holding a full framework boot in memory.
    expect($this->requests->total())->toBe(0);
});

test('a named queue still gets its floor while discovered ones do not', function (): void {
    config()->set('queue-autoscale.queues', ['reports' => ['workers' => ['min' => 2, 'max' => 4]]]);

    fakeDiscoveredQueues([
        'redis:reports' => rawDiscoveredMetrics('redis', 'reports', pending: 0),
        'redis:tenant-a-exports' => rawDiscoveredMetrics('redis', 'tenant-a-exports', pending: 0),
        'redis:tenant-b-exports' => rawDiscoveredMetrics('redis', 'tenant-b-exports', pending: 0),
    ]);

    runOneEvaluation();

    expect($this->requests->byQueue)->toHaveKey('redis:reports')
        ->and($this->requests->byQueue['redis:reports'])->toBe(2)
        ->and($this->requests->byQueue)->not->toHaveKey('redis:tenant-a-exports')
        ->and($this->requests->byQueue)->not->toHaveKey('redis:tenant-b-exports');
});

test('a discovered queue with real backlog still scales up', function (): void {
    // Withdrawing the floor must not stop a queue being served — it removes
    // the standing promise, not the response to demand.
    config()->set('queue-autoscale.queues', []);

    fakeDiscoveredQueues([
        'redis:tenant-busy' => rawDiscoveredMetrics('redis', 'tenant-busy', pending: 500),
    ]);

    runOneEvaluation();

    expect($this->requests->total())->toBeGreaterThan(0);
});

test('a wildcard rule restores the floor for every discovered queue', function (): void {
    // The documented one-line migration for anyone relying on the old default.
    config()->set('queue-autoscale.queues', ['*' => ['workers' => ['min' => 1]]]);

    fakeDiscoveredQueues([
        'redis:tenant-a' => rawDiscoveredMetrics('redis', 'tenant-a', pending: 0),
        'redis:tenant-b' => rawDiscoveredMetrics('redis', 'tenant-b', pending: 0),
    ]);

    runOneEvaluation();

    expect($this->requests->total())->toBe(2);
});
