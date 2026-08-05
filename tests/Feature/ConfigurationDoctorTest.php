<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ConnectionLimitedProfile;
use Cbox\LaravelQueueAutoscale\Diagnostics\ConfigurationDoctor;
use Cbox\LaravelQueueAutoscale\Diagnostics\Finding;
use Cbox\LaravelQueueAutoscale\Diagnostics\Severity;

/**
 * @param  list<string>  $queues
 * @return list<Finding>
 */
function diagnose(array $queues): array
{
    return (new ConfigurationDoctor)->diagnose($queues);
}

/**
 * @param  list<Finding>  $findings
 */
function findingTitled(array $findings, string $fragment): ?Finding
{
    foreach ($findings as $finding) {
        if (str_contains($finding->title, $fragment)) {
            return $finding;
        }
    }

    return null;
}

beforeEach(function (): void {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.excluded', []);
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.limits.max_total_workers', 100);
});

test('a clean configuration reports nothing', function (): void {
    config()->set('queue-autoscale.queues', [
        'reports' => ['workers' => ['max' => 3]],
    ]);

    expect(diagnose(['reports']))->toBe([]);
});

test('a pattern matching no queue is reported', function (): void {
    // The failure this exists for: a misspelled pattern does not error, it
    // simply never applies, and every queue it was meant to govern silently
    // runs on defaults.
    config()->set('queue-autoscale.queues', [
        'scrape-tenat-*' => ['workers' => ['max' => 5]],
    ]);

    $finding = findingTitled(diagnose(['scrape-tenant-42', 'scrape-tenant-99']), 'scrape-tenat-*');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->remedy)->toContain('typo');
});

test('a configured queue that has never received a job is reported', function (): void {
    config()->set('queue-autoscale.queues', [
        'raports' => ['workers' => ['max' => 3]],
    ]);

    $finding = findingTitled(diagnose(['reports']), 'raports');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning);
});

test('a glob is reported with every queue it actually caught', function (): void {
    // Intent cannot be inferred, so the doctor does not guess whether a match
    // was wanted. It lists what the rule caught, which is what makes an
    // unrelated queue visible.
    config()->set('queue-autoscale.queues', [
        'tenant-*' => ['workers' => ['max' => 5]],
    ]);

    $finding = findingTitled(
        diagnose(['tenant-42', 'tenant-99', 'tenant-admin-notifications']),
        "'tenant-*' governs",
    );

    expect($finding)->not->toBeNull()
        ->and($finding->title)->toContain('3 queues')
        ->and($finding->detail)->toContain('tenant-admin-notifications');
});

test('the reported queues reflect which rule actually wins', function (): void {
    // An exact name beats a glob, so the glob must not claim it in the report
    // either — otherwise the output disagrees with what the autoscaler does.
    config()->set('queue-autoscale.queues', [
        'tenant-*' => ['workers' => ['max' => 5]],
        'tenant-vip' => ['workers' => ['max' => 20]],
    ]);

    $finding = findingTitled(diagnose(['tenant-42', 'tenant-99', 'tenant-vip']), "'tenant-*' governs");

    expect($finding->title)->toContain('2 queues')
        ->and($finding->detail)->not->toContain('tenant-vip');
});

test('an excluded queue is not counted against a glob', function (): void {
    config()->set('queue-autoscale.queues', [
        'tenant-*' => ['workers' => ['max' => 5]],
    ]);
    config()->set('queue-autoscale.excluded', ['tenant-admin-*']);

    $finding = findingTitled(diagnose(['tenant-42', 'tenant-99', 'tenant-admin-notifications']), "'tenant-*' governs");

    expect($finding->detail)->not->toContain('tenant-admin-notifications');
});

test('dotted queue names are reported when an sqs connection exists', function (): void {
    // Legal on Redis, rejected by AWS at dispatch. The config file that chose
    // the name is a long way from where that error surfaces.
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-autoscale.queues', [
        'tenant.42' => ['workers' => ['max' => 5]],
    ]);

    $finding = findingTitled(diagnose(['tenant.42']), 'SQS rejects');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toContain('tenant.42');
});

test('a .fifo suffix is not mistaken for an illegal name', function (): void {
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-autoscale.queues', [
        'orders.fifo' => ['workers' => ['max' => 5]],
    ]);

    expect(findingTitled(diagnose(['orders.fifo']), 'SQS rejects'))->toBeNull();
});

