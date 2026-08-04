<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Fuse\CacheFailureWindowStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    // A null queue driver keeps the command's raw-driver section out of the
    // way; this suite is only interested in the fuse section.
    config()->set('queue.default', 'fuse-conn');
    config()->set('queue.connections.fuse-conn.driver', 'null');
    // A shared (non-array) driver is needed so the array-driver warning is
    // not triggered by every test, but the file driver persists to disk
    // across tests — flush it so each spec starts from a clean fuse.
    config()->set('cache.default', 'file');
    Cache::flush();

    $this->store = new CacheFailureWindowStore;
    $this->app->instance(FailureWindowStoreContract::class, $this->store);
});

/**
 * Assertions run against the captured buffer rather than
 * expectsOutputToContain, because the fuse section renders a table and table
 * output does not reach the expectsOutput assertions.
 */
function debugOutput(): string
{
    Artisan::call('queue:autoscale:debug', ['--queue' => 'default', '--connection' => 'fuse-conn']);

    return Artisan::output();
}

test('reports a closed fuse and its thresholds', function (): void {
    $output = debugOutput();

    expect($output)->toContain('=== Failure Fuse ===')
        ->and($output)->toContain('closed')
        ->and($output)->toContain('50.0%')
        ->and($output)->toContain('60s');
});

test('says so when not enough samples have accumulated to evaluate', function (): void {
    $this->store->recordOutcome('fuse-conn', 'default', failed: true, windowSeconds: 60);

    expect(debugOutput())->toContain('Not enough samples yet');
});

test('surfaces a tripped fuse as the reason a queue is held down', function (): void {
    // This is the whole point of the section: an operator seeing a stuck
    // queue runs this command and learns why in one line.
    $this->store->writeState('fuse-conn', 'default', 'open', microtime(true));

    $output = debugOutput();

    expect($output)->toContain('TRIPPED')
        ->and($output)->toContain('scaling is held at')
        ->and($output)->toContain('probe runs automatically');
});

test('surfaces an in-flight recovery probe', function (): void {
    $this->store->writeState('fuse-conn', 'default', 'half_open', microtime(true));

    expect(debugOutput())->toContain('PROBING');
});

test('reports the observed failure rate', function (): void {
    foreach (range(1, 40) as $i) {
        $this->store->recordOutcome('fuse-conn', 'default', failed: $i <= 30, windowSeconds: 60);
    }

    expect(debugOutput())->toContain('75.0%');
});

test('says the fuse is off rather than showing meaningless numbers', function (): void {
    config()->set('queue-autoscale.queues.default', ['fuse' => ['enabled' => false]]);

    $output = debugOutput();

    expect($output)->toContain('Disabled for this queue')
        ->and($output)->not->toContain('Trips at');
});

test('warns that a null store makes the fuse inert', function (): void {
    config()->set('queue-autoscale.fuse.store', 'null');
    $this->app->forgetInstance(FailureWindowStoreContract::class);

    expect(debugOutput())->toContain('can never trip');
});

test('warns when the array cache driver silently breaks the fuse', function (): void {
    // Workers and the manager are separate processes; the array driver gives
    // each of them its own empty view, so the fuse would never see anything.
    config()->set('cache.default', 'array');

    expect(debugOutput())->toContain('shared cache driver');
});

test('does not warn about the cache driver when a custom store is configured', function (): void {
    config()->set('cache.default', 'array');
    config()->set('queue-autoscale.fuse.store', CacheFailureWindowStore::class);

    expect(debugOutput())->not->toContain('shared cache driver');
});

test('does not warn about the cache driver on a shared backend', function (): void {
    config()->set('cache.default', 'file');

    expect(debugOutput())->not->toContain('shared cache driver');
});
