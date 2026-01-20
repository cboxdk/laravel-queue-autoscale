<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->pool = new WorkerPool;
});

afterEach(function () {
    Mockery::close();
});

function createMockWorker(string $connection, string $queue, int $pid, bool $isRunning = true): WorkerProcess
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('getPid')->andReturn($pid);
    $process->shouldReceive('isRunning')->andReturn($isRunning);

    return new WorkerProcess(
        process: $process,
        connection: $connection,
        queue: $queue,
        spawnedAt: Carbon::now(),
    );
}

test('starts with empty pool', function () {
    expect($this->pool->totalCount())->toBe(0)
        ->and($this->pool->all())->toBeEmpty();
});

test('add adds worker to pool', function () {
    $worker = createMockWorker('redis', 'default', 1001);

    $this->pool->add($worker);

    expect($this->pool->all())->toHaveCount(1);
});

test('addMany adds multiple workers', function () {
    $workers = collect([
        createMockWorker('redis', 'default', 1001),
        createMockWorker('redis', 'default', 1002),
        createMockWorker('redis', 'default', 1003),
    ]);

    $this->pool->addMany($workers);

    expect($this->pool->all())->toHaveCount(3);
});

test('count returns running workers for connection and queue', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001));
    $this->pool->add(createMockWorker('redis', 'default', 1002));
    $this->pool->add(createMockWorker('redis', 'high', 1003));

    expect($this->pool->count('redis', 'default'))->toBe(2)
        ->and($this->pool->count('redis', 'high'))->toBe(1)
        ->and($this->pool->count('database', 'default'))->toBe(0);
});

test('count excludes dead workers', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001, true));
    $this->pool->add(createMockWorker('redis', 'default', 1002, false));

    expect($this->pool->count('redis', 'default'))->toBe(1);
});

test('totalCount returns all running workers', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001, true));
    $this->pool->add(createMockWorker('redis', 'high', 1002, true));
    $this->pool->add(createMockWorker('database', 'default', 1003, false));

    expect($this->pool->totalCount())->toBe(2);
});

test('removeWorker removes specific worker by pid', function () {
    $worker1 = createMockWorker('redis', 'default', 1001);
    $worker2 = createMockWorker('redis', 'default', 1002);

    $this->pool->add($worker1);
    $this->pool->add($worker2);

    $this->pool->removeWorker($worker1);

    expect($this->pool->all())->toHaveCount(1);
});

test('remove returns matching workers', function () {
    $worker = createMockWorker('redis', 'default', 1001);

    $this->pool->add($worker);

    $removed = $this->pool->remove('redis', 'default', 1);

    expect($removed)->toHaveCount(1);
});

test('remove returns empty collection when no matching workers', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001));

    $removed = $this->pool->remove('database', 'default', 2);

    expect($removed)->toBeEmpty()
        ->and($this->pool->all())->toHaveCount(1);
});

test('getDeadWorkers returns only dead workers', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001, true));
    $this->pool->add(createMockWorker('redis', 'default', 1002, false));
    $this->pool->add(createMockWorker('redis', 'default', 1003, false));

    $dead = $this->pool->getDeadWorkers();

    expect($dead)->toHaveCount(2);
});

test('getByConnection returns workers matching connection and queue', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001));
    $this->pool->add(createMockWorker('redis', 'default', 1002));
    $this->pool->add(createMockWorker('redis', 'high', 1003));

    $workers = $this->pool->getByConnection('redis', 'default');

    expect($workers)->toHaveCount(2);
});

test('reset clears all workers', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001));
    $this->pool->add(createMockWorker('redis', 'default', 1002));

    $this->pool->reset();

    expect($this->pool->all())->toBeEmpty();
});

test('all returns all workers regardless of status', function () {
    $this->pool->add(createMockWorker('redis', 'default', 1001, true));
    $this->pool->add(createMockWorker('redis', 'default', 1002, false));

    expect($this->pool->all())->toHaveCount(2);
});
