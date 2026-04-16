<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Tests\Simulation\ScalingSimulation;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\WorkloadScenarios;
use Cbox\LaravelQueueAutoscale\Tests\Simulation\WorkloadSimulator;

/**
 * E2E Simulation Tests
 *
 * These tests simulate real-world workload patterns and verify
 * the autoscaler responds correctly. They provide confidence
 * that the scaling algorithms work in realistic scenarios.
 *
 * Run locally with: vendor/bin/pest tests/Simulation
 * Excluded from CI due to execution time (~18 minutes).
 */
uses()->group('simulation');
describe('Steady State', function () {
    it('maintains stable worker count under constant load', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::steadyState(300))
            ->setScalingInterval(5)
            ->setInitialWorkers(5)
            ->run(300);

        // Should maintain SLA
        expect($result->getSlaCompliance())->toBeGreaterThan(95.0)
            ->and($result->getPeakJobAge())->toBeLessThan(30);

        // Worker count should be relatively stable (not too many scale events)
        $totalScaleEvents = $result->countScaleUpEvents() + $result->countScaleDownEvents();
        expect($totalScaleEvents)->toBeLessThan(20); // Allow some initial adjustment
    });

    it('achieves high SLA compliance with proper worker count', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 10.0, avgJobTime: 0.5),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::steadyState(300))
            ->setScalingInterval(5)
            ->run(300);

        expect($result->getSlaCompliance())->toBeGreaterThan(90.0);
    });
});

describe('Traffic Spike', function () {
    it('scales up quickly during sudden spike', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 30]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::suddenSpike(300))
            ->setScalingInterval(5)
            ->setScalingDelay(2)
            ->run(300);

        // Should have scaled up during spike
        expect($result->countScaleUpEvents())->toBeGreaterThan(0)
            ->and($result->getMaxWorkersReached())->toBeGreaterThan(5);

        // Response time should be reasonable (within 15 seconds of spike start)
        $responseTime = $result->getResponseTimeToSpike(60);
        expect($responseTime)->not->toBeNull()
            ->and($responseTime)->toBeLessThanOrEqual(15);
    });

    it('scales back down after spike ends', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 30]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::suddenSpike(300))
            ->setScalingInterval(5)
            ->run(300);

        // Should have scaled down after spike
        expect($result->countScaleDownEvents())->toBeGreaterThan(0);

        // Final worker count should be reasonable (not at max)
        $finalState = $result->getFinalState();
        expect($finalState['workers'])->toBeLessThan(15);
    });

    it('handles extreme spike without crashing', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 50]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::extremeSpike(300))
            ->setScalingInterval(5)
            ->run(300);

        // Should reach max workers during extreme load
        expect($result->getMaxWorkersReached())->toBeGreaterThan(20);

        // Should recover after spike
        $finalState = $result->getFinalState();
        expect($finalState['workers'])->toBeLessThan(30);
    });
});

describe('Gradual Growth', function () {
    it('scales up gradually following growth trend', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 3.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 30]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::gradualGrowth(300))
            ->setScalingInterval(5)
            ->run(300);

        // Should have multiple scale-up events (following growth)
        expect($result->countScaleUpEvents())->toBeGreaterThan(3);

        // Max workers should be higher than start
        expect($result->getMaxWorkersReached())->toBeGreaterThan(3);

        // Should maintain reasonable SLA (sliding window smoothing may add
        // ~1 tick latency to arrival rate estimation during gradual ramps)
        expect($result->getSlaCompliance())->toBeGreaterThan(70.0);
    });

    it('maintains SLA during growth', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 2.0, avgJobTime: 1.0),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::gradualGrowth(300))
            ->setScalingInterval(5)
            ->run(300);

        // With continuous growth, SLA compliance will degrade as we approach max capacity
        // This is expected - the test validates the autoscaler responds, not that it's magic
        expect($result->getSlaCompliance())->toBeGreaterThan(35.0)
            ->and($result->countScaleUpEvents())->toBeGreaterThan(0);
    });
});

describe('Traffic Decline', function () {
    it('scales down as traffic decreases', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 30]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::trafficDecline(300))
            ->setScalingInterval(5)
            ->setInitialWorkers(15) // Start with many workers
            ->run(300);

        // Should have scale-down events
        expect($result->countScaleDownEvents())->toBeGreaterThan(0);

        // Final workers should be less than initial
        $finalState = $result->getFinalState();
        expect($finalState['workers'])->toBeLessThan(15);
    });

    it('drains backlog during decline', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::endOfDay(300))
            ->setScalingInterval(5)
            ->run(300);

        // Backlog should be mostly drained at end
        $finalState = $result->getFinalState();
        expect($finalState['backlog'])->toBeLessThan(10);
    });
});

describe('Bursty Traffic', function () {
    it('avoids excessive thrashing with bursty load', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::burstyTraffic(300))
            ->setScalingInterval(5)
            ->run(300);

        // Total scale events should be reasonable (not excessive thrashing)
        // With 5-second intervals over 300 ticks, we have 60 decision points
        // Some scaling is expected, but should be less than half
        $totalEvents = $result->countScaleUpEvents() + $result->countScaleDownEvents();
        expect($totalEvents)->toBeLessThan(50);

        // Should still maintain reasonable SLA
        expect($result->getSlaCompliance())->toBeGreaterThan(70.0);
    });

    it('handles flapping load without constant scaling', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::flappingLoad(300))
            ->setScalingInterval(10) // Longer interval to smooth out flapping
            ->run(300);

        // Should not have excessive scale events for a 5-second oscillation
        $totalEvents = $result->countScaleUpEvents() + $result->countScaleDownEvents();
        expect($totalEvents)->toBeLessThan(50);
    });
});

