<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\PickupSampler;

/**
 * A controllable clock, so rate windows roll on command rather than on wall time.
 */
function samplerClock(float &$now): Closure
{
    return function () use (&$now): float {
        return $now;
    };
}

test('records everything when sampling is disabled', function (): void {
    $now = 1000.0;
    $sampler = new PickupSampler(
        enabled: false,
        maxPerSecond: 1,
        clock: samplerClock($now),
        randomizer: fn (): float => 0.99,
    );

    $kept = 0;

    for ($i = 0; $i < 500; $i++) {
        $kept += $sampler->shouldRecord() ? 1 : 0;
    }

    expect($kept)->toBe(500)
        ->and($sampler->currentSampleRate())->toBe(1.0);
});

test('records everything while the rate stays under the budget', function (): void {
    $now = 1000.0;
    $sampler = new PickupSampler(
        enabled: true,
        maxPerSecond: 100,
        clock: samplerClock($now),
        randomizer: fn (): float => 0.99,
    );

    // Ten jobs a second, ten seconds. Nothing here justifies dropping a sample.
    $kept = 0;

    for ($second = 0; $second < 10; $second++) {
        for ($i = 0; $i < 10; $i++) {
            $kept += $sampler->shouldRecord() ? 1 : 0;
        }
        $now += 1.0;
    }

    expect($kept)->toBe(100);
});

test('the first window is never sampled, because no rate is known yet', function (): void {
    // A cold process has no evidence of the rate, and guessing would be worse
    // than one window of full recording.
    $now = 1000.0;
    $sampler = new PickupSampler(
        enabled: true,
        maxPerSecond: 10,
        clock: samplerClock($now),
        randomizer: fn (): float => 0.99,
    );

    $kept = 0;

    for ($i = 0; $i < 1000; $i++) {
        $kept += $sampler->shouldRecord() ? 1 : 0;
    }

    expect($kept)->toBe(1000);
});

test('throttles to the budget once a busy window has been observed', function (): void {
    $now = 1000.0;
    $sampler = new PickupSampler(
        enabled: true,
        maxPerSecond: 100,
        clock: samplerClock($now),
        randomizer: fn (): float => 0.5,
    );

    // Window one: 10,000 jobs, all recorded (no prior evidence).
    for ($i = 0; $i < 10000; $i++) {
        $sampler->shouldRecord();
    }

    $now += 1.0;
    $sampler->shouldRecord();

    // Window two now prices itself from window one: 100 / 10000.
    expect($sampler->currentSampleRate())->toBe(0.01);
});

test('the inclusion probability is constant across a window', function (): void {
    // Deriving the probability from a running count would make a job's chance
    // of being sampled depend on when in the window it arrived, which biases
    // the estimate during exactly the bursts it exists to measure.
    $now = 1000.0;
    $sampler = new PickupSampler(
        enabled: true,
        maxPerSecond: 100,
        clock: samplerClock($now),
        randomizer: fn (): float => 0.5,
    );

    for ($i = 0; $i < 1000; $i++) {
        $sampler->shouldRecord();
    }

    $now += 1.0;

    $rates = [];

    for ($i = 0; $i < 1000; $i++) {
        $sampler->shouldRecord();
        $rates[] = $sampler->currentSampleRate();
    }

    expect(array_unique($rates))->toHaveCount(1);
});

test('keeps approximately the budgeted number of samples from a burst', function (): void {
    $now = 1000.0;
    $seed = 0;
    $sampler = new PickupSampler(
        enabled: true,
        maxPerSecond: 100,
        clock: samplerClock($now),
        // A deterministic sweep across [0,1) stands in for randomness: it gives
        // the same expected keep-rate without making the test flaky.
        randomizer: function () use (&$seed): float {
            $seed++;

            return ($seed % 1000) / 1000;
        },
    );

    for ($i = 0; $i < 10000; $i++) {
        $sampler->shouldRecord();
    }

    $now += 1.0;

    $kept = 0;

    for ($i = 0; $i < 10000; $i++) {
        $kept += $sampler->shouldRecord() ? 1 : 0;
    }

    // 1% of 10,000, within a tolerance that a real RNG would also satisfy.
    expect($kept)->toBeGreaterThan(50)->toBeLessThan(200);
});

test('an idle process does not carry a stale rate into the burst that wakes it', function (): void {
    // A queue that goes quiet for a minute and then spikes must not have its
    // first busy window sampled against a rate measured before the silence.
    $now = 1000.0;
    $sampler = new PickupSampler(
        enabled: true,
        maxPerSecond: 100,
        clock: samplerClock($now),
        randomizer: fn (): float => 0.99,
    );

    for ($i = 0; $i < 10000; $i++) {
        $sampler->shouldRecord();
    }

    $now += 60.0;

    expect($sampler->shouldRecord())->toBeTrue()
        ->and($sampler->currentSampleRate())->toBe(1.0);
});

test('a non-positive budget disables sampling rather than discarding everything', function (): void {
    // Misconfiguration must fail toward recording, never toward blinding the
    // p95 that every SLA decision depends on.
    $now = 1000.0;

    foreach ([0, -1] as $rate) {
        $sampler = new PickupSampler(
            enabled: true,
            maxPerSecond: $rate,
            clock: samplerClock($now),
            randomizer: fn (): float => 0.99,
        );

        $kept = 0;

        for ($i = 0; $i < 100; $i++) {
            $kept += $sampler->shouldRecord() ? 1 : 0;
        }

        expect($kept)->toBe(100);
    }
});
