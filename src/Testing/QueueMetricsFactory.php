<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Testing;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\HealthStats;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

/**
 * Builds the metrics snapshot a scaling decision is made from.
 *
 * `QueueMetricsData` has sixteen constructor arguments, of which any one test
 * cares about two or three. Restating the other thirteen buries the fact under
 * the scaffolding, and worse, invites each spec to invent its own defaults —
 * so two tests exercising "an idle queue" end up meaning different things by
 * it.
 */
class QueueMetricsFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(array $overrides = []): QueueMetricsData
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
            'avgDuration' => 100.0,
            'failureRate' => 0.0,
            'utilizationRate' => 0.0,
            'activeWorkers' => 0,
            'driver' => 'redis',
            'calculatedAt' => Carbon::now(),
        ];

        /** @var array<string, mixed> $data */
        $data = array_merge($defaults, $overrides);

        return new QueueMetricsData(
            connection: self::str($data['connection']),
            queue: self::str($data['queue']),
            depth: self::int($data['depth']),
            pending: self::int($data['pending']),
            scheduled: self::int($data['scheduled']),
            reserved: self::int($data['reserved']),
            oldestJobAge: self::int($data['oldestJobAge']),
            ageStatus: self::str($data['ageStatus']),
            throughputPerMinute: self::float($data['throughputPerMinute']),
            avgDuration: self::float($data['avgDuration']),
            failureRate: self::float($data['failureRate']),
            utilizationRate: self::float($data['utilizationRate']),
            activeWorkers: self::int($data['activeWorkers']),
            driver: self::str($data['driver']),
            health: new HealthStats(
                status: self::str($data['ageStatus']),
                score: 100.0,
                depth: self::int($data['depth']),
                oldestJobAge: self::int($data['oldestJobAge']),
                failureRate: self::float($data['failureRate']),
                utilizationRate: self::float($data['utilizationRate']),
            ),
            calculatedAt: $data['calculatedAt'] instanceof Carbon ? $data['calculatedAt'] : Carbon::now(),
        );
    }

    /**
     * A queue with nothing waiting.
     */
    public static function idle(string $queue = 'default'): QueueMetricsData
    {
        return self::make(['queue' => $queue]);
    }

    /**
     * A queue with a backlog that is not yet late.
     *
     * Worth distinguishing from `behind()`: a full queue and a late queue lead
     * to very different targets, because the drain calculation contributes
     * nothing until the SLA budget is half spent.
     */
    public static function backlogged(int $pending, string $queue = 'default'): QueueMetricsData
    {
        return self::make([
            'queue' => $queue,
            'depth' => $pending,
            'pending' => $pending,
            'oldestJobAge' => 1,
        ]);
    }

    /**
     * A queue whose oldest job has been waiting long enough to matter.
     */
    public static function behind(int $pending, int $oldestJobAge, string $queue = 'default'): QueueMetricsData
    {
        return self::make([
            'queue' => $queue,
            'depth' => $pending,
            'pending' => $pending,
            'oldestJobAge' => $oldestJobAge,
            'ageStatus' => 'critical',
        ]);
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
