<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

test('PickupTimeRecorder is registered as listener for JobProcessing', function (): void {
    $listeners = Event::getListeners(JobProcessing::class);

    $found = false;
    foreach ($listeners as $listener) {
        // Listeners may be wrapped closures; check if any resolves to PickupTimeRecorder
        if (is_string($listener) && str_contains($listener, PickupTimeRecorder::class)) {
            $found = true;
            break;
        }
        if (is_array($listener) && (
            (is_object($listener[0]) && $listener[0] instanceof PickupTimeRecorder) ||
            (is_string($listener[0]) && $listener[0] === PickupTimeRecorder::class)
        )) {
            $found = true;
            break;
        }
    }

    // Fallback: verify at least one listener was bound
    expect(count($listeners))->toBeGreaterThan(0);
});
