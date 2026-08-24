<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\WorkerOutputBuffer;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;

/**
 * A worker stub whose stream reads return the given chunks in call order,
 * repeating the last chunk once exhausted.
 */
function bufferedOutputWorker(
    ?int $pid,
    array $stdoutChunks = [''],
    array $stderrChunks = [''],
    bool $running = true,
): WorkerProcess {
    $worker = Mockery::mock(WorkerProcess::class);
    $worker->shouldReceive('pid')->andReturn($pid);
    $worker->shouldReceive('isRunning')->andReturn($running);
    $worker->shouldReceive('getIncrementalOutput')->andReturn(...$stdoutChunks);
    $worker->shouldReceive('getIncrementalErrorOutput')->andReturn(...$stderrChunks);
    $worker->shouldReceive('clearOutput');
    $worker->shouldReceive('clearErrorOutput');

    return $worker;
}

beforeEach(function (): void {
    $this->buffer = new WorkerOutputBuffer;
});

it('collects complete stdout lines grouped by pid', function (): void {
    $workers = [
        bufferedOutputWorker(101, stdoutChunks: ["one\ntwo\n"]),
        bufferedOutputWorker(202, stdoutChunks: ["three\n"]),
    ];

    expect($this->buffer->collectOutput($workers))->toBe([
        101 => ['one', 'two'],
        202 => ['three'],
    ]);
});

it('holds a partial stdout line until it completes', function (): void {
    $worker = bufferedOutputWorker(101, stdoutChunks: ['partial', " done\n"]);

    expect($this->buffer->collectOutput([$worker]))->toBe([])
        ->and($this->buffer->collectOutput([$worker]))->toBe([101 => ['partial done']]);
});

it('collects stderr separately from stdout', function (): void {
    $worker = bufferedOutputWorker(101, stdoutChunks: ["out line\n"], stderrChunks: ["err line\n"]);

    expect($this->buffer->collectOutput([$worker]))->toBe([101 => ['out line']])
        ->and($this->buffer->collectErrorOutput([$worker]))->toBe([101 => ['err line']]);
});

it('keeps partial lines on the two streams from bleeding into each other', function (): void {
    $worker = bufferedOutputWorker(
        101,
        stdoutChunks: ['stdout-part', " a\n"],
        stderrChunks: ['stderr-part', " b\n"],
    );

    $this->buffer->collectOutput([$worker]);
    $this->buffer->collectErrorOutput([$worker]);

    expect($this->buffer->collectOutput([$worker]))->toBe([101 => ['stdout-part a']])
        ->and($this->buffer->collectErrorOutput([$worker]))->toBe([101 => ['stderr-part b']]);
});

it('skips dead workers and workers without a pid', function (): void {
    $workers = [
        bufferedOutputWorker(101, stdoutChunks: ["never\n"], running: false),
        bufferedOutputWorker(null, stdoutChunks: ["never\n"]),
    ];

    expect($this->buffer->collectOutput($workers))->toBe([])
        ->and($this->buffer->collectErrorOutput($workers))->toBe([]);
});

it('clears the retained process buffers after each read', function (): void {
    $worker = Mockery::mock(WorkerProcess::class);
    $worker->shouldReceive('pid')->andReturn(101);
    $worker->shouldReceive('isRunning')->andReturn(true);
    $worker->shouldReceive('getIncrementalOutput')->andReturn("out\n");
    $worker->shouldReceive('getIncrementalErrorOutput')->andReturn("err\n");
    $worker->shouldReceive('clearOutput')->once();
    $worker->shouldReceive('clearErrorOutput')->once();

    $this->buffer->collectOutput([$worker]);
    $this->buffer->collectErrorOutput([$worker]);
});

it('filters blank lines from both streams', function (): void {
    $worker = bufferedOutputWorker(101, stdoutChunks: ["\n  \n"], stderrChunks: ["\n\n"]);

    expect($this->buffer->collectOutput([$worker]))->toBe([])
        ->and($this->buffer->collectErrorOutput([$worker]))->toBe([]);
});

it('forgets every partial remainder when all buffers are cleared', function (): void {
    $worker = bufferedOutputWorker(
        101,
        stdoutChunks: ['stale-out', "fresh out\n"],
        stderrChunks: ['stale-err', "fresh err\n"],
    );

    $this->buffer->collectOutput([$worker]);
    $this->buffer->collectErrorOutput([$worker]);

    $this->buffer->clearAllBuffers();

    expect($this->buffer->collectOutput([$worker]))->toBe([101 => ['fresh out']])
        ->and($this->buffer->collectErrorOutput([$worker]))->toBe([101 => ['fresh err']]);
});

it('forgets partial remainders for both streams when a pid is cleared', function (): void {
    $worker = bufferedOutputWorker(
        101,
        stdoutChunks: ['stale-out', "fresh out\n"],
        stderrChunks: ['stale-err', "fresh err\n"],
    );

    $this->buffer->collectOutput([$worker]);
    $this->buffer->collectErrorOutput([$worker]);

    $this->buffer->clearBuffer(101);

    expect($this->buffer->collectOutput([$worker]))->toBe([101 => ['fresh out']])
        ->and($this->buffer->collectErrorOutput([$worker]))->toBe([101 => ['fresh err']]);
});
