<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\SpawnIdentity;

/**
 * The pending spawn record lives in the Redis every host in the cluster
 * shares, for five minutes, and PIDs are per host and recycled. A bare PID
 * therefore collided across hosts: one host's worker read another's payload
 * and recorded its spawn latency against the wrong queue, while the second
 * worker found its record already consumed.
 */
test('two managers on different hosts do not collide on the same pid', function (): void {
    config()->set('queue-autoscale.manager_id', 'web-01');
    $onA = SpawnIdentity::forPid(12345);

    config()->set('queue-autoscale.manager_id', 'web-02');
    $onB = SpawnIdentity::forPid(12345);

    expect($onA)->not->toBe($onB);
});

test('the worker derives the identity the manager stamped', function (): void {
    // The spawner injects AUTOSCALE_MANAGER_ID into the child's environment,
    // which is how the two halves agree without sharing memory.
    config()->set('queue-autoscale.manager_id', 'web-01');
    putenv('AUTOSCALE_MANAGER_ID=web-01');

    expect(SpawnIdentity::forCurrentProcess())->toBe('web-01:'.getmypid());

    putenv('AUTOSCALE_MANAGER_ID');
});

test('a worker started by hand still resolves an identity', function (): void {
    // No injected env var: it falls back to the configured manager id and
    // simply finds no pending record, which is what that path already did.
    putenv('AUTOSCALE_MANAGER_ID');
    config()->set('queue-autoscale.manager_id', 'standalone');

    expect(SpawnIdentity::forCurrentProcess())->toBe('standalone:'.getmypid())
        ->and(SpawnIdentity::forCurrentProcess())->toStartWith(AutoscaleConfiguration::managerId());
});
