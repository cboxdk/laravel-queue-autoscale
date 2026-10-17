<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\ConnectionLimitedProfile;
use Cbox\LaravelQueueAutoscale\Diagnostics\ConfigurationDoctor;
use Cbox\LaravelQueueAutoscale\Diagnostics\Finding;
use Cbox\LaravelQueueAutoscale\Diagnostics\QueueDiscovery;
use Cbox\LaravelQueueAutoscale\Diagnostics\Severity;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Tests\Fixtures\CustomQueueDriver;
use Illuminate\Queue\SqsQueue;

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

test('dotted queue names are reported when a custom driver built on SQS exists', function (): void {
    config()->set('queue.connections', []);
    CustomQueueDriver::register('tenant-sqs', SqsQueue::class, driver: 'tenant-sqs');
    config()->set('queue-autoscale.queues', [
        'tenant.42' => ['workers' => ['max' => 5]],
    ]);

    expect(findingTitled(diagnose(['tenant.42']), 'SQS rejects'))->not->toBeNull();
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

/**
 * The leader renews its lease as part of its evaluation cycle, so a lease with
 * no headroom over that cycle turns an ordinary hiccup into a handover — and
 * every handover discards worker placement, the anti-flapping window and the
 * fair-share rotation's position. Measured: with leadership moving every eleven
 * cycles, two of six contending queues went back to never being served at all.
 */
test('it warns when the leader lease leaves no room for a slow cycle', function (): void {
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 10);
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 5);

    $finding = findingTitled(diagnose(['default']), 'leader lease');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->detail)->toContain('10s')
        ->and($finding->detail)->toContain('5s');
});

test('the shipped defaults leave enough room', function (): void {
    // 15s against a 5s interval is exactly the three intervals the check wants.
    // If the defaults ever tripped their own diagnostic, the diagnostic would
    // be the thing that is wrong.
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 15);
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 5);

    expect(findingTitled(diagnose(['default']), 'leader lease'))->toBeNull();
});

test('a generous lease is not mentioned', function (): void {
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 60);
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 5);

    expect(findingTitled(diagnose(['default']), 'leader lease'))->toBeNull();
});

test('a short lease on a single host is not a finding', function (): void {
    // There is no leadership to lose without cluster mode, so the check would
    // be noise — and noise in a diagnostic is what teaches people to skip it.
    config()->set('queue-autoscale.cluster.enabled', false);
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 5);
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 5);

    expect(findingTitled(diagnose(['default']), 'leader lease'))->toBeNull();
});

test('a long evaluation interval outgrows a default lease', function (): void {
    // The pairing matters, not either number alone: raising the interval on a
    // busy cluster silently removes the headroom the default lease had.
    config()->set('queue-autoscale.cluster.leader_lease_seconds', 15);
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 10);

    expect(findingTitled(diagnose(['default']), 'leader lease'))->not->toBeNull();
});

test('a pickup sample cap at or below an SLA target is reported', function (): void {
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', 3600);
    config()->set('queue-autoscale.queues', [
        'reports' => ['sla' => ['target_seconds' => 3600], 'workers' => ['timeout_seconds' => 600]],
        'emails' => ['sla' => ['target_seconds' => 30]],
        'broken' => ['sla' => ['target_seconds' => 7200], 'workers' => ['timeout_seconds' => 900, 'max_time_seconds' => 100]],
    ]);

    // 'broken' cannot load (its timeout outlives the worker), so it
    // is skipped rather than aborting the doctor.
    $finding = findingTitled(diagnose(['reports', 'emails', 'broken']), 'pickup sample cap');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->detail)->toContain("'reports' (3600s)")
        ->and($finding->detail)->not->toContain("'emails'")
        ->and($finding->detail)->not->toContain("'broken'");
});

