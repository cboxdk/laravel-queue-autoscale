<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;

test('creates instance with all properties', function () {
    $event = new WorkersScaled(
        connection: 'redis',
        queue: 'default',
        from: 5,
        to: 10,
        action: 'scale_up',
        reason: 'High load detected',
    );

    expect($event->connection)->toBe('redis')
        ->and($event->queue)->toBe('default')
        ->and($event->from)->toBe(5)
        ->and($event->to)->toBe(10)
        ->and($event->action)->toBe('scale_up')
        ->and($event->reason)->toBe('High load detected');
});

test('handles scale down action', function () {
    $event = new WorkersScaled(
        connection: 'redis',
        queue: 'default',
        from: 10,
        to: 5,
        action: 'scale_down',
        reason: 'Low load detected',
    );

    expect($event->from)->toBe(10)
        ->and($event->to)->toBe(5)
        ->and($event->action)->toBe('scale_down');
});

test('handles hold action', function () {
    $event = new WorkersScaled(
        connection: 'redis',
        queue: 'default',
        from: 5,
        to: 5,
        action: 'hold',
        reason: 'Stable load',
    );

    expect($event->from)->toBe(5)
        ->and($event->to)->toBe(5)
        ->and($event->action)->toBe('hold');
});
