<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\SlaBreached;

test('creates instance with all properties', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->connection)->toBe('redis')
        ->and($event->queue)->toBe('default')
        ->and($event->oldestJobAge)->toBe(45)
        ->and($event->slaTarget)->toBe(30)
        ->and($event->pending)->toBe(100)
        ->and($event->activeWorkers)->toBe(5);
});

test('calculates breach seconds correctly', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachSeconds())->toBe(15);
});

test('breach seconds returns zero when not breached', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 20,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachSeconds())->toBe(0);
});

test('calculates breach percentage correctly', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachPercentage())->toBe(50.0);
});

test('breach percentage handles zero sla target', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 45,
        slaTarget: 0,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachPercentage())->toBe(0.0);
});

test('breach percentage handles exact sla match', function () {
    $event = new SlaBreached(
        connection: 'redis',
        queue: 'default',
        oldestJobAge: 30,
        slaTarget: 30,
        pending: 100,
        activeWorkers: 5,
    );

    expect($event->breachPercentage())->toBe(0.0);
});