test('a pickup sample cap above every SLA target, or no cap, is not reported', function (?int $cap): void {
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', $cap);
    config()->set('queue-autoscale.queues', ['reports' => ['sla' => ['target_seconds' => 3599]]]);

    expect(findingTitled(diagnose(['reports']), 'pickup sample cap'))->toBeNull();
})->with(['cap above the target' => [3600], 'no cap' => [null]]);

test('every queue whose SLA the pickup cap hides is named, with the cap and the fix', function (): void {
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', '600');
    config()->set('queue-autoscale.queues', [
        'reports' => ['sla' => ['target_seconds' => 900], 'workers' => ['timeout_seconds' => 600]],
        'exports' => ['sla' => ['target_seconds' => 600]],
        'emails' => ['sla' => ['target_seconds' => 30]],
    ]);

    $finding = findingTitled(diagnose(['reports', 'exports', 'emails']), 'pickup sample cap');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toContain('max_sample_seconds is 600s')
        ->and($finding->detail)->toContain("'reports' (900s), 'exports' (600s)")
        ->and($finding->detail)->not->toContain("'emails'")
        ->and($finding->remedy)->toContain('set it to null');
});

test('a pickup cap of zero is no cap and is not reported', function (): void {
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', 0);
    config()->set('queue-autoscale.queues', ['reports' => ['sla' => ['target_seconds' => 3600]]]);

    expect(findingTitled(diagnose(['reports']), 'pickup sample cap'))->toBeNull();
});

test('the pickup cap is checked against the default SLA for queues with no rule of their own', function (): void {
    // The shipped balanced profile targets 30s.
    config()->set('queue-autoscale.pickup_time.max_sample_seconds', 10);

    $finding = findingTitled(diagnose(['default']), 'pickup sample cap');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toContain("'default' (30s)");
});

// --- SQS queues without pickup samples ---

function sqsDoctorSetup(bool $stamp, string $store, bool $cluster): void
{
    config()->set('queue.connections.sqs', ['driver' => 'sqs']);
    config()->set('queue-autoscale.queues', [
        'orders' => ['connection' => 'sqs'],
        'emails' => ['connection' => 'redis'],
    ]);
    config()->set('queue-autoscale.pickup_time.stamp_pushed_at', $stamp);
    config()->set('queue-autoscale.pickup_time.store', $store);
    config()->set('queue-autoscale.cluster.enabled', $cluster);
}

test('an SQS queue on a single host with the default pickup settings has no SLA signal', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);

    $finding = findingTitled(diagnose(['emails']), 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->detail)->toStartWith('orders: ')
        ->and($finding->detail)->not->toContain('emails')
        ->and($finding->detail)->toContain('not stamped with pushedAt')
        ->and($finding->detail)->toContain('null store')
        ->and($finding->remedy)->toContain('QUEUE_AUTOSCALE_STAMP_PUSHED_AT=true')
        ->and($finding->remedy)->toContain('QUEUE_AUTOSCALE_PICKUP_TIME_STORE=redis');
});

test('each missing piece of the SQS pickup setup is named on its own', function (bool $stamp, string $store, bool $cluster, ?string $named, ?string $notNamed): void {
    sqsDoctorSetup($stamp, $store, $cluster);

    $finding = findingTitled(diagnose([]), 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toContain($named)
        ->and($finding->detail)->not->toContain($notNamed);
})->with([
    'stamped, null store on a single host' => [true, 'auto', false, 'null store', 'pushedAt'],
    'stamped, store explicitly null' => [true, 'null', true, 'null store', 'pushedAt'],
    'not stamped, redis store' => [false, 'redis', false, 'pushedAt', 'null store'],
]);

test('SQS queues with pushedAt stamped and a shared store are not reported', function (string $store, bool $cluster): void {
    sqsDoctorSetup(stamp: true, store: $store, cluster: $cluster);

    expect(findingTitled(diagnose([]), 'SQS queues have no SLA signal'))->toBeNull();
})->with([
    'redis on a single host' => ['redis', false],
    'auto in cluster mode' => ['auto', true],
    'a custom store class' => [InMemoryPickupTimeStore::class, false],
]);

test('without an SQS queue the pickup setup is not reported', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);
    config()->set('queue-autoscale.queues', ['emails' => ['connection' => 'redis']]);

    expect(findingTitled(diagnose(['emails', 'default']), 'SQS queues have no SLA signal'))->toBeNull();
});

