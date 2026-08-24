<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterManagerState;
use Cbox\LaravelQueueAutoscale\Cluster\WorkerDistributor;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ConnectionLimitedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * A tenant's downstream limit is a promise, so it needs proving against real
 * backlogs on the drivers people actually run.
 *
 * Three tenants, a deep queue each, and one glob capping them all at five.
 * What is asserted is the recommendation the autoscaler produces — five
 * workers for each tenant, and five across the whole fleet rather than five
 * per host. Whether five workers then run five jobs at once is Laravel's
 * guarantee that a worker handles one job at a time, not this package's, so it
 * is not restated here.
 *
 * Redis specs need a local Redis; SQS specs need the ElasticMQ container
 * described in tests/Integration/SqsQueueDepthTest.php. Each skips when its
 * dependency is absent.
 */
const TENANT_CONNECTION_LIMIT = 5;

/**
 * Queue names for three tenants.
 *
 * The separator differs by driver and that is not cosmetic: SQS rejects a
 * queue name containing a dot outright ("Can only include alphanumeric
 * characters, hyphens, or underscores"), while Redis is happy with one. A
 * tenant-per-queue scheme therefore has to pick its separator to suit the
 * driver, and the glob has to match whichever was picked.
 *
 * @return list<string>
 */
function tenantQueues(string $separator, string $suffix): array
{
    return array_map(
        static fn (string $name): string => "tenant{$separator}{$name}-{$suffix}",
        ['acme', 'globex', 'initech'],
    );
}

