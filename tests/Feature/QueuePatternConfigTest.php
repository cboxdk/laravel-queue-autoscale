<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ConnectionLimitedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfigResolver;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

test('a queue name containing dots reaches its own configuration', function (): void {
    // config('queue-autoscale.queues.tenant.42') splits on the dots and looks
    // for a nested 'tenant' key, so this entry used to be unreachable: the
    // queue silently ran on defaults with no cap and nothing reported it.
    config()->set('queue-autoscale.queues', [
        'tenant.42' => ['workers' => ['max' => 5]],
    ]);

    expect(QueueConfiguration::fromConfig('redis', 'tenant.42')->workers->max)->toBe(5);
});

test('a glob governs every queue that matches it', function (): void {
    config()->set('queue-autoscale.queues', [
        'tenant.*' => ['workers' => ['min' => 0, 'max' => 5]],
    ]);

    foreach (['tenant.42', 'tenant.99', 'tenant.acme-university'] as $queue) {
        expect(QueueConfiguration::fromConfig('redis', $queue)->workers->max)->toBe(5);
    }
});

test('a queue nothing matches still gets the defaults', function (): void {
    config()->set('queue-autoscale.queues', [
        'tenant.*' => ['workers' => ['max' => 5]],
    ]);

    $default = QueueConfiguration::fromConfig('redis', 'unrelated');

    expect($default->workers->max)->not->toBe(5);
});

test('an exact name wins over a glob that also matches', function (): void {
    // One customer with a higher negotiated limit must not require rewriting
    // the rule that covers everyone else.
    config()->set('queue-autoscale.queues', [
        'tenant.*' => ['workers' => ['max' => 5]],
        'tenant.vip' => ['workers' => ['max' => 20]],
    ]);

    expect(QueueConfiguration::fromConfig('redis', 'tenant.vip')->workers->max)->toBe(20)
        ->and(QueueConfiguration::fromConfig('redis', 'tenant.42')->workers->max)->toBe(5);
});

test('overlapping globs resolve in declaration order', function (): void {
    // First listed wins, so precedence is visible in the file rather than
    // inferred from an invisible specificity ranking.
    config()->set('queue-autoscale.queues', [
        'tenant.eu-*' => ['workers' => ['max' => 2]],
        'tenant.*' => ['workers' => ['max' => 5]],
    ]);

    expect(QueueConfiguration::fromConfig('redis', 'tenant.eu-1')->workers->max)->toBe(2)
        ->and(QueueConfiguration::fromConfig('redis', 'tenant.us-1')->workers->max)->toBe(5);
});

test('a glob is never treated as a queue of its own', function (): void {
    // Left in the configured-queue list, the manager would discover a queue
    // literally named 'tenant.*' and hold workers on something that cannot
    // receive a job.
    config()->set('queue-autoscale.queues', [
        'tenant.*' => ['workers' => ['max' => 5]],
        'reports' => ['workers' => ['max' => 3]],
    ]);

    $queues = array_column(AutoscaleConfiguration::configuredQueues(), 'queue');

    expect($queues)->toContain('reports')
        ->and($queues)->not->toContain('tenant.*');
});

test('per-queue resource overrides resolve through globs too', function (): void {
    config()->set('queue-autoscale.queues', [
        'tenant.*' => ['resources' => ['memory_mb' => 256]],
    ]);

    expect(AutoscaleConfiguration::queueResources('tenant.7'))->toBe(['memory_mb' => 256]);
});

test('a profile can be combined with overrides', function (): void {
    config()->set('queue-autoscale.queues', [
        'tenant.*' => [
            'profile' => ConnectionLimitedProfile::class,
            'workers' => ['max' => 5],
        ],
    ]);

    $config = QueueConfiguration::fromConfig('redis', 'tenant.42');

    expect($config->workers->max)->toBe(5)
        ->and($config->workers->min)->toBe(0)
        ->and($config->workers->scalable)->toBeTrue()
        // Untouched fields still come from the profile. Read from the profile
        // rather than restated, so this stays a test of composition and does
        // not have to be edited whenever the profile is tuned.
        ->and($config->fuse->enabled)->toBeTrue()
        ->and($config->sla->targetSeconds)->toBe((new ConnectionLimitedProfile)->resolve()['sla']['target_seconds']);
});

test('a bare profile class still works', function (): void {
    config()->set('queue-autoscale.queues', [
        'urgent' => CriticalProfile::class,
    ]);

    $config = QueueConfiguration::fromConfig('redis', 'urgent');

    expect($config->sla->targetSeconds)->toBe((new CriticalProfile)->resolve()['sla']['target_seconds']);
});

test('a profile key that is not a profile is rejected loudly', function (): void {
    // Silently ignoring it would hand the queue the defaults, which for a
    // connection-limited queue means no cap at all.
    config()->set('queue-autoscale.queues', [
        'tenant.*' => ['profile' => 'App\\Nonsense', 'workers' => ['max' => 5]],
    ]);

    expect(fn () => QueueConfiguration::fromConfig('redis', 'tenant.1'))
        ->toThrow(InvalidConfigurationException::class, 'App\\Nonsense');
});

test('the resolver distinguishes globs from names', function (): void {
    expect(QueueConfigResolver::isPattern('tenant.*'))->toBeTrue()
        ->and(QueueConfigResolver::isPattern('tenant.?'))->toBeTrue()
        ->and(QueueConfigResolver::isPattern('tenant.[0-9]'))->toBeTrue()
        ->and(QueueConfigResolver::isPattern('tenant.42'))->toBeFalse()
        ->and(QueueConfigResolver::isPattern('reports'))->toBeFalse();
});

test('the connection-limited profile caps parallelism and idles at zero', function (): void {
    $profile = (new ConnectionLimitedProfile)->resolve();

    // The two properties the whole design rests on: a hard ceiling, and no
    // cost for a customer who is not currently being scraped.
    expect($profile['workers']['min'])->toBe(0)
        ->and($profile['workers']['scalable'])->toBeTrue()
        ->and($profile['fuse']['enabled'])->toBeTrue();
});
