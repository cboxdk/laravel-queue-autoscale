<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Illuminate\Support\Facades\Log;

/**
 * Capacity is enforced per queue, and workers.min is applied after the
 * CPU/memory clamp so a floor always beats measured capacity. That is right
 * for one queue — but queues are discovered from metrics, not only read from
 * config, so an app with per-tenant queue names presents thousands of queues
 * and each is raised to its floor. Nothing bounded the sum.
 */
function clampRequest(AutoscaleManager $manager, int $requested): int
{
    $method = new ReflectionMethod(AutoscaleManager::class, 'clampToHostCeiling');

    return $method->invoke($manager, $requested);
}

beforeEach(function (): void {
    $this->manager = app(AutoscaleManager::class);
});

test('grants the full request when no ceiling is configured', function (): void {
    config()->set('queue-autoscale.limits.max_total_workers', null);

    expect(clampRequest($this->manager, 500))->toBe(500);
});

test('grants the full request while there is headroom', function (): void {
    config()->set('queue-autoscale.limits.max_total_workers', 50);

    expect(clampRequest($this->manager, 10))->toBe(10);
});

test('trims a request that would cross the ceiling', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    config()->set('queue-autoscale.limits.max_total_workers', 4);

    // Pool is empty in this harness, so the whole ceiling is the headroom.
    expect(clampRequest($this->manager, 100))->toBe(4);
});

test('a zero or negative ceiling is read as unbounded, not as a freeze', function (int $ceiling): void {
    config()->set('queue-autoscale.limits.max_total_workers', $ceiling);

    expect(clampRequest($this->manager, 7))->toBe(7);
})->with([0, -1]);

test('ignores a non-numeric ceiling', function (): void {
    config()->set('queue-autoscale.limits.max_total_workers', 'lots');

    expect(clampRequest($this->manager, 7))->toBe(7);
});

test('never returns a negative grant', function (): void {
    config()->set('queue-autoscale.limits.max_total_workers', 10);

    expect(clampRequest($this->manager, -3))->toBe(0);
});

test('rate limits the ceiling warning so an incident does not flood the log', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    config()->set('queue-autoscale.limits.max_total_workers', 2);

    foreach (range(1, 20) as $ignored) {
        clampRequest($this->manager, 50);
    }
});
