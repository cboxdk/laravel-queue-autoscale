<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('uses a deployment-stable restart key by default', function () {
    config()->set('app.name', 'Orderscale');
    config()->set('app.env', 'production');
    config()->set('queue-autoscale.manager_id', 'node-a');

    $signal = app(RestartSignal::class);

    expect($signal->cacheKey())->toBe('queue-autoscale:restart:orderscale-production')
        ->and($signal->cacheKey())->not->toContain('node-a');
});

it('allows a custom restart scope', function () {
    config()->set('queue-autoscale.manager.restart_scope', 'Acme Horizon Workers');

    $signal = app(RestartSignal::class);

    expect($signal->cacheKey())->toBe('queue-autoscale:restart:acme-horizon-workers');
});

it('detects a restart request issued after startup', function () {
    $signal = app(RestartSignal::class);
    $startedAt = (int) round(microtime(true) * 1000);

    usleep(2000);
    $signal->issue();

    expect($signal->requestedAfter($startedAt))->toBeTrue();
});

it('detects legacy manager-scoped restart requests', function () {
    $signal = app(RestartSignal::class);
    $startedAt = (int) round(microtime(true) * 1000);

    usleep(2000);
    Cache::forever($signal->managerCacheKey(), (int) round(microtime(true) * 1000));

    expect($signal->requestedAfter($startedAt))->toBeTrue();
});

it('ignores stale restart requests from before startup', function () {
    $signal = app(RestartSignal::class);

    $issuedAt = $signal->issue();
    $startedAt = $issuedAt + 1;

    expect($signal->requestedAfter($startedAt))->toBeFalse();
});

it('detects a laravel queue:restart signal issued after startup', function () {
    $signal = app(RestartSignal::class);
    $startedAtMs = 1_000_000;

    Cache::forever('illuminate:queue:restart', 2_000);

    expect($signal->requestedAfter($startedAtMs))->toBeTrue();
});

it('ignores a laravel queue:restart signal from before startup', function () {
    $signal = app(RestartSignal::class);
    $startedAtMs = 3_000_000;

    Cache::forever('illuminate:queue:restart', 2_000);

    expect($signal->requestedAfter($startedAtMs))->toBeFalse();
});

it('ignores the laravel queue:restart signal when honor_queue_restart is disabled', function () {
    config()->set('queue-autoscale.manager.honor_queue_restart', false);

    $signal = app(RestartSignal::class);
    $startedAtMs = 1_000_000;

    Cache::forever('illuminate:queue:restart', 2_000);

    expect($signal->requestedAfter($startedAtMs))->toBeFalse();
});

it('ignores non-numeric laravel queue:restart values', function () {
    $signal = app(RestartSignal::class);

    Cache::forever('illuminate:queue:restart', 'not-a-timestamp');

    expect($signal->requestedAfter(0))->toBeFalse();
});
