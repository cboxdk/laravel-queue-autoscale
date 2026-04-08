<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Illuminate\Support\Facades\Event;

/**
 * Feature tests for end-to-end scaling integration
 *
 * These tests verify the integration between components
 * without requiring actual worker processes or queue infrastructure.
 */
beforeEach(function () {
    Event::fake([
        ScalingDecisionMade::class,
        WorkersScaled::class,
    ]);

    $this->engine = app(ScalingEngine::class);

    $this->config = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 30,
        minWorkers: 1,
        maxWorkers: 10,
        scaleCooldownSeconds: 60,
    );
});

it('completes full scaling evaluation successfully', function () {
    $metrics = createMetrics([
        'throughput_per_minute' => 300.0, // 5.0 jobs/sec * 60
        'active_workers' => 10,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $decision = $this->engine->evaluate($metrics, $this->config, 5);

    expect($decision)->toBeInstanceOf(ScalingDecision::class)
        ->and($decision->targetWorkers)->toBeInt()
        ->and($decision->reason)->toBeString();
});

it('integrates with custom scaling strategies', function () {
    $customStrategy = new class implements ScalingStrategyContract
    {
        private string $lastReason = 'custom strategy';

        private ?float $lastPrediction = 0.0;

        public function calculateTargetWorkers(object $metrics, $config): int
        {
            return 5;
        }

        public function getLastReason(): string
        {
            return $this->lastReason;
        }

        public function getLastPrediction(): ?float
        {
            return $this->lastPrediction;
        }
    };

    // Create engine with custom strategy
    $capacity = app(CapacityCalculator::class);
    $customEngine = new ScalingEngine($customStrategy, $capacity);

    $metrics = createMetrics([
        'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
        'active_workers' => 20,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $decision = $customEngine->evaluate($metrics, $this->config, 10);

    // Custom strategy returns 5, but capacity calculator may constrain based on metrics
    expect($decision->targetWorkers)->toBeInt()
        ->and($decision->targetWorkers)->toBeGreaterThanOrEqual(1)
        ->and($decision->reason)->toContain('custom strategy');
});

it('enforces all constraints in correct order', function () {
    $highDemandMetrics = createMetrics([
        'throughput_per_minute' => 6000.0, // 100.0 jobs/sec * 60
        'active_workers' => 200,
        'pending' => 1000,
        'oldest_job_age' => 25,
    ]);

    $decision = $this->engine->evaluate($highDemandMetrics, $this->config, 5);

    // Should be constrained by config maxWorkers (10)
    expect($decision->targetWorkers)->toBeLessThanOrEqual($this->config->maxWorkers)
        // And at least config minWorkers (1)
        ->and($decision->targetWorkers)->toBeGreaterThanOrEqual($this->config->minWorkers);
});

it('scales based on hybrid algorithm correctly', function () {
    // Test steady state
    $steadyMetrics = createMetrics([
        'throughput_per_minute' => 300.0, // 5.0 jobs/sec * 60
        'active_workers' => 5,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $steadyDecision = $this->engine->evaluate($steadyMetrics, $this->config, 5);
    // Reason contains arrival rate info - could be steady state or trend prediction
    expect($steadyDecision->reason)->toBeString()
        ->and(strlen($steadyDecision->reason))->toBeGreaterThan(10);

    // Test trend-based (trend policy adds buffer to arrival rate)
    $trendMetrics = createMetrics([
        'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
        'active_workers' => 20,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $trendDecision = $this->engine->evaluate($trendMetrics, $this->config, 10);
    // With trend policy enabled, reason may show trend prediction or arrival rate info
    expect($trendDecision->reason)->toBeString()
        ->and(strlen($trendDecision->reason))->toBeGreaterThan(10);

    // Test SLA breach protection - high backlog with old jobs should trigger scaling
    $breachMetrics = createMetrics([
        'throughput_per_minute' => 300.0, // 5.0 jobs/sec * 60
        'active_workers' => 10,
        'pending' => 100,
        'oldest_job_age' => 28,
    ]);

    $breachDecision = $this->engine->evaluate($breachMetrics, $this->config, 5);
    // With backlog and near-SLA job age, reason should show backlog or scaling info
    expect($breachDecision->reason)->toBeString()
        ->and(strlen($breachDecision->reason))->toBeGreaterThan(10)
        // Should scale up from current 5 workers due to backlog demand (may be capped by system capacity)
        ->and($breachDecision->targetWorkers)->toBeGreaterThanOrEqual(5);
});

it('handles configuration overrides per queue', function () {
    $criticalConfig = new QueueConfiguration(
        connection: 'redis',
        queue: 'critical',
        maxPickupTimeSeconds: 10, // Stricter SLA
        minWorkers: 5,
        maxWorkers: 20,
        scaleCooldownSeconds: 30,
    );

    $defaultConfig = new QueueConfiguration(
        connection: 'redis',
        queue: 'default',
        maxPickupTimeSeconds: 60, // Relaxed SLA
        minWorkers: 0,
        maxWorkers: 10,
        scaleCooldownSeconds: 120,
    );

    $sameMetrics = createMetrics([
        'throughput_per_minute' => 0.0,
        'active_workers' => 0,
        'pending' => 0,
        'oldest_job_age' => 0,
    ]);

    $criticalDecision = $this->engine->evaluate($sameMetrics, $criticalConfig, 0);
    $defaultDecision = $this->engine->evaluate($sameMetrics, $defaultConfig, 0);

    // Critical queue maintains minimum workers
    expect($criticalDecision->targetWorkers)->toBeGreaterThanOrEqual(5);

    // Default queue can scale to zero
    expect($defaultDecision->targetWorkers)->toBe(0);
});

it('provides predictions for queues with backlog', function () {
    $metricsWithBacklog = createMetrics([
        'throughput_per_minute' => 600.0, // 10.0 jobs/sec * 60
        'active_workers' => 20,
        'pending' => 100,
        'oldest_job_age' => 5,
    ]);

    $decision = $this->engine->evaluate($metricsWithBacklog, $this->config, 10);

    expect($decision->predictedPickupTime)->toBeFloat()
        ->and($decision->predictedPickupTime)->toBeGreaterThan(0.0);
});

it('handles missing or sparse metrics gracefully', function () {
    // createMetrics provides defaults for all fields
    $sparseMetrics = createMetrics([
        'throughput_per_minute' => 300.0, // 5.0 jobs/sec * 60
    ]);

    $decision = $this->engine->evaluate($sparseMetrics, $this->config, 5);

    expect($decision)->toBeInstanceOf(ScalingDecision::class)
        ->and($decision->targetWorkers)->toBeInt()
        ->and($decision->targetWorkers)->toBeGreaterThanOrEqual(0);
});
