<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Illuminate\Support\Facades\Cache;

it('writes a restart signal for the autoscale manager', function () {
    $signal = app(RestartSignal::class);

    Cache::forget($signal->cacheKey());

    $this->artisan('queue:autoscale:restart')
        ->expectsOutput('Broadcasting queue autoscale restart signal.')
        ->assertSuccessful();

    expect(Cache::get($signal->cacheKey()))
        ->toBeInt();
});
