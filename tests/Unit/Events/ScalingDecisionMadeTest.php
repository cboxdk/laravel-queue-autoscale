<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

test('creates instance with scaling decision', function () {
    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 8,
        reason: 'High load detected',
        predictedPickupTime: 25.0,
        slaTarget: 30,
    );

    $event = new ScalingDecisionMade($decision);

    expect($event->decision)->toBe($decision)
        ->and($event->decision->connection)->toBe('redis')
        ->and($event->decision->queue)->toBe('default')
        ->and($event->decision->targetWorkers)->toBe(8);
});
