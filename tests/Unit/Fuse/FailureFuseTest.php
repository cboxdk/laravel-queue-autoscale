<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Events\FuseProbing;
use Cbox\LaravelQueueAutoscale\Events\FuseRecovered;
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Fuse\FuseState;
use Cbox\LaravelQueueAutoscale\Tests\Helpers\InMemoryFailureWindowStore;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([FuseTripped::class, FuseProbing::class, FuseRecovered::class]);

    $this->store = new InMemoryFailureWindowStore;
    $this->fuse = new FailureFuse($this->store);
    $this->config = makeQueueConfig([
        'minWorkers' => 1,
        'maxWorkers' => 10,
        'fuse' => ['minSamples' => 20, 'failureThresholdPercent' => 50.0, 'cooldownSeconds' => 60],
    ]);
});

test('stays closed while the failure rate is below the threshold', function (): void {
    $this->store->seedWindow(total: 100, failures: 10);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::Closed);
    expect($verdict->workerCeiling(1))->toBeNull();
    Event::assertNotDispatched(FuseTripped::class);
});

test('does not trip on a high failure rate before min_samples is reached', function (): void {
    // 3 of 4 failing is 75%, well over the threshold — but four jobs is not
    // evidence of an outage, and tripping here would stall a healthy queue.
    $this->store->seedWindow(total: 4, failures: 3);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::Closed);
    Event::assertNotDispatched(FuseTripped::class);
});

test('trips once the threshold and min_samples are both crossed', function (): void {
    $this->store->seedWindow(total: 40, failures: 30);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::Open);
    expect($verdict->failureRate)->toBe(75.0);
    expect($verdict->workerCeiling(1))->toBe(1);
    expect($this->store->readState('redis', 'default')['state'])->toBe('open');

    Event::assertDispatched(FuseTripped::class, function (FuseTripped $event): bool {
        return $event->queue === 'default'
            && $event->failureRate === 75.0
            && $event->samples === 40
            && $event->failures === 30;
    });
});

test('holds at workers.min rather than at one worker', function (): void {
    $config = makeQueueConfig([
        'minWorkers' => 3,
        'maxWorkers' => 20,
        'fuse' => ['minSamples' => 20],
    ]);
    $this->store->seedWindow(total: 40, failures: 30);

    expect($this->fuse->evaluate($config)->workerCeiling(3))->toBe(3);
});

test('resets the window when it trips so the same failures cannot re-trip it', function (): void {
    $this->store->seedWindow(total: 40, failures: 30);

    $this->fuse->evaluate($this->config);

    expect($this->store->resetCount)->toBe(1);
    expect($this->store->currentWindow('redis', 'default', 60)['total'])->toBe(0);
});

test('stays open until the cooldown has elapsed', function (): void {
    $this->store->seedState('open', microtime(true) - 30.0);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::Open);
    Event::assertNotDispatched(FuseProbing::class);
});

test('moves to half-open once the cooldown has elapsed', function (): void {
    $this->store->seedState('open', microtime(true) - 61.0);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::HalfOpen);
    expect($this->store->readState('redis', 'default')['state'])->toBe('half_open');
    Event::assertDispatched(FuseProbing::class);
});

test('probes with one worker even when workers.min is zero', function (): void {
    // Scale-to-zero queues would otherwise deadlock: no workers means no jobs,
    // no jobs means no outcomes, and the fuse could never observe recovery.
    $config = makeQueueConfig(['minWorkers' => 0, 'maxWorkers' => 10]);
    $this->store->seedState('open', microtime(true) - 61.0);

    $verdict = $this->fuse->evaluate($config);

    expect($verdict->state)->toBe(FuseState::HalfOpen);
    expect($verdict->workerCeiling(0))->toBe(1);
});

test('closes from half-open when the probe window comes back healthy', function (): void {
    $this->store->seedState('half_open', microtime(true));
    $this->store->seedWindow(total: 25, failures: 1);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::Closed);
    expect($verdict->workerCeiling(1))->toBeNull();
    Event::assertDispatched(FuseRecovered::class);
});

test('re-trips from half-open when the probe is still failing', function (): void {
    $this->store->seedState('half_open', microtime(true));
    $this->store->seedWindow(total: 25, failures: 20);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::Open);
    Event::assertDispatched(FuseTripped::class);
    Event::assertNotDispatched(FuseRecovered::class);
});

test('keeps probing while the half-open window is still inconclusive', function (): void {
    $this->store->seedState('half_open', microtime(true));
    $this->store->seedWindow(total: 5, failures: 0);

    $verdict = $this->fuse->evaluate($this->config);

    expect($verdict->state)->toBe(FuseState::HalfOpen);
    Event::assertNotDispatched(FuseRecovered::class);
    Event::assertNotDispatched(FuseTripped::class);
});

