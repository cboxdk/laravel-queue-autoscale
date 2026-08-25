<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\ClusterCooldown;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\ScalingSimulation;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\SimulationResult;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\WorkloadSimulator;

/**
 * The damper and the engine, together.
 *
 * Every other simulation drives the engine alone, and every cooldown spec
 * drives the damper alone. Both are well behaved in isolation. Composed, on
 * demand whose period is a small multiple of the cooldown window, they used to
 * produce the opposite of what each was for: because every change is a reversal
 * at those periods, each rise was deferred until the backlog breached the SLA,
 * the breach then released a target the delay had itself inflated, and the fall
 * back off that spike was deferred in turn. On the 120s sine below, symmetric
 * damping pinned the fleet to the 20-worker ceiling for a load needing about 5,
 * averaged 9.2 workers and spent 109 of 3600 ticks breaching — simultaneously
 * over-provisioned AND missing the SLA. One-sided peaks at 8, averages 6.5 and
 * never breaches. At a 90s period symmetric holds the SLA but still sits at the
 * ceiling with a mean of 9.8, against 6.4 here.
 *
 * These run with withSimulatedClock(), so the arrival-rate estimator sees the
 * five-second cadence and the forecaster actually runs. Without it the
 * estimator measures sub-millisecond intervals, reports 'interval_too_short'
 * every call and falls back to the processing rate — which is the default for
 * the older simulations in this directory.
 *
 * @group simulation
 */
function resonanceRun(int $periodSeconds): SimulationResult
{
    $pattern = [];

    // Integer arrivals per tick, oscillating between 1 and 9 jobs/second.
    for ($tick = 1; $tick <= 3600; $tick++) {
        $pattern[$tick] = (float) max(1, (int) round(5 + 4 * sin(2 * M_PI * $tick / $periodSeconds)));
    }

    return (new ScalingSimulation(simulator: new WorkloadSimulator(baseArrivalRate: 1.0, avgJobTime: 1.0)))
        ->withCooldown(new ClusterCooldown, 60)
        ->withSimulatedClock()
        ->setWorkloadPattern($pattern)
        ->setScalingInterval(5)
        ->setScalingDelay(2)
        ->run(3600);
}

test('an oscillating workload inside the damping band never breaches the SLA', function (int $period): void {
    $result = resonanceRun($period);

    expect($result->hadNoSlaBreaches())->toBeTrue();
})->with([90, 120, 150, 180, 240])->group('simulation');

test('the damper does not drive the fleet into the worker ceiling', function (): void {
    // True need oscillates between 1 and 9 jobs/s at one second per job, so
    // workers.max is far above anything this load justifies. Reaching it is the
    // signature of the manufactured breach: the deferred rise let the backlog
    // grow, and the urgency in the released target had nothing to do with the
    // arrival rate. Measured at workers.max = 20: the ceiling was reached and
    // the SLA missed, against a peak of 13 and no breach now.
    $result = resonanceRun(120);

    expect($result->getMaxWorkersReached())->toBeLessThan(18)
        ->and($result->getAverageWorkers())->toBeLessThan(9.0);
})->group('simulation');

test('a steady load settles instead of cycling', function (): void {
    // An invariant guard rather than a regression: this scenario behaves the
    // same before and after the change. It is here so that a future adjustment
    // to the damper cannot start the fleet moving at unchanging load without
    // something failing.
    $pattern = [];
    for ($tick = 1; $tick <= 1800; $tick++) {
        $pattern[$tick] = 5.0;
    }

    $result = (new ScalingSimulation(simulator: new WorkloadSimulator(baseArrivalRate: 1.0, avgJobTime: 1.0)))
        ->withCooldown(new ClusterCooldown, 60)
        ->withSimulatedClock()
        ->setWorkloadPattern($pattern)
        ->setScalingInterval(5)
        ->setScalingDelay(2)
        ->run(1800);

    expect($result->hadNoSlaBreaches())->toBeTrue()
        ->and($result->getMaxWorkersReached())->toBeLessThan(10);
})->group('simulation');
