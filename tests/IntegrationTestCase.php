<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests;

use Cbox\LaravelQueueMetrics\LaravelQueueMetricsServiceProvider;
use Illuminate\Foundation\Application;

/**
 * Base case for tests that talk to real infrastructure.
 *
 * The unit and feature suites fake the metrics facade, so they never register
 * queue-metrics' own provider and its repositories are never bound. An
 * integration test is exercising the real path end to end, which means it
 * needs the real bindings.
 *
 * The database storage driver is chosen deliberately: it works against the
 * in-memory sqlite the suite already uses, so an integration test needs only
 * the one piece of infrastructure it is actually about.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            LaravelQueueMetricsServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    /**
     * @param  Application  $app
     */
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        config()->set('queue-metrics.storage.driver', 'database');
    }
}
