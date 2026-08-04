<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Testing;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPUnit\Framework\Assert;

/**
 * Test helpers for applications that configure this package.
 *
 * The question a consumer wants to answer is "given this queue in this state,
 * how many workers does my configuration ask for" — and answering it directly
 * means assembling an engine, a config, a metrics DTO and two stores. This
 * trait does that assembly, so a spec is one line of intent and one assertion.
 *
 * Everything here drives the real ScalingEngine with the application's real
 * configuration. Only the two stores are substituted, and only because they
 * would otherwise need Redis.
 */
trait InteractsWithAutoscaling
{
    /**
     * Replace the fuse's window store with an in-memory one.
     *
     * Returns it, so a spec can seed failures and drive the fuse through its
     * states without waiting for real cooldowns.
     */
    protected function fakeFailureWindows(): InMemoryFailureWindowStore
    {
        $store = new InMemoryFailureWindowStore;
        app()->instance(FailureWindowStoreContract::class, $store);

        return $store;
    }

    /**
     * Replace the cluster store with an in-memory one.
     */
    protected function fakeCluster(): FakeClusterStore
    {
        $store = new FakeClusterStore;
        app()->instance(ClusterStoreContract::class, $store);

        return $store;
    }

    /**
     * The worker count the autoscaler asks for, given a queue in this state.
     *
     * This is cluster-wide demand: the strategy's answer bounded by the
     * queue's own min and max and by the fuse. It deliberately does not apply
     * host CPU and memory limits, because those depend on the machine running
     * the test and would make the same configuration assert differently on a
     * laptop and in CI. To test the host ceiling itself, use CapacityCalculator
     * directly.
     */
    protected function workersDemandedFor(QueueMetricsData $metrics, ?QueueConfiguration $config = null): int
    {
        $config ??= QueueConfiguration::fromConfig($metrics->connection, $metrics->queue);

        return app()->make(ScalingEngine::class)->evaluateDemand($metrics, $config);
    }

    /**
     * Assert the configuration asks for a specific worker count.
     */
    protected function assertWorkersDemanded(int $expected, QueueMetricsData $metrics, ?QueueConfiguration $config = null): void
    {
        $actual = $this->workersDemandedFor($metrics, $config);

        Assert::assertSame(
            $expected,
            $actual,
            "Expected {$metrics->connection}:{$metrics->queue} to demand {$expected} workers, got {$actual}."
        );
    }

    /**
     * Assert a queue can never exceed a worker count, however deep it gets.
     *
     * The assertion a downstream connection limit actually needs: not that a
     * particular backlog produces a particular number, but that no backlog
     * produces more than the cap.
     */
    protected function assertWorkersCappedAt(int $cap, string $queue, string $connection = 'redis'): void
    {
        $config = QueueConfiguration::fromConfig($connection, $queue);

        foreach ([10, 1_000, 100_000] as $pending) {
            $metrics = QueueMetricsFactory::behind($pending, oldestJobAge: 3_600, queue: $queue);
            $actual = $this->workersDemandedFor($metrics, $config);

            Assert::assertLessThanOrEqual(
                $cap,
                $actual,
                "{$connection}:{$queue} demanded {$actual} workers with {$pending} pending, above its cap of {$cap}."
            );
        }
    }

    /**
     * Drive a queue's fuse to tripped, so a spec can assert what happens next.
     */
    protected function tripFuseFor(string $queue, string $connection = 'redis'): InMemoryFailureWindowStore
    {
        $store = app()->make(FailureWindowStoreContract::class);

        if (! $store instanceof InMemoryFailureWindowStore) {
            $store = $this->fakeFailureWindows();
        }

        $config = QueueConfiguration::fromConfig($connection, $queue);
        $samples = max($config->fuse->minSamples, 1);

        $store->seedWindow($samples, $samples, $connection, $queue);

        return $store;
    }
}
