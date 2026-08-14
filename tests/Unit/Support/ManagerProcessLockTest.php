<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\ManagerProcessLock;

it('acquires a lock successfully', function () {
    $lock = new ManagerProcessLock;
    $held = $lock->acquire();

    expect($held->metadata())->toHaveKeys(['pid', 'manager_id', 'host', 'acquired_at', 'cluster_enabled']);

    $held->release();
});

it('prevents a second lock on the same host', function () {
    $lock1 = new ManagerProcessLock;
    $held1 = $lock1->acquire();

    $lock2 = new ManagerProcessLock;

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

    $lock = new ManagerProcessLock;
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

    $lock = new ManagerProcessLock;
    $held = $lock->acquire();

    $lockDir = storage_path('framework/queue-autoscale');
    $files = glob($lockDir.'/manager-*.lock');

    // In single-host mode, the lock filename should NOT contain the host fingerprint
    $hostFingerprint = substr(sha1(gethostname() ?: 'unknown-host'), 0, 12);
    $matchingFiles = array_filter($files, fn ($f) => str_contains(basename($f), $hostFingerprint));

    expect($matchingFiles)->toBeEmpty();

    $held->release();
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

it('refuses to signal a pid that is not an autoscale manager', function () {
    // The lock file sits under storage/, which the web user can usually write.
    // Trusting the PID inside it let anything local have an operator SIGTERM
    // an arbitrary process — as root, if the manager runs as root.
    $lock = new ManagerProcessLock;
    $method = new ReflectionMethod($lock, 'looksLikeAutoscaleManager');

    // PID 1 is init: real, running, and emphatically not our manager. With no
    // manager_id recorded there is nothing to fall back on either.
    expect($method->invoke($lock, 1, []))->toBeFalse();
});

it('creates its lock directory without world write', function () {
    $lock = new ManagerProcessLock;
    $held = $lock->acquire();

    $directory = storage_path('framework/queue-autoscale');
    $mode = fileperms($directory) & 0777;

    expect($mode & 0002)->toBe(0, sprintf('lock directory is world-writable (%04o)', $mode));

    $held->release();
});
