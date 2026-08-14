<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Support\WorkloadName;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;

test('ordinary names are accepted', function (string $name): void {
    expect(WorkloadName::isSafe($name))->toBeTrue();
})->with(['default', 'payments', 'tenant-42', 'tenant_42', 'tenant.42', 'orders.fifo', 'a:b']);

test('a comma is refused, because queue:work reads it as a list', function (): void {
    // The multi-tenant case: a queue named 'tenant-me,tenant-victim' would
    // spawn a worker draining someone else's queue. Queue names are
    // discovered, so this is not necessarily a name the operator chose.
    expect(WorkloadName::isSafe('tenant-me,tenant-victim'))->toBeFalse()
        ->and(WorkloadName::reason('a,b'))->toContain('list of queues');
});

test('a leading dash is refused, because it parses as an option', function (): void {
    // The connection is a bare positional argument, so '--env=staging' would
    // boot the worker against different credentials entirely.
    expect(WorkloadName::isSafe('--env=staging'))->toBeFalse()
        ->and(WorkloadName::isSafe('--memory=1'))->toBeFalse()
        ->and(WorkloadName::reason('--env=x'))->toContain('command-line option');
});

test('whitespace and control characters are refused', function (string $name): void {
    // Illegal in cache keys on some stores, where the write fails silently and
    // the fuse then reads zero and can never trip.
    expect(WorkloadName::isSafe($name))->toBeFalse();
})->with(['has space', "has\ttab", "has\nnewline", "has\0nul", 'trailing ']);

test('an empty name is refused', function (): void {
    expect(WorkloadName::isSafe(''))->toBeFalse();
});

test('the spawner refuses an unsafe name rather than building the command', function (): void {
    $spawner = new WorkerSpawner(app(SpawnLatencyTrackerContract::class));

    expect(fn () => $spawner->spawn(
        connection: 'redis',
        queue: 'tenant-me,tenant-victim',
        count: 1,
        spawnConfig: new SpawnCompensationConfiguration(false, 2.0, 5, 0.2),
    ))->toThrow(InvalidArgumentException::class, 'list of queues');
});

test('the spawner refuses an unsafe connection', function (): void {
    $spawner = new WorkerSpawner(app(SpawnLatencyTrackerContract::class));

    expect(fn () => $spawner->spawn(
        connection: '--env=staging',
        queue: 'default',
        count: 1,
        spawnConfig: new SpawnCompensationConfiguration(false, 2.0, 5, 0.2),
    ))->toThrow(InvalidArgumentException::class, 'command-line option');
});

test('a group worker may poll a comma-joined queue list', function (): void {
    // The package joins a group's member queues with commas on purpose, so for
    // a group the comma is the separator rather than an injected one. Refusing
    // the joined argument stopped every group worker from starting — shipped
    // in v4.0.0 and caught only when a test finally invoked the group path.
    $spawner = new WorkerSpawner(app(SpawnLatencyTrackerContract::class));

    expect($spawner->buildCommand('redis', 'email,sms', new WorkerConfiguration(
        min: 1, max: 2, tries: 3, maxTimeSeconds: 3600,
        timeoutSeconds: 900, sleepSeconds: 3, shutdownTimeoutSeconds: 30,
    )))->toContain('--queue=email,sms');
});

test('a group member is still checked on its own', function (): void {
    // The separator is allowed; an injected option inside a member is not.
    $spawner = new WorkerSpawner(app(SpawnLatencyTrackerContract::class));

    expect(fn () => $spawner->spawn(
        connection: 'redis',
        queue: 'email,--env=staging',
        count: 1,
        spawnConfig: new SpawnCompensationConfiguration(false, 2.0, 5, 0.2),
        group: 'notifications',
    ))->toThrow(InvalidArgumentException::class, 'command-line option');
});

test('a comma is still refused for a non-group queue', function (): void {
    $spawner = new WorkerSpawner(app(SpawnLatencyTrackerContract::class));

    expect(fn () => $spawner->spawn(
        connection: 'redis',
        queue: 'tenant-me,tenant-victim',
        count: 1,
        spawnConfig: new SpawnCompensationConfiguration(false, 2.0, 5, 0.2),
    ))->toThrow(InvalidArgumentException::class, 'list of queues');
});
