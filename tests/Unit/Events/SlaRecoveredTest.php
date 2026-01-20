<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\SlaRecovered;

test('creates instance with all properties', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->connection)->toBe('redis')
        ->and($event->queue)->toBe('default')
        ->and($event->currentJobAge)->toBe(15)
        ->and($event->slaTarget)->toBe(30)
        ->and($event->pending)->toBe(50)
        ->and($event->activeWorkers)->toBe(8);
});

test('calculates margin seconds correctly', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginSeconds())->toBe(15);
});

test('margin seconds returns zero when at sla target', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 30,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginSeconds())->toBe(0);
});

test('margin seconds returns zero when over sla target', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 40,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginSeconds())->toBe(0);
});

test('calculates margin percentage correctly', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginPercentage())->toBe(50.0);
});

test('margin percentage handles zero sla target', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 15,
        slaTarget: 0,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginPercentage())->toBe(0.0);
});

test('margin percentage handles exact sla match', function () {
    $event = new SlaRecovered(
        connection: 'redis',
        queue: 'default',
        currentJobAge: 30,
        slaTarget: 30,
        pending: 50,
        activeWorkers: 8,
    );

    expect($event->marginPercentage())->toBe(0.0);
});
