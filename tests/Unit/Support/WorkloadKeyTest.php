<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\WorkloadKey;

/**
 * The stores used to interpolate connection and queue around a colon. Queue
 * names may legitimately contain colons, so two different pairs could produce
 * one key — and with per-tenant queue names that is reachable by anyone who
 * gets to choose a queue name.
 */
test('pairs that used to collide no longer do', function (): void {
    // 'redis' + 'a:b' and 'redis:a' + 'b' both rendered as redis:a:b.
    expect(WorkloadKey::for('redis', 'a:b'))
        ->not->toBe(WorkloadKey::for('redis:a', 'b'));
});

test('the same pair always gives the same key', function (): void {
    expect(WorkloadKey::for('redis', 'exports'))->toBe(WorkloadKey::for('redis', 'exports'));
});

test('different queues on one connection differ', function (): void {
    expect(WorkloadKey::for('redis', 'a'))->not->toBe(WorkloadKey::for('redis', 'b'));
});

test('the key is legal on every cache backend', function (string $queue): void {
    // Memcached rejects spaces and control characters and truncates past 250
    // bytes, so an awkward queue name meant a write that silently failed, a
    // read that returned zero, and a fuse that could never trip.
    $key = WorkloadKey::for('redis', $queue).WorkloadKey::label($queue);

    expect($key)->toMatch('/^[A-Za-z0-9._-]+$/')
        ->and(strlen($key))->toBeLessThan(200);
})->with([
    'plain' => 'exports',
    'with a space' => 'my queue',
    'with a newline' => "bad\nname",
    'very long' => 'x-that-goes-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on',
    'unicode' => 'kø-med-æøå',
]);

test('the label keeps the queue recognisable while scanning', function (): void {
    expect(WorkloadKey::label('scrape-tenant-42'))->toBe('scrape-tenant-42');
});
