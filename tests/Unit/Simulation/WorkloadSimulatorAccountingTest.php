<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Tests\Simulation\WorkloadSimulator;

/**
 * The simulator's own books have to balance.
 *
 * Whole jobs enter and leave the queue while arrivals and throughput are both
 * fractional. Rounding the two sides independently — ceil() in, floor() out —
 * grew the job list by up to one phantom job per tick at any non-integer rate,
 * and nothing failed: oldestJobAge is read off that list, so a simulation
 * reported an ever-growing SLA breach while the backlog figure said zero. Every
 * simulation using a fractional arrival rate was quietly measuring the bug.
 *
 * Deliberately NOT in the `simulation` group: this guards the instrument, so it
 * has to run in the default suite alongside everything else.
 */
test('a fractional arrival rate does not accumulate phantom jobs', function (): void {
    $simulator = new WorkloadSimulator(baseArrivalRate: 5.4, avgJobTime: 1.0);
    $simulator->reset();
    $simulator->setWorkers(6);

    for ($tick = 0; $tick < 3600; $tick++) {
        $simulator->tick();
    }

    expect($simulator->getBacklog())->toBe(0.0)
        ->and($simulator->getOldestJobAge())->toBe(0.0);
});

test('resetting clears the carried remainders', function (): void {
    $simulator = new WorkloadSimulator(baseArrivalRate: 0.5, avgJobTime: 1.0);
    $simulator->setWorkers(0);
    $simulator->tick();
    $simulator->reset();
    $simulator->setWorkers(1);

    for ($tick = 0; $tick < 100; $tick++) {
        $simulator->tick();
    }

    expect($simulator->getOldestJobAge())->toBe(0.0);
});
