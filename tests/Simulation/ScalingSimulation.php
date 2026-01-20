<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Simulation;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\PredictiveStrategy;

/**
 * Runs end-to-end scaling simulations
 *
 * Connects the WorkloadSimulator to the actual ScalingEngine
 * and tracks decisions, metrics, and outcomes over time.
 */
final class ScalingSimulation
{
    private WorkloadSimulator $simulator;

    private ScalingEngine $engine;

    private QueueConfiguration $config;

    private ArrivalRateEstimator $arrivalEstimator;

    /** @var array<int, array{tick: int, decision: ScalingDecision, metrics: array<string, mixed>}> */
    private array $decisions = [];

    /** @var array<int, float> Arrival multiplier per tick */
    private array $workloadPattern = [];

    private int $scalingInterval = 5; // How often to evaluate scaling (ticks)

    private int $scalingDelay = 2; // Ticks before new workers become active

    private int $pendingWorkerChange = 0;

    private int $pendingChangeAtTick = 0;

    public function __construct(
        ?WorkloadSimulator $simulator = null,
        ?QueueConfiguration $config = null,
    ) {
        $this->simulator = $simulator ?? new WorkloadSimulator;

        $this->config = $config ?? new QueueConfiguration(
            connection: 'redis',
            queue: 'default',
            maxPickupTimeSeconds: 30,
            minWorkers: 1,
            maxWorkers: 20,
            scaleCooldownSeconds: 0, // No cooldown in simulation
        );

        // Create fresh arrival rate estimator for each simulation
        $this->arrivalEstimator = new ArrivalRateEstimator;

        $strategy = new PredictiveStrategy(
            new LittlesLawCalculator,
            new BacklogDrainCalculator,
            $this->arrivalEstimator,
        );

        $this->engine = new ScalingEngine($strategy, new CapacityCalculator);
    }

    /**
     * Set the workload pattern for simulation
     *
     * @param  array<int, float>  $pattern  Map of tick => arrival multiplier
     */
    public function setWorkloadPattern(array $pattern): self
    {
        $this->workloadPattern = $pattern;

        return $this;
    }

    /**
     * Set scaling evaluation interval
     */
    public function setScalingInterval(int $ticks): self
    {
        $this->scalingInterval = max(1, $ticks);

        return $this;
    }

    /**
     * Set delay before worker changes take effect
     */
    public function setScalingDelay(int $ticks): self
    {
        $this->scalingDelay = max(0, $ticks);

        return $this;
    }

    /**
     * Set initial workers
     */
    public function setInitialWorkers(int $workers): self
    {
        $this->simulator->setWorkers($workers);

        return $this;
    }

    /**
     * Run simulation for specified duration
     *
     * @param  int  $durationTicks  Duration in ticks (seconds)
     */
    public function run(int $durationTicks): SimulationResult
    {
        $this->simulator->reset();
        $this->decisions = [];
        $this->arrivalEstimator->reset();

        // Set initial workers to minimum
        $this->simulator->setWorkers($this->config->minWorkers);

        for ($tick = 1; $tick <= $durationTicks; $tick++) {
            // Apply pending worker changes
            if ($this->pendingWorkerChange !== 0 && $tick >= $this->pendingChangeAtTick) {
                $currentWorkers = $this->simulator->getActiveWorkers();
                $newWorkers = max($this->config->minWorkers, min($this->config->maxWorkers, $currentWorkers + $this->pendingWorkerChange));
                $this->simulator->setWorkers($newWorkers);
                $this->pendingWorkerChange = 0;
            }

            // Get arrival multiplier for this tick
            $multiplier = $this->getArrivalMultiplier($tick);

            // Advance simulation
            $this->simulator->tick($multiplier);

            // Evaluate scaling at intervals
            if ($tick % $this->scalingInterval === 0) {
                $this->evaluateScaling($tick);
            }
        }

        return new SimulationResult(
            simulator: $this->simulator,
            decisions: $this->decisions,
            config: $this->config,
            durationTicks: $durationTicks,
        );
    }

    /**
     * Get arrival multiplier for a given tick
     */
    private function getArrivalMultiplier(int $tick): float
    {
        // Check for exact tick match
        if (isset($this->workloadPattern[$tick])) {
            return $this->workloadPattern[$tick];
        }

        // Find the most recent pattern value
        $lastValue = 1.0;
        foreach ($this->workloadPattern as $patternTick => $value) {
            if ($patternTick <= $tick) {
                $lastValue = $value;
            } else {
                break;
            }
        }

        return $lastValue;
    }

    /**
     * Evaluate scaling and apply decision
     */
    private function evaluateScaling(int $tick): void
    {
        $metrics = $this->simulator->getMetrics();
        $currentWorkers = $this->simulator->getActiveWorkers();

        $decision = $this->engine->evaluate($metrics, $this->config, $currentWorkers);

        $this->decisions[$tick] = [
            'tick' => $tick,
            'decision' => $decision,
            'metrics' => [
                'backlog' => $this->simulator->getBacklog(),
                'oldestJobAge' => $this->simulator->getOldestJobAge(),
                'currentWorkers' => $currentWorkers,
                'targetWorkers' => $decision->targetWorkers,
                'throughput' => $metrics->throughputPerMinute,
            ],
        ];

        // Schedule worker change with delay
        $workerDiff = $decision->targetWorkers - $currentWorkers;
        if ($workerDiff !== 0) {
            $this->pendingWorkerChange = $workerDiff;
            $this->pendingChangeAtTick = $tick + $this->scalingDelay;
        }
    }

    /**
     * Get the simulator instance
     */
    public function getSimulator(): WorkloadSimulator
    {
        return $this->simulator;
    }

    /**
     * Get configuration
     */
    public function getConfig(): QueueConfiguration
    {
        return $this->config;
    }
}
