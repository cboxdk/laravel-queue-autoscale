<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
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
