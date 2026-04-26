<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

test('first call allows the alert through', function (): void {
    $limiter = new AlertRateLimiter(cooldownSeconds: 300);

    expect($limiter->allow('breach:redis:payments'))->toBeTrue();
});

test('subsequent calls within cooldown are suppressed', function (): void {
    $limiter = new AlertRateLimiter(cooldownSeconds: 300);

    expect($limiter->allow('breach:redis:payments'))->toBeTrue();
    expect($limiter->allow('breach:redis:payments'))->toBeFalse();
    expect($limiter->allow('breach:redis:payments'))->toBeFalse();
});

test('different keys are rate-limited independently', function (): void {
    $limiter = new AlertRateLimiter(cooldownSeconds: 300);

    expect($limiter->allow('breach:redis:payments'))->toBeTrue();
    expect($limiter->allow('breach:redis:emails'))->toBeTrue();
    expect($limiter->allow('high_util:redis:payments'))->toBeTrue();
});

test('cooldown seconds is exposed as a public property', function (): void {
    $limiter = new AlertRateLimiter(cooldownSeconds: 60);

    expect($limiter->cooldownSeconds)->toBe(60);
});