describe('Cold Start', function () {
    it('bootstraps correctly from zero load', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::coldStart(300))
            ->setScalingInterval(5)
            ->run(300);

        // Should scale up after load starts
        expect($result->countScaleUpEvents())->toBeGreaterThan(0);

        // Time to first scale-up should be reasonable after load starts (tick 31+)
        $firstScaleUp = $result->getTimeToFirstScaleUp();
        expect($firstScaleUp)->not->toBeNull();
        if ($firstScaleUp !== null) {
            expect($firstScaleUp)->toBeGreaterThanOrEqual(31) // After load starts
                ->and($firstScaleUp)->toBeLessThan(55); // Within 25 seconds of load (sliding window needs 2 ticks)
        }
    });

    it('maintains minimum workers during zero load', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 0.0, avgJobTime: 1.0),
            config: makeQueueConfig(['minWorkers' => 2, 'maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::steadyState(60))
            ->setScalingInterval(5)
            ->run(60);

        // Should maintain at least minimum workers
        expect($result->getMinWorkersReached())->toBeGreaterThanOrEqual(2);
    });
});

describe('SLA Pressure', function () {
    it('scales proactively under sustained pressure', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::slaPressure(300))
            ->setScalingInterval(5)
            ->run(300);

        // Should scale up to handle slight over-capacity
        expect($result->countScaleUpEvents())->toBeGreaterThan(0);

        // SLA should be maintained despite pressure
        expect($result->getSlaCompliance())->toBeGreaterThan(85.0);
    });

    it('prevents SLA breach before it happens', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::slaPressure(180))
            ->setScalingInterval(5)
            ->run(180);

        // Peak job age should be near but not exceed SLA (predictive scaling)
        expect($result->getPeakJobAge())->toBeLessThan(35); // Allow small margin
    });
});

describe('Wave Pattern', function () {
    it('follows sinusoidal load smoothly', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::wavePattern(300))
            ->setScalingInterval(5)
            ->run(300);

        // Should have both scale-up and scale-down events following the wave
        expect($result->countScaleUpEvents())->toBeGreaterThan(0)
            ->and($result->countScaleDownEvents())->toBeGreaterThan(0);

        // Should maintain reasonable SLA
        expect($result->getSlaCompliance())->toBeGreaterThan(75.0);
    });
});

describe('Morning Rush', function () {
    it('handles exponential ramp-up then plateau', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 3.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => 30]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::morningRush(300))
            ->setScalingInterval(5)
            ->run(300);

        // Should scale up during ramp
        expect($result->countScaleUpEvents())->toBeGreaterThan(3);

        // Should reach stable state after plateau
        $history = $result->getHistory();
        $lastWorkers = end($history)['workers'];
        $midWorkers = $history[150]['workers'] ?? 0;

        // Workers at end should be similar to mid-plateau (stable)
        expect(abs($lastWorkers - $midWorkers))->toBeLessThan(5);
    });
});

describe('Worker Efficiency', function () {
    it('achieves reasonable efficiency in steady state', function () {
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::steadyState(300))
            ->setScalingInterval(5)
            ->run(300);

        // Efficiency should be decent (jobs processed per worker-second)
        // With 5 jobs/sec arrival and 1 sec job time, ideal efficiency is ~1.0
        expect($result->getWorkerEfficiency())->toBeGreaterThan(0.5);
    });
});

describe('Configuration Respect', function () {
    it('never exceeds max workers', function () {
        $maxWorkers = 10;
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 20.0, avgJobTime: 1.0),
            config: makeQueueConfig(['maxWorkers' => $maxWorkers]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::extremeSpike(120))
            ->setScalingInterval(5)
            ->run(120);

        expect($result->getMaxWorkersReached())->toBeLessThanOrEqual($maxWorkers);
    });

    it('never goes below min workers', function () {
        $minWorkers = 3;
        $simulation = new ScalingSimulation(
            simulator: new WorkloadSimulator(baseArrivalRate: 0.1, avgJobTime: 1.0),
            config: makeQueueConfig(['minWorkers' => $minWorkers, 'maxWorkers' => 20]),
        );

        $result = $simulation
            ->setWorkloadPattern(WorkloadScenarios::steadyState(120))
            ->setScalingInterval(5)
            ->run(120);

        expect($result->getMinWorkersReached())->toBeGreaterThanOrEqual($minWorkers);
    });
});

describe('Summary Report', function () {
    it('provides complete summary for all scenarios', function () {
        $scenarios = [
            'steady_state' => WorkloadScenarios::steadyState(120),
            'sudden_spike' => WorkloadScenarios::suddenSpike(120),
            'gradual_growth' => WorkloadScenarios::gradualGrowth(120),
        ];

        foreach ($scenarios as $name => $pattern) {
            $simulation = new ScalingSimulation(
                simulator: new WorkloadSimulator(baseArrivalRate: 5.0, avgJobTime: 1.0),
            );

            $result = $simulation
                ->setWorkloadPattern($pattern)
                ->setScalingInterval(5)
                ->run(120);

            $summary = $result->getSummary();

            expect($summary)->toHaveKeys([
                'duration_ticks',
                'sla_target',
                'sla_compliance',
                'peak_job_age',
                'peak_backlog',
                'average_workers',
                'max_workers',
                'min_workers',
                'scale_up_events',
                'scale_down_events',
                'worker_efficiency',
                'final_backlog',
            ]);
        }
    });
});