test('dotted names are not reported when no sqs connection is configured', function (): void {
    config()->set('queue.connections', ['redis' => ['driver' => 'redis']]);
    config()->set('queue-autoscale.queues', [
        'tenant.42' => ['workers' => ['max' => 5]],
    ]);

    expect(findingTitled(diagnose(['tenant.42']), 'SQS rejects'))->toBeNull();
});

test('caps are flagged as per-host while cluster mode is off', function (): void {
    // The promise that quietly changes meaning the day a second host appears.
    config()->set('queue-autoscale.cluster.enabled', false);
    config()->set('queue-autoscale.queues', [
        'scrape-tenant-*' => ['workers' => ['max' => 5]],
    ]);

    $finding = findingTitled(diagnose(['scrape-tenant-42']), 'per-host limit');

    expect($finding)->not->toBeNull()
        ->and($finding->remedy)->toContain('cluster');
});

test('caps are not flagged when cluster mode is on', function (): void {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.queues', [
        'scrape-tenant-*' => ['workers' => ['max' => 5]],
    ]);

    expect(findingTitled(diagnose(['scrape-tenant-42']), 'per-host limit'))->toBeNull();
});

test('pattern matching without a total ceiling is reported', function (): void {
    // Patterns imply generated queue names, so nothing in config bounds how
    // many queues there will be.
    config()->set('queue-autoscale.limits.max_total_workers', null);
    config()->set('queue-autoscale.queues', [
        'scrape-tenant-*' => ['workers' => ['max' => 5]],
    ]);

    $finding = findingTitled(diagnose(['scrape-tenant-42']), 'max_total_workers');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning);
});

test('a missing ceiling is not reported without pattern matching', function (): void {
    config()->set('queue-autoscale.limits.max_total_workers', null);
    config()->set('queue-autoscale.queues', [
        'reports' => ['workers' => ['max' => 5]],
    ]);

    expect(findingTitled(diagnose(['reports']), 'max_total_workers'))->toBeNull();
});

test('a fifo queue allowing parallelism is explained', function (): void {
    // Not an error — it is correct when the jobs span enough message groups.
    // It is reported because the failure mode when they do not is silent:
    // workers that poll and never receive anything.
    config()->set('queue-autoscale.queues', [
        'orders.fifo' => ['profile' => ConnectionLimitedProfile::class, 'workers' => ['max' => 5]],
    ]);

    $finding = findingTitled(diagnose(['orders.fifo']), 'FIFO queue allowing 5 workers');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Notice)
        ->and($finding->detail)->toContain('message group');
});

test('a fifo queue pinned to one worker is not flagged', function (): void {
    config()->set('queue-autoscale.queues', [
        'orders.fifo' => ['workers' => ['min' => 1, 'max' => 1, 'scalable' => false]],
    ]);

    expect(findingTitled(diagnose(['orders.fifo']), 'FIFO queue allowing'))->toBeNull();
});

test('a leftover v3 workers block is reported', function (): void {
    // The upgrade mistake that produces no error: the file still parses, the
    // autoscaler still runs, and the settings simply stopped applying.
    config()->set('queue-autoscale.workers', ['tries' => 3, 'sleep_seconds' => 3]);

    $finding = findingTitled(diagnose(['reports']), 'top-level queue-autoscale.workers block');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->remedy)->toContain('manager.shutdown_grace_seconds');
});

test('a v3 timeout_seconds carried over without max_time_seconds is reported', function (): void {
    // The key survived the upgrade but changed meaning: it was the worker
    // process lifetime, it is now the per-job limit.
    config()->set('queue-autoscale.queues', [
        'reports' => ['workers' => ['timeout_seconds' => 3600]],
    ]);

    $finding = findingTitled(diagnose(['reports']), 'without workers.max_time_seconds');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toContain('reports');
});

test('both timeout keys set together is not reported', function (): void {
    config()->set('queue-autoscale.queues', [
        'reports' => ['workers' => ['max_time_seconds' => 3600, 'timeout_seconds' => 900]],
    ]);

    expect(findingTitled(diagnose(['reports']), 'without workers.max_time_seconds'))->toBeNull();
});
