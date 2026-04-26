<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('scopes the restart key by app scope id and manager id', function () {
    config()->set('app.name', 'Orderscale');
    config()->set('app.env', 'production');
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
    ]);
    config()->set('queue-autoscale.manager_id', 'node-a');

    $signal = app(RestartSignal::class);

    expect($signal->cacheKey())->toContain('queue-autoscale:restart:')
        ->and($signal->cacheKey())->toContain(AutoscaleConfiguration::applicationScopeId())
        ->and($signal->cacheKey())->toContain('node-a');
});

it('detects a restart request issued after startup', function () {
    $signal = app(RestartSignal::class);
    $startedAt = (int) round(microtime(true) * 1000);

    usleep(2000);
    $signal->issue();

    expect($signal->requestedAfter($startedAt))->toBeTrue();
});

it('ignores stale restart requests from before startup', function () {
    $signal = app(RestartSignal::class);

    $issuedAt = $signal->issue();
    $startedAt = $issuedAt + 1;

    expect($signal->requestedAfter($startedAt))->toBeFalse();
});
