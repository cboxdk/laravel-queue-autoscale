<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

function makeBaseConfig(string $queue, array $members = []): QueueConfiguration
{
    return new QueueConfiguration(
        connection: 'redis',
        queue: $queue,
        sla: new SlaConfiguration(30, 95, 300, 20),
        forecast: new ForecastConfiguration(
            LinearRegressionForecaster::class,
            ModerateForecastPolicy::class,
            60,
            300,
        ),
        spawnCompensation: new SpawnCompensationConfiguration(true, 2.0, 5, 0.2),
        workers: new WorkerConfiguration(1, 10, 3, 3600, 3, 30),
        memberQueues: $members,
    );
}

test('sampleQueues returns singleton queue for per-queue config', function (): void {
    $cfg = makeBaseConfig('payments');

    expect($cfg->sampleQueues())->toBe(['payments']);
});

test('sampleQueues returns member list for group-adapted config', function (): void {
    $cfg = makeBaseConfig('notifications', members: ['email', 'sms', 'push']);

    expect($cfg->sampleQueues())->toBe(['email', 'sms', 'push']);
});

test('memberQueues defaults to empty array', function (): void {
    $cfg = makeBaseConfig('default');

    expect($cfg->memberQueues)->toBe([]);
});
