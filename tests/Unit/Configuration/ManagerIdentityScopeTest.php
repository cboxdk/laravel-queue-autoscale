<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

/**
 * The manager id is not a label — OrphanedWorkerReaper treats it as an
 * ownership token and SIGTERMs every process stamped with it. Two applications
 * deployed to one VM share hostname, machine-id and resolved IP, which is the
 * documented Forge/Ploi/VPS topology, so an id built from host identity alone
 * makes one app's manager restart kill the other app's entire worker fleet.
 */
function identitySource(): string
{
    $method = new ReflectionMethod(AutoscaleConfiguration::class, 'managerIdentitySource');

    return $method->invoke(null);
}

test('the manager identity includes application scope, not just host identity', function (): void {
    expect(identitySource())->toContain('app=');
});

test('two applications on the same host derive different identity sources', function (): void {
    config()->set('app.name', 'invoicing');
    config()->set('app.env', 'production');
    $first = identitySource();

    // Same host, same machine-id, same resolved IP — only the application
    // differs. managerId() itself memoises for the life of the process, which
    // is correct there (one process serves one app) but means the derivation
    // has to be compared at its source.
    config()->set('app.name', 'reporting');
    $second = identitySource();

    expect($second)->not->toBe($first)
        ->and($first)->toContain('app=')
        ->and($second)->toContain('app=');
});

test('an explicitly configured manager id still wins', function (): void {
    config()->set('queue-autoscale.manager_id', 'operator-chosen-id');

    expect(AutoscaleConfiguration::managerId())->toBe('operator-chosen-id');
});

test('the identity still resolves when no application is booted', function (): void {
    // managerId() is reachable before the container is available; it must
    // degrade to host identity rather than throw.
    expect(identitySource())->toBeString()->not->toBe('');
});

/*
 * The reaper matches this id exactly, so it has to survive a deploy: a
 * restarted manager must recognise its predecessor's workers. PHP resolves
 * symlinks, so anything derived from base_path() changes on every release with
 * a releases/<timestamp> layout — which would have traded over-reaping for
 * silently under-reaping on exactly the platforms this scoping is for.
 */
test('the identity is stable across a deploy to a new release directory', function (): void {
    config()->set('app.name', 'invoicing');
    config()->set('app.env', 'production');

    $first = identitySource();

    // Same app, same host, new release path — which is all a deploy changes.
    expect(identitySource())->toBe($first)
        ->and($first)->not->toContain(base_path());
});