test('never constrains scaling when disabled for the queue', function (): void {
    $config = makeQueueConfig(['fuse' => ['enabled' => false]]);
    $this->store->seedWindow(total: 1000, failures: 1000);

    $verdict = $this->fuse->evaluate($config);

    expect($verdict->state)->toBe(FuseState::Closed);
    expect($verdict->workerCeiling(1))->toBeNull();
    expect($this->store->readState('redis', 'default'))->toBeNull();
    Event::assertNotDispatched(FuseTripped::class);
});

test('treats an unknown persisted state as closed', function (): void {
    $this->store->seedState('bogus', microtime(true));
    $this->store->seedWindow(total: 10, failures: 0);

    expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
});

describe('key isolation', function (): void {
    test('does not read another queue on the same connection', function (): void {
        $this->store->seedWindow(total: 100, failures: 100, queue: 'other');

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
    });

    test('does not read the same queue on another connection', function (): void {
        $this->store->seedWindow(total: 100, failures: 100, connection: 'sqs');

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
    });

    test('does not inherit another queue state', function (): void {
        $this->store->seedState('open', microtime(true), queue: 'other');
        $this->store->seedWindow(total: 10, failures: 0);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
    });

    test('resets only the window it is responsible for', function (): void {
        $this->store->seedWindow(total: 40, failures: 30);
        $this->store->seedWindow(total: 40, failures: 30, queue: 'bystander');

        $this->fuse->evaluate($this->config);

        expect($this->store->currentWindow('redis', 'default', 60)['total'])->toBe(0)
            ->and($this->store->currentWindow('redis', 'bystander', 60)['total'])->toBe(40);
    });
});

describe('store faults', function (): void {
    test('fails open rather than aborting the manager evaluation cycle', function (): void {
        // The fuse is an addition to the scaling path; its own unavailability
        // must not be more damaging than not having it. An unhandled throw
        // here propagates through ScalingEngine and aborts the whole cycle,
        // freezing autoscaling for every queue on the host — including queues
        // that disabled the fuse and queues on other connections.
        $fuse = new FailureFuse(new class extends InMemoryFailureWindowStore
        {
            public function currentWindow(string $connection, string $queue, int $windowSeconds): array
            {
                throw new RuntimeException('READONLY You cannot write against a read only replica');
            }
        });

        $verdict = $fuse->evaluate($this->config);

        expect($verdict->state)->toBe(FuseState::Closed)
            ->and($verdict->workerCeiling(1))->toBeNull();
    });
});

describe('threshold boundaries', function (): void {
    test('trips when the failure rate lands exactly on the threshold', function (): void {
        $this->store->seedWindow(total: 40, failures: 20);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Open);
    });

    test('holds closed just below the threshold', function (): void {
        $this->store->seedWindow(total: 40, failures: 19);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
    });

    test('trips on exactly min_samples', function (): void {
        $this->store->seedWindow(total: 20, failures: 20);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Open);
    });

    test('holds closed one sample short of min_samples', function (): void {
        $this->store->seedWindow(total: 19, failures: 19);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
    });

    test('handles an empty window without dividing by zero', function (): void {
        $this->store->seedWindow(total: 0, failures: 0);

        $verdict = $this->fuse->evaluate($this->config);

        expect($verdict->state)->toBe(FuseState::Closed)
            ->and($verdict->failureRate)->toBe(0.0);
    });

    test('a total failure rate trips at the lowest configured sample count', function (): void {
        $config = makeQueueConfig(['fuse' => ['minSamples' => 1, 'failureThresholdPercent' => 100.0]]);
        $this->store->seedWindow(total: 1, failures: 1);

        expect($this->fuse->evaluate($config)->state)->toBe(FuseState::Open);
    });
});

describe('cooldown boundaries', function (): void {
    test('probes when the cooldown has elapsed exactly', function (): void {
        $this->store->seedState('open', microtime(true) - 60.0);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::HalfOpen);
    });

    test('still holds a hair before the cooldown expires', function (): void {
        $this->store->seedState('open', microtime(true) - 59.5);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Open);
    });

    test('re-tripping from half-open restarts the cooldown clock', function (): void {
        $this->store->seedState('half_open', microtime(true) - 600.0);
        $this->store->seedWindow(total: 30, failures: 30);

        $before = microtime(true);
        $this->fuse->evaluate($this->config);

        // A stale changed_at would send the very next cycle straight back to
        // half-open, collapsing the cooldown to nothing.
        expect($this->store->readState('redis', 'default')['state'])->toBe('open')
            ->and($this->store->readState('redis', 'default')['changed_at'])->toBeGreaterThanOrEqual($before);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Open);
    });
});

