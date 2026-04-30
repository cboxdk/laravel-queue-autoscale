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

it('returns empty array when queue has no resources configured', function (): void {
    config()->set('queue-autoscale.queues', [
        'fast' => ['sla' => ['target_seconds' => 10]],
    ]);

    $resources = AutoscaleConfiguration::queueResources('fast');

    expect($resources)->toBe([]);
});

it('returns configured resources for a queue', function (): void {
    config()->set('queue-autoscale.queues', [
        'slow' => [
            'resources' => [
                'cpu_cores' => 0.5,
                'memory_mb' => 2048,
            ],
        ],
    ]);

    $resources = AutoscaleConfiguration::queueResources('slow');

    expect($resources)->toBe([
        'cpu_cores' => 0.5,
        'memory_mb' => 2048,
    ]);
});

it('returns partial resources when only one dimension configured', function (): void {
    config()->set('queue-autoscale.queues', [
        'heavy' => [
            'resources' => [
                'memory_mb' => 4096,
            ],
        ],
    ]);

    $resources = AutoscaleConfiguration::queueResources('heavy');

    expect($resources)->toBe(['memory_mb' => 4096]);
});

it('returns empty array when queue is not configured at all', function (): void {
    config()->set('queue-autoscale.queues', []);

    $resources = AutoscaleConfiguration::queueResources('nonexistent');

    expect($resources)->toBe([]);
});