test('queues on a custom driver built on SQS have no SLA signal either', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);
    CustomQueueDriver::register('tenant-sqs', SqsQueue::class, driver: 'tenant-sqs');
    config()->set('queue-autoscale.queues', ['orders' => ['connection' => 'tenant-sqs']]);

    $doctor = doctorDiscovering([
        'tenant-sqs:invoices' => ['connection' => 'tenant-sqs', 'queue' => 'invoices'],
    ]);

    $finding = findingTitled($doctor->diagnose(['invoices']), 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toStartWith('orders, invoices: ');
});

/**
 * @param  array<string, array<string, mixed>>  $metrics
 */
function doctorDiscovering(array $metrics): ConfigurationDoctor
{
    return new ConfigurationDoctor(new class($metrics) extends QueueDiscovery
    {
        /**
         * @param  array<string, array<string, mixed>>  $metrics
         */
        public function __construct(private array $metrics) {}

        protected function fromMetrics(): array
        {
            return $this->metrics;
        }
    });
}

test('discovered queues seen on an SQS connection are reported', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);
    config()->set('queue-autoscale.queues', []);

    $doctor = doctorDiscovering([
        'sqs:invoices' => ['connection' => 'sqs', 'queue' => 'invoices'],
        'sqs:reports' => ['connection' => 'sqs', 'queue' => 'reports'],
    ]);

    $finding = findingTitled($doctor->diagnose(['invoices', 'reports']), 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toStartWith('invoices, reports: ');
});

test('discovered queues on other connections are not reported when the default connection is SQS', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);
    config()->set('queue.default', 'sqs');
    config()->set('queue.connections.redis', ['driver' => 'redis']);
    config()->set('queue.connections.database', ['driver' => 'database']);

    $doctor = doctorDiscovering([
        'sqs:invoices' => ['connection' => 'sqs', 'queue' => 'invoices'],
        'redis:emails' => ['connection' => 'redis', 'queue' => 'emails'],
        'database:reports' => ['connection' => 'database', 'queue' => 'reports'],
    ]);

    $finding = findingTitled($doctor->diagnose(['invoices', 'emails', 'reports', 'orders']), 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toStartWith('orders, invoices: ')
        ->and($finding->detail)->not->toContain('emails')
        ->and($finding->detail)->not->toContain('reports');
});

test('without a discovery only configured SQS queues are reported, whatever the default connection', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);
    config()->set('queue.default', 'sqs');

    $finding = findingTitled(diagnose(['invoices', 'emails']), 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toStartWith('orders: ')
        ->and($finding->detail)->not->toContain('invoices');
});

test('a configured queue without a connection is on SQS when the default connection is', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);
    config()->set('queue.default', 'sqs');
    config()->set('queue-autoscale.queues', ['billing' => ['sla' => ['target_seconds' => 60]]]);

    $finding = findingTitled(diagnose([]), 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toStartWith('billing: ');
});

test('a discovery failure falls back to the configured SQS queues', function (): void {
    sqsDoctorSetup(stamp: false, store: 'auto', cluster: false);

    $doctor = new ConfigurationDoctor(new class extends QueueDiscovery
    {
        protected function fromMetrics(): array
        {
            throw new RuntimeException('metrics store unreachable');
        }
    });

    $findings = $doctor->diagnose(['emails']);
    $finding = findingTitled($findings, 'SQS queues have no SLA signal');

    expect($finding)->not->toBeNull()
        ->and($finding->detail)->toStartWith('orders: ');
});