describe('probe deadline', function (): void {
    test('closes on a healthy but under-sampled probe once the deadline passes', function (): void {
        // Without a deadline the probe starves itself: it runs at the ceiling
        // of one worker, the window holds at most 2x window_seconds, and a
        // queue whose jobs are slower than (2x window / min_samples) can never
        // reach min_samples — pinned at one worker forever, even fully healthy.
        $this->store->seedState('half_open', microtime(true) - 121.0);
        $this->store->seedWindow(total: 6, failures: 0);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
        Event::assertDispatched(FuseRecovered::class);
    });

    test('re-trips on an under-sampled probe that is still failing', function (): void {
        $this->store->seedState('half_open', microtime(true) - 121.0);
        $this->store->seedWindow(total: 6, failures: 5);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Open);
        Event::assertDispatched(FuseTripped::class);
    });

    test('keeps probing while still inside the deadline', function (): void {
        $this->store->seedState('half_open', microtime(true) - 30.0);
        $this->store->seedWindow(total: 6, failures: 0);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::HalfOpen);
    });

    test('the deadline covers a full window turnover, not just the cooldown', function (): void {
        // cooldown 60s, window 120s -> deadline is 240s, not 60s, so a probe
        // is never judged before its evidence could have accumulated.
        $config = makeQueueConfig([
            'fuse' => ['cooldownSeconds' => 60, 'windowSeconds' => 120, 'minSamples' => 20],
        ]);
        $this->store->seedState('half_open', microtime(true) - 200.0);
        $this->store->seedWindow(total: 3, failures: 0);

        expect($this->fuse->evaluate($config)->state)->toBe(FuseState::HalfOpen);

        $this->store->seedState('half_open', microtime(true) - 241.0);
        $this->store->seedWindow(total: 3, failures: 0);

        expect($this->fuse->evaluate($config)->state)->toBe(FuseState::Closed);
    });

    test('a zero-sample probe at the deadline closes rather than staying stuck', function (): void {
        // No traffic at all during the probe: nothing indicates the dependency
        // is still broken, so releasing scaling is the safe reading.
        $this->store->seedState('half_open', microtime(true) - 121.0);
        $this->store->seedWindow(total: 0, failures: 0);

        expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);
    });
});

describe('window resets', function (): void {
    test('resets when entering the probe', function (): void {
        $this->store->seedState('open', microtime(true) - 61.0);
        $this->store->seedWindow(total: 100, failures: 100);

        $this->fuse->evaluate($this->config);

        // Failures recorded while held down are evidence about the outage,
        // not about the probe, and would re-trip it before it ran a job.
        expect($this->store->resetCount)->toBe(1)
            ->and($this->store->currentWindow('redis', 'default', 60)['total'])->toBe(0);
    });

    test('resets when closing', function (): void {
        $this->store->seedState('half_open', microtime(true));
        $this->store->seedWindow(total: 30, failures: 0);

        $this->fuse->evaluate($this->config);

        expect($this->store->resetCount)->toBe(1);
    });

    test('does not reset while merely holding', function (): void {
        $this->store->seedState('open', microtime(true));
        $this->store->seedWindow(total: 30, failures: 30);

        $this->fuse->evaluate($this->config);

        expect($this->store->resetCount)->toBe(0);
    });

    test('does not reset while closed and healthy', function (): void {
        $this->store->seedWindow(total: 100, failures: 1);

        $this->fuse->evaluate($this->config);

        expect($this->store->resetCount)->toBe(0);
    });
});

test('a full outage and recovery cycle returns to closed', function (): void {
    // Closed → Open → HalfOpen → Closed, driven only through the store the
    // way the manager would across successive evaluation cycles.
    $this->store->seedWindow(total: 50, failures: 45);
    expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Open);

    // Still failing during the hold.
    $this->store->seedWindow(total: 30, failures: 30);
    expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Open);

    // Cooldown expires.
    $this->store->seedState('open', microtime(true) - 61.0);
    expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::HalfOpen);

    // Probe is inconclusive, then comes back healthy.
    $this->store->seedWindow(total: 5, failures: 0);
    expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::HalfOpen);

    $this->store->seedWindow(total: 25, failures: 0);
    expect($this->fuse->evaluate($this->config)->state)->toBe(FuseState::Closed);

    Event::assertDispatchedTimes(FuseTripped::class, 1);
    Event::assertDispatchedTimes(FuseProbing::class, 1);
    Event::assertDispatchedTimes(FuseRecovered::class, 1);
});

test('does not re-announce a state it is already in', function (): void {
    // The cluster leader and each local manager both evaluate the same queue,
    // so a handler that fired on every cycle rather than on transition would
    // flood alerting for the duration of an outage.
    $this->store->seedState('open', microtime(true));
    $this->store->seedWindow(total: 100, failures: 100);

    $this->fuse->evaluate($this->config);
    $this->fuse->evaluate($this->config);
    $this->fuse->evaluate($this->config);

    Event::assertNotDispatched(FuseTripped::class);
});
