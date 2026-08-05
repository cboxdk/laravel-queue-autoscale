<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Diagnostics\QueueDiscovery;

beforeEach(function (): void {
    config()->set('queue-autoscale.queues', []);
    config()->set('queue-autoscale.excluded', []);
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.limits.max_total_workers', 100);
});

/**
 * QueueDiscovery is the only part of the diagnostic path that touches live
 * infrastructure, so overriding what it reads from metrics is all a spec needs
 * to control. Configured names are still folded in by the real implementation.
 *
 * @param  list<string>  $queues
 */
function fakeDiscovery(array $queues): void
{
    app()->instance(QueueDiscovery::class, new class($queues) extends QueueDiscovery
    {
        /**
         * @param  list<string>  $queues
         */
        public function __construct(private array $queues) {}

        protected function fromMetrics(): array
        {
            $discovered = [];

            foreach ($this->queues as $queue) {
                $discovered["redis:{$queue}"] = ['queue' => $queue, 'connection' => 'redis'];
            }

            return $discovered;
        }
    });
}

test('it says so plainly when there is nothing to report', function (): void {
    fakeDiscovery(['reports']);
    config()->set('queue-autoscale.queues', ['reports' => ['workers' => ['max' => 3]]]);

    $this->artisan('queue:autoscale:doctor')
        ->expectsOutputToContain('Nothing to report')
        ->assertSuccessful();
});

test('it explains itself when no queue has been seen yet', function (): void {
    // A fresh install has no metrics, and "no findings" would read as a clean
    // bill of health when nothing was actually checked.
    fakeDiscovery([]);

    $this->artisan('queue:autoscale:doctor')
        ->expectsOutputToContain('No queues discovered yet')
        ->assertSuccessful();
});

test('it reports a glob that caught an unrelated queue', function (): void {
    fakeDiscovery(['tenant-42', 'tenant-admin-notifications']);
    config()->set('queue-autoscale.queues', ['tenant-*' => ['workers' => ['max' => 5]]]);

    $this->artisan('queue:autoscale:doctor')
        ->expectsOutputToContain('tenant-admin-notifications')
        ->assertSuccessful();
});

test('warnings pass by default and fail under --strict', function (): void {
    // The default has to stay usable in a deploy script that runs it for
    // information; --strict is for the pipeline that wants it as a gate.
    fakeDiscovery(['reports']);
    config()->set('queue-autoscale.queues', ['scrape-tenat-*' => ['workers' => ['max' => 5]]]);

    $this->artisan('queue:autoscale:doctor')->assertSuccessful();
    $this->artisan('queue:autoscale:doctor', ['--strict' => true])->assertFailed();
});

test('a glob is never listed as a discovered queue', function (): void {
    // It would otherwise match itself and report as governing one queue,
    // which is both wrong and reassuring in the worst way.
    fakeDiscovery(['tenant-42']);
    config()->set('queue-autoscale.queues', ['tenant-*' => ['workers' => ['max' => 5]]]);

    $this->artisan('queue:autoscale:doctor')
        ->expectsOutputToContain('Discovered queues: 1')
        ->assertSuccessful();
});