function redisReachable(): bool
{
    try {
        Redis::connection()->command('ping', []);

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * The worker count the autoscaler recommends for a queue, given its real
 * current depth.
 */
function recommendedWorkersFor(string $connection, string $queue): int
{
    $config = QueueConfiguration::fromConfig($connection, $queue);
    $metrics = QueueMetrics::getQueueMetrics($connection, $queue);

    return app(ScalingEngine::class)->evaluateDemand($metrics, $config);
}

beforeEach(function (): void {
    // Two globs, one per naming convention, both pointing at the same rule.
    // The queues below are never named in configuration and are still capped,
    // which is the whole point of matching by pattern.
    $rule = [
        'profile' => ConnectionLimitedProfile::class,
        'workers' => ['max' => TENANT_CONNECTION_LIMIT],
    ];

    config()->set('queue-autoscale.queues', [
        'tenant.*' => $rule,
        'tenant-*' => $rule,
    ]);
});

test('sqs rejects a dotted queue name, so tenant queues there use a hyphen', function (): void {
    // Worth pinning as a spec because it decides the naming scheme for anyone
    // on SQS, and the failure is a remote API error rather than anything this
    // package could warn about.
    if (! elasticMqReachable()) {
        test()->markTestSkipped('ElasticMQ is not running on '.elasticMqEndpoint());
    }

    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $dotted = @file_get_contents(elasticMqEndpoint().'/?Action=CreateQueue&QueueName=tenant.rejected', false, $context);
    $hyphenated = @file_get_contents(elasticMqEndpoint().'/?Action=CreateQueue&QueueName=tenant-accepted', false, $context);

    expect($dotted)->toContain('Can only include alphanumeric characters')
        ->and($hyphenated)->toContain('<QueueUrl>');
});

test('every tenant is capped at its connection limit on redis', function (): void {
    if (! redisReachable()) {
        test()->markTestSkipped('No local Redis');
    }

    $queues = tenantQueues('.', 'redis');

    foreach ($queues as $queue) {
        Redis::del("queues:{$queue}");

        // Far more work than the cap allows, so nothing but the cap can be
        // what holds the recommendation down.
        for ($i = 0; $i < 200; $i++) {
            Queue::connection('redis')->pushRaw(
                json_encode(['id' => "job-{$i}", 'job' => 'noop', 'data' => [], 'attempts' => 0]),
                $queue,
            );
        }
    }

    foreach ($queues as $queue) {
        expect(QueueMetrics::getQueueDepth('redis', $queue)->pendingJobs)->toBe(200)
            ->and(recommendedWorkersFor('redis', $queue))->toBeLessThanOrEqual(TENANT_CONNECTION_LIMIT);
    }

    foreach ($queues as $queue) {
        Redis::del("queues:{$queue}");
    }
});

test('every tenant is capped at its connection limit on sqs', function (): void {
    if (! elasticMqReachable()) {
        test()->markTestSkipped('ElasticMQ is not running on '.elasticMqEndpoint());
    }

    $endpoint = elasticMqEndpoint();
    $suffix = bin2hex(random_bytes(4));
    $queues = tenantQueues('-', $suffix);

    config()->set('queue.connections.sqs', [
        'driver' => 'sqs',
        'key' => 'x',
        'secret' => 'x',
        'prefix' => $endpoint.'/000000000000',
        'queue' => $queues[0],
        'suffix' => '',
        'region' => 'elasticmq',
        'endpoint' => $endpoint,
    ]);

    foreach ($queues as $queue) {
        @file_get_contents($endpoint."/?Action=CreateQueue&QueueName={$queue}");

        for ($i = 0; $i < 40; $i++) {
            Queue::connection('sqs')->pushRaw(
                json_encode(['id' => "job-{$i}", 'job' => 'noop', 'data' => []]),
                $queue,
            );
        }
    }

    foreach ($queues as $queue) {
        expect(QueueMetrics::getQueueDepth('sqs', $queue)->pendingJobs)->toBeGreaterThan(0)
            ->and(recommendedWorkersFor('sqs', $queue))->toBeLessThanOrEqual(TENANT_CONNECTION_LIMIT);
    }
});

test('a tenant that is behind saturates its allowance and stops there', function (): void {
    // The specs above prove the ceiling holds against real backlogs. This one
    // proves the ceiling is actually reached, which needs a queue that is
    // genuinely late — a freshly filled queue is not behind on anything yet,
    // and the engine correctly asks for less than the cap allows.
    //
    // Depth alone would not do it, so the metrics are built rather than
    // enqueued: a thousand jobs, the oldest well past the SLA target, and job
    // durations that make draining them impossible inside the budget.
    foreach (tenantQueues('.', 'pressure') as $queue) {
        $metrics = QueueMetricsFactory::make([
            'connection' => 'redis',
            'queue' => $queue,
            'depth' => 1000,
            'pending' => 1000,
            'oldestJobAge' => 180,
            'ageStatus' => 'critical',
            'avgDuration' => 3000.0,
            'throughputPerMinute' => 40.0,
        ]);

        $config = QueueConfiguration::fromConfig('redis', $queue);
        $demand = app(ScalingEngine::class)->evaluateDemand($metrics, $config);

        expect($demand)->toBe(TENANT_CONNECTION_LIMIT);
    }
});

test('the cap is a fleet total, not a per-host allowance', function (): void {
    if (! redisReachable()) {
        test()->markTestSkipped('No local Redis');
    }

    // The half that a per-host scaler gets wrong. Three hosts with room to
    // spare would each happily run five workers, which is fifteen concurrent
    // callers against an API that permits five.
    $managers = array_map(
        static fn (string $id): ClusterManagerState => new ClusterManagerState(
            managerId: $id,
            host: $id,
            lastSeenAt: (int) (microtime(true) * 1000),
            totalWorkers: 0,
            maxWorkers: 50,
            availableWorkerCapacity: 50,
            capacityLimiter: 'cpu',
            cpuPercent: 10.0,
            cpuCores: 8.0,
            cpuUsableCores: 7.2,
            cpuReservedCores: 0.8,
            memoryPercent: 20.0,
            memoryTotalMb: 8192.0,
            memoryUsedMb: 1638.4,
            memoryFreeMb: 6553.6,
            queueCount: 3,
            groupCount: 0,
            packageVersion: '4.0.0',
            queueWorkers: [],
            groupWorkers: [],
        ),
        ['host-1', 'host-2', 'host-3'],
    );

    $assignedTotals = ['host-1' => 0, 'host-2' => 0, 'host-3' => 0];
    // One distributor across the loop, so the running $assignedTotals and the
    // placement cache carry between tenants exactly as they do on the leader.
    $distributor = new WorkerDistributor;

    foreach (tenantQueues('.', 'redis') as $queue) {
        $assignments = $distributor->distribute(
            $managers,
            "queue:redis:{$queue}",
            TENANT_CONNECTION_LIMIT,
            $assignedTotals,
        );

        expect(array_sum($assignments))->toBe(TENANT_CONNECTION_LIMIT);
    }

    // Three tenants at five each is fifteen workers spread over the fleet —
    // not fifteen per host.
    expect(array_sum($assignedTotals))->toBe(3 * TENANT_CONNECTION_LIMIT);
});

test('an idle tenant is not held at the cap', function (): void {
    if (! redisReachable()) {
        test()->markTestSkipped('No local Redis');
    }

    // The other half of the design: with one queue per customer and most of
    // them quiet, a cap that were also a floor would be ruinous.
    $queue = 'tenant.dormant-'.bin2hex(random_bytes(4));
    Redis::del("queues:{$queue}");

    expect(QueueConfiguration::fromConfig('redis', $queue)->workers->min)->toBe(0)
        ->and(recommendedWorkersFor('redis', $queue))->toBe(0);
});
