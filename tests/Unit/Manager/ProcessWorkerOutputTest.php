<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use Cbox\LaravelQueueAutoscale\Workers\WorkerPool;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Facades\Log;

function streamingWorker(int $pid, string $stdout, string $stderr): WorkerProcess
{
    $worker = Mockery::mock(WorkerProcess::class);
    $worker->shouldReceive('pid')->andReturn($pid);
    $worker->shouldReceive('isRunning')->andReturn(true);
    $worker->shouldReceive('getIncrementalOutput')->once()->andReturn($stdout);
    $worker->shouldReceive('getIncrementalErrorOutput')->once()->andReturn($stderr);
    $worker->shouldReceive('clearOutput')->once();
    $worker->shouldReceive('clearErrorOutput')->once();

    return $worker;
}

function lineRecordingRenderer(): OutputRendererContract
{
    return new class implements OutputRendererContract
    {
        /** @var array<int, string> */
        public array $lines = [];

        public function initialize(): void {}

        public function render(OutputData $data): void {}

        public function handleWorkerOutput(int $pid, string $line): void
        {
            $this->lines[] = "{$pid}:{$line}";
        }

        public function shutdown(): void {}
    };
}

function invokeProcessWorkerOutput(AutoscaleManager $manager, WorkerProcess $worker): void
{
    $poolProperty = new ReflectionProperty($manager, 'pool');
    /** @var WorkerPool $pool */
    $pool = $poolProperty->getValue($manager);
    $pool->add($worker);

    (new ReflectionMethod($manager, 'processWorkerOutput'))->invoke($manager);
}

it('sends worker stdout to the renderer and stderr to the log channel', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->once()->with('[worker 321] Something broke');
    Log::shouldReceive('info')->once()->with('[worker 321] Stack frame #0');

    $manager = app(AutoscaleManager::class);
    $renderer = lineRecordingRenderer();
    $manager->setRenderer($renderer);

    invokeProcessWorkerOutput($manager, streamingWorker(321, "job ok\n", "Something broke\nStack frame #0\n"));

    expect($renderer->lines)->toBe(['321:job ok']);
});

it('drains both streams even when no renderer is attached', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->once()->with('[worker 654] boom');

    $manager = app(AutoscaleManager::class);

    invokeProcessWorkerOutput($manager, streamingWorker(654, "progress line\n", "boom\n"));
});

it('logs nothing when workers wrote no stderr', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->never();

    $manager = app(AutoscaleManager::class);
    $manager->setRenderer(lineRecordingRenderer());

    invokeProcessWorkerOutput($manager, streamingWorker(987, "quiet worker\n", ''));
});
