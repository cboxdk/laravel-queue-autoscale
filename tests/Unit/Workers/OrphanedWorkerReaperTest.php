<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\OrphanedWorkerReaper;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Build a throwaway procfs lookalike: one directory per "process", each with
 * an environ file in the real NUL-separated format.
 */
function fakeProcfs(array $processes): string
{
    $root = sys_get_temp_dir().'/lqas-reaper-'.bin2hex(random_bytes(6));
    mkdir($root, 0755, true);

    foreach ($processes as $pid => $environ) {
        mkdir("{$root}/{$pid}", 0755, true);

        if ($environ !== null) {
            file_put_contents("{$root}/{$pid}/environ", $environ);
        }
    }

    return $root;
}

function removeFakeProcfs(string $root): void
{
    foreach (glob("{$root}/*/environ") ?: [] as $file) {
        unlink($file);
    }
    foreach (glob("{$root}/*", GLOB_ONLYDIR) ?: [] as $dir) {
        rmdir($dir);
    }
    rmdir($root);
}

/**
 * A real throwaway process to signal, so posix_kill() has something to hit.
 */
function disposableProcess(): Process
{
    $process = new Process([PHP_BINARY, '-r', 'sleep(30);']);
    $process->start();

    return $process;
}

function waitUntilStopped(Process $process, float $timeoutSeconds = 5.0): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        if (! $process->isRunning()) {
            return true;
        }

        usleep(50_000);
    }

    return false;
}

function workerEnviron(string $managerId): string
{
    return "PATH=/usr/bin\0LARAVEL_AUTOSCALE_WORKER=true\0AUTOSCALE_MANAGER_ID={$managerId}\0MALFORMED-NO-EQUALS\0";
}

it('terminates a marker-stamped worker from the same manager generation', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    $process = disposableProcess();
    $pid = $process->getPid();

    $procfs = fakeProcfs([$pid => workerEnviron('mgr-test')]);

    try {
        $reaper = new OrphanedWorkerReaper($procfs);

        expect($reaper->reap('mgr-test'))->toBe(1)
            ->and(waitUntilStopped($process))->toBeTrue();
    } finally {
        $process->stop(0);
        removeFakeProcfs($procfs);
    }
});

it('leaves workers stamped by a different manager alone', function (): void {
    $process = disposableProcess();
    $pid = $process->getPid();

    $procfs = fakeProcfs([$pid => workerEnviron('another-manager')]);

    try {
        $reaper = new OrphanedWorkerReaper($procfs);

        expect($reaper->reap('mgr-test'))->toBe(0)
            ->and($process->isRunning())->toBeTrue();
    } finally {
        $process->stop(0);
        removeFakeProcfs($procfs);
    }
});

it('ignores processes without the worker marker', function (): void {
    $process = disposableProcess();
    $pid = $process->getPid();

    $procfs = fakeProcfs([$pid => "PATH=/usr/bin\0AUTOSCALE_MANAGER_ID=mgr-test\0"]);

    try {
        $reaper = new OrphanedWorkerReaper($procfs);

        expect($reaper->reap('mgr-test'))->toBe(0)
            ->and($process->isRunning())->toBeTrue();
    } finally {
        $process->stop(0);
        removeFakeProcfs($procfs);
    }
});

it('never signals its own process', function (): void {
    $previousHandler = pcntl_signal_get_handler(SIGTERM);
    pcntl_signal(SIGTERM, SIG_IGN);

    $procfs = fakeProcfs([getmypid() => workerEnviron('mgr-test')]);

    try {
        $reaper = new OrphanedWorkerReaper($procfs);

        expect($reaper->reap('mgr-test'))->toBe(0);
    } finally {
        pcntl_signal(SIGTERM, $previousHandler);
        removeFakeProcfs($procfs);
    }
});

it('skips entries whose environ is missing or unreadable', function (): void {
    $procfs = fakeProcfs([424242 => null]);

    try {
        $reaper = new OrphanedWorkerReaper($procfs);

        expect($reaper->reap('mgr-test'))->toBe(0);
    } finally {
        removeFakeProcfs($procfs);
    }
});

it('does not count a matching pid that is already gone', function (): void {
    $process = disposableProcess();
    $pid = $process->getPid();
    $process->stop(0);
    waitUntilStopped($process);

    $procfs = fakeProcfs([$pid => workerEnviron('mgr-test')]);

    try {
        $reaper = new OrphanedWorkerReaper($procfs);

        expect($reaper->reap('mgr-test'))->toBe(0);
    } finally {
        removeFakeProcfs($procfs);
    }
});

it('reports zero and logs when procfs is not available', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('debug')->once();

    $reaper = new OrphanedWorkerReaper(sys_get_temp_dir().'/lqas-no-procfs-'.bin2hex(random_bytes(6)));

    expect($reaper->reap('mgr-test'))->toBe(0);
});
