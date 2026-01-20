<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/**
 * Tests for Worker Management Classes
 *
 * Note: These tests focus on WorkerProcess and WorkerPool logic.
 * WorkerSpawner and WorkerTerminator require process spawning which is
 * integration-tested rather than unit-tested.
 */
describe('WorkerProcess', function () {
    beforeEach(function () {
        // Create a simple process for testing (non-running)
        $this->process = new Process(['echo', 'test']);
        $this->worker = new WorkerProcess(
            process: $this->process,
            connection: 'redis',
            queue: 'default',
            spawnedAt: now()->subSeconds(30),
        );
    });

    it('provides access to readonly properties', function () {
        expect($this->worker->connection)->toBe('redis')
            ->and($this->worker->queue)->toBe('default')
            ->and($this->worker->spawnedAt)->toBeInstanceOf(Carbon::class);
    });

    it('returns pid when process has started', function () {
        // Start a long-running process to get a PID
        $process = new Process(['sleep', '1']);
        $worker = new WorkerProcess(
            process: $process,
            connection: 'redis',
            queue: 'default',
            spawnedAt: now(),
        );

        $process->start();

        expect($worker->pid())->toBeInt();

        // Cleanup
        $process->stop();
    });

    it('returns null pid when process has not started', function () {
        expect($this->worker->pid())->toBeNull();
    });

    it('reports running status correctly', function () {
        // Before starting
        expect($this->worker->isRunning())->toBe(false);

        // Start a long-running process for testing
        $longProcess = new Process(['sleep', '0.1']);
        $runningWorker = new WorkerProcess(
            process: $longProcess,
            connection: 'redis',
            queue: 'default',
            spawnedAt: now(),
        );
        $longProcess->start();

        expect($runningWorker->isRunning())->toBe(true);

        // Wait for completion
        $longProcess->wait();

        expect($runningWorker->isRunning())->toBe(false);
    });

    it('reports dead status correctly', function () {
        expect($this->worker->isDead())->toBe(true);

        $process = new Process(['sleep', '0.1']);
        $worker = new WorkerProcess(
            process: $process,
            connection: 'redis',
            queue: 'default',
            spawnedAt: now(),
        );
        $process->start();

        expect($worker->isDead())->toBe(false);

        $process->wait();

        expect($worker->isDead())->toBe(true);
    });

    it('calculates uptime correctly', function () {
        $spawnedAt = now()->subSeconds(45);
        $worker = new WorkerProcess(
            process: $this->process,
            connection: 'redis',
            queue: 'default',
            spawnedAt: $spawnedAt,
        );

        $uptime = $worker->uptimeSeconds();

        // Should be approximately 45 seconds (allow 1 second tolerance)
        expect($uptime)->toBeGreaterThanOrEqual(44)
            ->and($uptime)->toBeLessThanOrEqual(46);
    });

    it('matches connection and queue correctly', function () {
        expect($this->worker->matches('redis', 'default'))->toBe(true)
            ->and($this->worker->matches('redis', 'emails'))->toBe(false)
            ->and($this->worker->matches('sqs', 'default'))->toBe(false)
            ->and($this->worker->matches('sqs', 'emails'))->toBe(false);
    });
});

