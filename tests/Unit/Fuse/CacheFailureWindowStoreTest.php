<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Fuse\CacheFailureWindowStore;

/**
 * Bucket boundaries are derived from wall-clock time, so these specs drive a
 * mutable clock rather than sleeping.
 */
function storeAtClock(float &$now): CacheFailureWindowStore
{
    return new CacheFailureWindowStore(static function () use (&$now): float {
        return $now;
    });
}

test('counts totals and failures for a queue', function (): void {
    $store = new CacheFailureWindowStore;

    $store->recordOutcome('redis', 'default', failed: false, windowSeconds: 60);
    $store->recordOutcome('redis', 'default', failed: false, windowSeconds: 60);
    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);

    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 3, 'failures' => 1]);
});

test('reports an empty window for a queue with no outcomes', function (): void {
    $store = new CacheFailureWindowStore;

    expect($store->currentWindow('redis', 'untouched', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('keeps counters separate per connection and queue', function (): void {
    $store = new CacheFailureWindowStore;

    $store->recordOutcome('redis', 'emails', failed: true, windowSeconds: 60);
    $store->recordOutcome('sqs', 'emails', failed: false, windowSeconds: 60);

    expect($store->currentWindow('redis', 'emails', 60))->toBe(['total' => 1, 'failures' => 1]);
    expect($store->currentWindow('sqs', 'emails', 60))->toBe(['total' => 1, 'failures' => 0]);
});

test('retains outcomes across a bucket rollover', function (): void {
    // The whole point of summing two buckets: a single tumbling bucket would
    // read as zero samples the moment it rolls, which the fuse would treat as
    // "not enough data" and resume scaling mid-outage.
    $now = 1_000_000.0;
    $store = storeAtClock($now);

    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);
    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);

    $now += 60.0;

    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 2, 'failures' => 2]);
});

test('drops outcomes older than two buckets', function (): void {
    $now = 1_000_000.0;
    $store = storeAtClock($now);

    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);

    $now += 180.0;

    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('reset clears both the current and previous bucket', function (): void {
    $now = 1_000_000.0;
    $store = storeAtClock($now);

    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);
    $now += 60.0;
    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);

    $store->resetWindow('redis', 'default', 60);

    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('round-trips fuse state', function (): void {
    $store = new CacheFailureWindowStore;

    $store->writeState('redis', 'default', 'open', 1_234.5);

    expect($store->readState('redis', 'default'))->toBe(['state' => 'open', 'changed_at' => 1_234.5]);
});

test('returns null state for a queue that has never tripped', function (): void {
    expect((new CacheFailureWindowStore)->readState('redis', 'default'))->toBeNull();
});

test('treats a corrupt state payload as no state', function (): void {
    $store = new CacheFailureWindowStore;
    Cache::put('autoscale:fuse:state:redis:default', 'not json', 60);

    expect($store->readState('redis', 'default'))->toBeNull();
});

test('treats a state payload with missing fields as no state', function (array $payload): void {
    $store = new CacheFailureWindowStore;
    Cache::put('autoscale:fuse:state:redis:default', json_encode($payload), 60);

    expect($store->readState('redis', 'default'))->toBeNull();
})->with([
    'no changed_at' => [['state' => 'open']],
    'no state' => [['changed_at' => 123.0]],
    'non-string state' => [[['state' => 42, 'changed_at' => 123.0]]],
    'non-numeric changed_at' => [['state' => 'open', 'changed_at' => 'yesterday']],
    'json array not object' => [['open', 123.0]],
]);

test('counters expire on their own without a sweeper', function (): void {
    // The bucket clock is held still so the key is unchanged; only wall-clock
    // time advances, which is what the cache TTL is measured against.
    $now = 1_000_000.0;
    $store = storeAtClock($now);

    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);
    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 1, 'failures' => 1]);

    $this->travel(121)->seconds();

    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 0, 'failures' => 0]);
});

test('counters survive up to their two-window TTL', function (): void {
    $now = 1_000_000.0;
    $store = storeAtClock($now);

    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 60);

    $this->travel(90)->seconds();

    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 1, 'failures' => 1]);
});

test('a successful outcome counts toward the total without counting as a failure', function (): void {
    $store = new CacheFailureWindowStore;

    foreach (range(1, 10) as $i) {
        $store->recordOutcome('redis', 'default', failed: $i <= 3, windowSeconds: 60);
    }

    expect($store->currentWindow('redis', 'default', 60))->toBe(['total' => 10, 'failures' => 3]);
});

test('failures never exceed the total', function (): void {
    $store = new CacheFailureWindowStore;

    foreach (range(1, 50) as $i) {
        $store->recordOutcome('redis', 'default', failed: $i % 3 === 0, windowSeconds: 60);
    }

    $window = $store->currentWindow('redis', 'default', 60);

    expect($window['failures'])->toBeLessThanOrEqual($window['total']);
});

test('handles the smallest permitted window', function (): void {
    $store = new CacheFailureWindowStore;

    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 1);

    expect($store->currentWindow('redis', 'default', 1)['total'])->toBe(1);
});

test('handles a long window without overflowing the bucket key', function (): void {
    $store = new CacheFailureWindowStore;

    $store->recordOutcome('redis', 'default', failed: true, windowSeconds: 86_400);

    expect($store->currentWindow('redis', 'default', 86_400))->toBe(['total' => 1, 'failures' => 1]);
});

test('resetting one queue leaves its neighbours alone', function (): void {
    $store = new CacheFailureWindowStore;

    $store->recordOutcome('redis', 'emails', failed: true, windowSeconds: 60);
    $store->recordOutcome('redis', 'invoices', failed: true, windowSeconds: 60);

    $store->resetWindow('redis', 'emails', 60);

    expect($store->currentWindow('redis', 'emails', 60)['total'])->toBe(0)
        ->and($store->currentWindow('redis', 'invoices', 60)['total'])->toBe(1);
});

test('state is scoped per connection and queue', function (): void {
    $store = new CacheFailureWindowStore;

    $store->writeState('redis', 'emails', 'open', 100.0);

    expect($store->readState('redis', 'emails')['state'])->toBe('open')
        ->and($store->readState('redis', 'invoices'))->toBeNull()
        ->and($store->readState('sqs', 'emails'))->toBeNull();
});

test('a later state write replaces the earlier one', function (): void {
    $store = new CacheFailureWindowStore;

    $store->writeState('redis', 'default', 'open', 100.0);
    $store->writeState('redis', 'default', 'half_open', 200.0);

    expect($store->readState('redis', 'default'))->toBe(['state' => 'half_open', 'changed_at' => 200.0]);
});
