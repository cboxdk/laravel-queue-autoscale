<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\HealthStats;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

class MetricsHelper
{
    public static function createMetrics(array $overrides = []): QueueMetricsData
    {
        $defaults = [
            'connection' => 'redis',
            'queue' => 'default',
            'depth' => 0,
            'pending' => 0,
            'scheduled' => 0,
            'reserved' => 0,
            'oldestJobAge' => 0,
            'ageStatus' => 'healthy',
            'throughputPerMinute' => 0.0,
            'avgDuration' => 0.0,
            'failureRate' => 0.0,
            'utilizationRate' => 0.0,
            'activeWorkers' => 0,
            'driver' => 'redis',
            'calculatedAt' => Carbon::now(),
        ];

        $data = array_merge($defaults, $overrides);

        // Create HealthStats object
        $health = new HealthStats(
            status: $data['ageStatus'] ?? 'healthy',
            score: 100.0,
            depth: $data['depth'],
            oldestJobAge: $data['oldestJobAge'],
            failureRate: $data['failureRate'],
            utilizationRate: $data['utilizationRate'],
        );

        return new QueueMetricsData(
            connection: $data['connection'],
            queue: $data['queue'],
            depth: $data['depth'],
            pending: $data['pending'],
            scheduled: $data['scheduled'],
            reserved: $data['reserved'],
            oldestJobAge: $data['oldestJobAge'],
            ageStatus: $data['ageStatus'],
            throughputPerMinute: $data['throughputPerMinute'],
            avgDuration: $data['avgDuration'],
            failureRate: $data['failureRate'],
            utilizationRate: $data['utilizationRate'],
            activeWorkers: $data['activeWorkers'],
            driver: $data['driver'],
            health: $health,
            calculatedAt: $data['calculatedAt'],
        );
    }
}