describe('WorkerPool', function () {
    beforeEach(function () {
        $this->pool = new WorkerPool;

        // Helper to create worker with specific state
        $this->createWorker = function (string $connection, string $queue, bool $running = true): WorkerProcess {
            $process = new Process($running ? ['sleep', '10'] : ['echo', 'done']);

            if ($running) {
                $process->start();
            }

            return new WorkerProcess(
                process: $process,
                connection: $connection,
                queue: $queue,
                spawnedAt: now(),
            );
        };
    });

    afterEach(function () {
        // Clean up any running processes
        foreach ($this->pool->all() as $worker) {
            if ($worker->isRunning()) {
                $worker->process->stop();
            }
        }
    });

    it('starts empty', function () {
        expect($this->pool->totalCount())->toBe(0)
            ->and($this->pool->all())->toHaveCount(0);
    });

    it('adds workers to the pool', function () {
        $worker = ($this->createWorker)('redis', 'default');

        $this->pool->add($worker);

        expect($this->pool->totalCount())->toBe(1)
            ->and($this->pool->count('redis', 'default'))->toBe(1);
    });

    it('adds many workers at once', function () {
        $workers = collect([
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'emails'),
        ]);

        $this->pool->addMany($workers);

        expect($this->pool->totalCount())->toBe(3)
            ->and($this->pool->count('redis', 'default'))->toBe(2)
            ->and($this->pool->count('redis', 'emails'))->toBe(1);
    });

    it('removes specific worker from pool', function () {
        $worker1 = ($this->createWorker)('redis', 'default');
        $worker2 = ($this->createWorker)('redis', 'default');

        $this->pool->add($worker1);
        $this->pool->add($worker2);

        expect($this->pool->totalCount())->toBe(2);

        $this->pool->removeWorker($worker1);

        expect($this->pool->totalCount())->toBe(1);
    });

    it('removes N workers for a queue', function () {
        $workers = collect([
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'emails'),
        ]);

        $this->pool->addMany($workers);

        $removed = $this->pool->remove('redis', 'default', 2);

        expect($removed)->toHaveCount(2)
            ->and($this->pool->count('redis', 'default'))->toBe(1)
            ->and($this->pool->count('redis', 'emails'))->toBe(1);
    });

    it('counts workers by connection and queue', function () {
        $workers = collect([
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'emails'),
            ($this->createWorker)('sqs', 'default'),
        ]);

        $this->pool->addMany($workers);

        expect($this->pool->count('redis', 'default'))->toBe(2)
            ->and($this->pool->count('redis', 'emails'))->toBe(1)
            ->and($this->pool->count('sqs', 'default'))->toBe(1)
            ->and($this->pool->count('sqs', 'emails'))->toBe(0);
    });

    it('counts only running workers', function () {
        $runningWorker = ($this->createWorker)('redis', 'default', true);
        $deadWorker = ($this->createWorker)('redis', 'default', false);

        $this->pool->add($runningWorker);
        $this->pool->add($deadWorker);

        // Only running worker should be counted
        expect($this->pool->count('redis', 'default'))->toBe(1)
            ->and($this->pool->totalCount())->toBe(1);
    });

    it('returns all workers including dead ones', function () {
        $runningWorker = ($this->createWorker)('redis', 'default', true);
        $deadWorker = ($this->createWorker)('redis', 'default', false);

        $this->pool->add($runningWorker);
        $this->pool->add($deadWorker);

        expect($this->pool->all())->toHaveCount(2);
    });

    it('identifies dead workers', function () {
        $runningWorker = ($this->createWorker)('redis', 'default', true);
        $deadWorker1 = ($this->createWorker)('redis', 'default', false);
        $deadWorker2 = ($this->createWorker)('redis', 'emails', false);

        $this->pool->add($runningWorker);
        $this->pool->add($deadWorker1);
        $this->pool->add($deadWorker2);

        $deadWorkers = $this->pool->getDeadWorkers();

        expect($deadWorkers)->toHaveCount(2);
    });

    it('handles empty pool operations safely', function () {
        expect($this->pool->count('redis', 'default'))->toBe(0)
            ->and($this->pool->totalCount())->toBe(0)
            ->and($this->pool->getDeadWorkers())->toHaveCount(0);

        $removed = $this->pool->remove('redis', 'default', 5);

        expect($removed)->toHaveCount(0);
    });

    it('respects count limit when removing workers', function () {
        $workers = collect([
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'default'),
            ($this->createWorker)('redis', 'default'),
        ]);

        $this->pool->addMany($workers);

        // Try to remove 10 but only 3 exist
        $removed = $this->pool->remove('redis', 'default', 10);

        expect($removed)->toHaveCount(3)
            ->and($this->pool->count('redis', 'default'))->toBe(0);
    });
});
