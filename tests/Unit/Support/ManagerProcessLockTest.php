<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\ManagerProcessLock;
use Symfony\Component\Process\Process;

it('acquires a lock successfully', function () {
    $lock = new ManagerProcessLock();
    $held = $lock->acquire();

    expect($held->metadata())->toHaveKeys(['pid', 'manager_id', 'host', 'acquired_at', 'cluster_enabled']);

    $held->release();
});

it('prevents a second lock on the same host', function () {
    $lock1 = new ManagerProcessLock();
    $held1 = $lock1->acquire();

    $lock2 = new ManagerProcessLock();

    try {
        $lock2->acquire();
        $this->fail('Expected RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Another queue:autoscale manager is already running');
    } finally {
        $held1->release();
    }
});

it('uses host-scoped lock path in cluster mode', function () {
    config()->set('queue-autoscale.cluster.enabled', true);

    $lock = new ManagerProcessLock();
    $held = $lock->acquire();

    $lockDir = storage_path('framework/queue-autoscale');
    $files = glob($lockDir.'/manager-*.lock');

    // In cluster mode, the lock filename should contain the host fingerprint
    $hostFingerprint = substr(sha1(gethostname() ?: 'unknown-host'), 0, 12);
    $matchingFiles = array_filter($files, fn ($f) => str_contains(basename($f), $hostFingerprint));

    expect($matchingFiles)->not->toBeEmpty();

    $held->release();
});

it('uses app-only lock path in single-host mode', function () {
    config()->set('queue-autoscale.cluster.enabled', false);

    $lock = new ManagerProcessLock();
    $held = $lock->acquire();

    $lockDir = storage_path('framework/queue-autoscale');
    $files = glob($lockDir.'/manager-*.lock');

    // In single-host mode, the lock filename should NOT contain the host fingerprint
    $hostFingerprint = substr(sha1(gethostname() ?: 'unknown-host'), 0, 12);
    $matchingFiles = array_filter($files, fn ($f) => str_contains(basename($f), $hostFingerprint));

    expect($matchingFiles)->toBeEmpty();

    $held->release();
});

it('releases the lock immediately after the manager exits even while spawned children survive', function () {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required to spawn an inheriting child.');
    }

    $lock1 = new ManagerProcessLock();
    $held1 = $lock1->acquire();

    // A child spawned while the lock is held must NOT inherit the lock fd
    // (O_CLOEXEC). Otherwise its inherited copy keeps the flock alive after the
    // parent releases, and the replacement manager cannot start.
    $child = new Process([PHP_BINARY, '-r', 'sleep(30);']);
    $child->start();

    try {
        expect($child->isRunning())->toBeTrue();

        // Manager exits: release and close the parent handle while the child lives on.
        $held1->release();

        $lock2 = new ManagerProcessLock();
        $held2 = $lock2->acquire();

        expect($held2->metadata())->toHaveKey('pid');

        $held2->release();
    } finally {
        $child->stop(0);

        if ($child->isRunning()) {
            $child->signal(defined('SIGKILL') ? SIGKILL : 9);
        }
    }
});

afterEach(function () {
    // Clean up any lock files created during tests
    $lockDir = storage_path('framework/queue-autoscale');
    $files = glob($lockDir.'/manager-*.lock');

    if (is_array($files)) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
});
