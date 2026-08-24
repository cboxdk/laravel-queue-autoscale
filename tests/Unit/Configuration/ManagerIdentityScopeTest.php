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
 * restarted manager must recognise its predecessor's workers. Anything derived
 * from base_path() fails that — PHP resolves symlinks, so on a
 * releases/<timestamp> layout the path changes every release, which would have
 * traded over-reaping for silently under-reaping.
 *
 * Asserted on the derivation rather than by calling twice, which any
 * deterministic implementation passes including the one this replaced.
 */
test('the application scope is derived from config, not from the filesystem', function (): void {
    config()->set('app.name', 'Invoicing Service');
    config()->set('app.env', 'production');

    expect(identitySource())->toContain('app=invoicing-service-production');
});

test('the application scope moves with the app, not with the release path', function (): void {
    config()->set('app.name', 'invoicing');
    config()->set('app.env', 'staging');
    $staging = identitySource();

    config()->set('app.env', 'production');

    expect(identitySource())->not->toBe($staging)
        ->and($staging)->not->toContain(sha1(base_path()))
        ->and($staging)->not->toContain(substr(sha1(base_path()), 0, 12));
});

/*
 * manager.restart_scope is documented as controlling the restart command's
 * cache key. An operator setting it to a shared value so two apps restart
 * together must not thereby hand them the same worker-ownership token — that
 * is the exact collision this scope exists to prevent.
 */
test('the restart scope override does not change the manager identity', function (): void {
    config()->set('app.name', 'invoicing');
    config()->set('app.env', 'production');

    $withoutOverride = identitySource();

    config()->set('queue-autoscale.manager.restart_scope', 'shared-deploy-group');

    expect(identitySource())->toBe($withoutOverride);
});

test('two apps sharing a restart scope still derive different identities', function (): void {
    config()->set('queue-autoscale.manager.restart_scope', 'shared-deploy-group');

    config()->set('app.name', 'invoicing');
    $first = identitySource();

    config()->set('app.name', 'reporting');

    expect(identitySource())->not->toBe($first);
});
