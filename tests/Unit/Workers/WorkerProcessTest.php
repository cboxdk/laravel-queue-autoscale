<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->mockProcess = Mockery::mock(Process::class);
    $this->spawnedAt = Carbon::now()->subMinutes(5);
});

afterEach(function () {
    Mockery::close();
});

test('creates instance with all properties', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->process)->toBe($this->mockProcess)
        ->and($worker->connection)->toBe('redis')
        ->and($worker->queue)->toBe('default')
        ->and($worker->spawnedAt)->toBe($this->spawnedAt);
});

test('pid returns process pid', function () {
    $this->mockProcess->shouldReceive('getPid')->andReturn(12345);

    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->pid())->toBe(12345);
});

test('pid returns null when process has no pid', function () {
    $this->mockProcess->shouldReceive('getPid')->andReturn(null);

    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->pid())->toBeNull();
});

test('isRunning delegates to process', function () {
    $this->mockProcess->shouldReceive('isRunning')->andReturn(true);

    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->isRunning())->toBeTrue();
});

test('isDead returns inverse of isRunning', function () {
    $this->mockProcess->shouldReceive('isRunning')->andReturn(false);

    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->isDead())->toBeTrue();
});

test('uptimeSeconds returns time since spawn', function () {
    Carbon::setTestNow(Carbon::now());
    $spawnedAt = Carbon::now()->subSeconds(300);

    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $spawnedAt,
    );

    expect($worker->uptimeSeconds())->toBe(300);

    Carbon::setTestNow();
});

test('matches returns true for matching connection and queue', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->matches('redis', 'default'))->toBeTrue();
});

test('matches returns false for different connection', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->matches('database', 'default'))->toBeFalse();
});

test('matches returns false for different queue', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->matches('redis', 'high'))->toBeFalse();
});

test('matches returns false for different connection and queue', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->matches('database', 'high'))->toBeFalse();
});

test('group worker is identified via matchesGroup', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'email,sms,push',
        spawnedAt: $this->spawnedAt,
        group: 'notifications',
    );

    expect($worker->isGroupWorker())->toBeTrue();
    expect($worker->matchesGroup('redis', 'notifications'))->toBeTrue();
    expect($worker->matchesGroup('redis', 'other'))->toBeFalse();
    expect($worker->matchesGroup('sqs', 'notifications'))->toBeFalse();
});

test('group worker does not match via per-queue matches() even with matching queue string', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'email,sms,push',
        spawnedAt: $this->spawnedAt,
        group: 'notifications',
    );

    // A per-queue operation must not accidentally target a group worker.
    expect($worker->matches('redis', 'email,sms,push'))->toBeFalse();
    expect($worker->matches('redis', 'email'))->toBeFalse();
});

test('per-queue worker reports no group', function () {
    $worker = new WorkerProcess(
        process: $this->mockProcess,
        connection: 'redis',
        queue: 'default',
        spawnedAt: $this->spawnedAt,
    );

    expect($worker->isGroupWorker())->toBeFalse();
    expect($worker->group)->toBeNull();
});
