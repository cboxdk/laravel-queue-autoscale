<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

test('creates instance with all properties', function () {
    $config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 30,
        minWorkers: 1,
        maxWorkers: 10,
        scaleCooldownSeconds: 60,
    );

    expect($config->connection)->toBe('redis')
        ->and($config->queue)->toBe('default')
        ->and($config->maxPickupTimeSeconds)->toBe(30)
        ->and($config->minWorkers)->toBe(1)
        ->and($config->maxWorkers)->toBe(10)
        ->and($config->scaleCooldownSeconds)->toBe(60);
});

test('fromConfig creates instance from config values', function () {
    config()->set('queue-autoscale.sla_defaults', [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 60,
    ]);
    config()->set('queue-autoscale.queues', []);

    $config = QueueConfiguration::fromConfig('redis', 'default');

    expect($config->connection)->toBe('redis')
        ->and($config->queue)->toBe('default')
        ->and($config->maxPickupTimeSeconds)->toBe(30)
        ->and($config->minWorkers)->toBe(1)
        ->and($config->maxWorkers)->toBe(10)
        ->and($config->scaleCooldownSeconds)->toBe(60);
});

test('fromConfig uses queue-specific overrides', function () {
    config()->set('queue-autoscale.sla_defaults', [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 60,
    ]);
    config()->set('queue-autoscale.queues.high-priority', [
        'max_pickup_time_seconds' => 10,
        'min_workers' => 5,
        'max_workers' => 50,
        'scale_cooldown_seconds' => 30,
    ]);

    $config = QueueConfiguration::fromConfig('redis', 'high-priority');

    expect($config->maxPickupTimeSeconds)->toBe(10)
        ->and($config->minWorkers)->toBe(5)
        ->and($config->maxWorkers)->toBe(50)
        ->and($config->scaleCooldownSeconds)->toBe(30);
});

test('fromConfig uses partial overrides with defaults', function () {
    config()->set('queue-autoscale.sla_defaults', [
        'max_pickup_time_seconds' => 30,
        'min_workers' => 1,
        'max_workers' => 10,
        'scale_cooldown_seconds' => 60,
    ]);
    config()->set('queue-autoscale.queues.partial', [
        'max_pickup_time_seconds' => 15,
        'max_workers' => 20,
    ]);

    $config = QueueConfiguration::fromConfig('redis', 'partial');

    expect($config->maxPickupTimeSeconds)->toBe(15)
        ->and($config->minWorkers)->toBe(1)
        ->and($config->maxWorkers)->toBe(20)
        ->and($config->scaleCooldownSeconds)->toBe(60);
});
