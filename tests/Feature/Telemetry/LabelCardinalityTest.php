<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Telemetry\TelemetryEventSubscriber;

/**
 * Queues are discovered rather than listed, so an application naming them per
 * tenant presents thousands — one time series per tenant per metric, which is
 * how a metrics backend falls over or produces a very large bill. Queue names
 * embedding tenant identifiers also reach a system with a different
 * access-control model than the app.
 */
function queueLabelFor(string $queue): string
{
    $subscriber = app(TelemetryEventSubscriber::class);
    $method = new ReflectionMethod($subscriber, 'queueLabel');

    return $method->invoke($subscriber, $queue);
}

function resetLabelRegistry(): void
{
    (new ReflectionProperty(TelemetryEventSubscriber::class, 'namedQueues'))->setValue(null, []);
}

beforeEach(fn () => resetLabelRegistry());

test('queues below the cap keep their own label', function (): void {
    config()->set('queue-autoscale.telemetry.max_queue_labels', 5);

    expect(queueLabelFor('payments'))->toBe('payments')
        ->and(queueLabelFor('exports'))->toBe('exports');
});

test('queues past the cap share one bucket', function (): void {
    config()->set('queue-autoscale.telemetry.max_queue_labels', 3);

    foreach (['a', 'b', 'c'] as $queue) {
        expect(queueLabelFor($queue))->toBe($queue);
    }

    expect(queueLabelFor('tenant-9001'))->toBe('__other__')
        ->and(queueLabelFor('tenant-9002'))->toBe('__other__');
});

test('a queue already named keeps its label past the cap', function (): void {
    // Otherwise a busy queue would flip between its name and __other__ as the
    // registry filled, splitting its own series in two.
    config()->set('queue-autoscale.telemetry.max_queue_labels', 2);

    expect(queueLabelFor('payments'))->toBe('payments');
    queueLabelFor('exports');
    queueLabelFor('overflow');

    expect(queueLabelFor('payments'))->toBe('payments');
});

test('the cap can be turned off', function (): void {
    config()->set('queue-autoscale.telemetry.max_queue_labels', null);

    foreach (range(1, 50) as $i) {
        expect(queueLabelFor("tenant-{$i}"))->toBe("tenant-{$i}");
    }
});
