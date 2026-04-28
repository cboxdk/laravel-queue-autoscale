<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

test('returns configured queues keyed by connection:queue', function (): void {
    config(['queue-autoscale.queues' => [
        'default' => ['connection' => 'redis'],
        'payments' => ['connection' => 'sqs'],
    ]]);

    $queues = AutoscaleConfiguration::configuredQueues();

    expect($queues)->toBe([
        'redis:default' => ['connection' => 'redis', 'queue' => 'default'],
        'sqs:payments' => ['connection' => 'sqs', 'queue' => 'payments'],
    ]);
});

test('returns empty array when no queues configured', function (): void {
    config(['queue-autoscale.queues' => []]);

    expect(AutoscaleConfiguration::configuredQueues())->toBeEmpty();
});

test('throws on numeric-keyed queues config (list-of-dicts shape)', function (): void {
    config(['queue-autoscale.queues' => [
        ['connection' => 'redis', 'queue' => 'default'],
    ]]);

    AutoscaleConfiguration::configuredQueues();
})->throws(
    InvalidArgumentException::class,
    'queue-autoscale.queues'
);
