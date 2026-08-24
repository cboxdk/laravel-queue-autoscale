<?php

namespace Cbox\LaravelQueueAutoscale\Tests;

use Cbox\LaravelQueueAutoscale\LaravelQueueAutoscaleServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            LaravelQueueAutoscaleServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        $this->configureRedis();
    }

    /**
     * Point the `default` Redis connection at a cluster when
     * REDIS_CLUSTER_HOSTS_AND_PORTS is set, otherwise a single node, so the
     * redis-group specs run against both modes depending on the CI job.
     */
    private function configureRedis(): void
    {
        $clusterHosts = getenv('REDIS_CLUSTER_HOSTS_AND_PORTS');

        if (is_string($clusterHosts) && $clusterHosts !== '') {
            config()->set('database.redis', [
                'client' => 'phpredis',
                'options' => [
                    'cluster' => 'redis',
                    'prefix' => 'lqas_test_',
                ],
                'clusters' => [
                    'default' => array_map(
                        static fn (string $hostAndPort): array => [
                            'host' => explode(':', $hostAndPort)[0],
                            'port' => (int) explode(':', $hostAndPort)[1],
                        ],
                        explode(',', $clusterHosts),
                    ),
                ],
            ]);

            return;
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = getenv('REDIS_PORT') ?: '6379';

        config()->set('database.redis', [
            'client' => 'phpredis',
            'options' => [
                'prefix' => 'lqas_test_',
            ],
            'default' => [
                'host' => $host,
                'port' => (int) $port,
                'database' => 0,
                'timeout' => 1.0,
            ],
        ]);
    }
}
