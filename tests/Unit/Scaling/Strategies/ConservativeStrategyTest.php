<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Strategies\ConservativeStrategy;
use Tests\Helpers\MetricsHelper;

beforeEach(function () {
    $this->strategy = app(ConservativeStrategy::class);
    $this->config = makeQueueConfig(['minWorkers' => 0]);
});

test('adds safety buffer to calculated workers', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 120.0, // 2 jobs/sec
        'avgDuration' => 2000, // 2 seconds
        'oldestJobAge' => 5,
        'activeWorkers' => 4,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Little's Law: 2 jobs/s × 2s = 4 workers base
    // With 25% buffer: 4 × 1.25 = 5 workers
    expect($workers)->toBe(5);
});

test('returns zero workers for idle queue', function () {
    $metrics = MetricsHelper::createMetrics();

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(0);
});

test('scales more aggressively than simple strategy', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 50,
        'throughputPerMinute' => 60.0, // 1 job/sec
        'avgDuration' => 1000, // 1 second
        'oldestJobAge' => 10,
        'activeWorkers' => 2,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Base calculation would be ~1 worker
    // Conservative adds 25% buffer, rounds up
    expect($workers)->toBeGreaterThanOrEqual(2);
});

test('provides reason with buffer information', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 90.0,
        'avgDuration' => 2000,
        'oldestJobAge' => 8,
        'activeWorkers' => 3,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)
        ->toContain('buffer')
        ->toContain('25%')
        ->toContain('workers');
});

test('prioritizes backlog drain when present', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 200,
        'throughputPerMinute' => 60.0,
        'avgDuration' => 1000,
        'oldestJobAge' => 25, // 83% of SLA - above breach threshold
        'activeWorkers' => 2,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Should scale significantly for backlog + buffer
    expect($workers)->toBeGreaterThan(10);
});

test('provides pickup time prediction', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 60.0,
        'avgDuration' => 1000,
        'oldestJobAge' => 5,
        'activeWorkers' => 2,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $prediction = $this->strategy->getLastPrediction();

    expect($prediction)
        ->toBeFloat()
        ->toBeGreaterThan(0.0);
});

test('handles high-volume scenarios conservatively', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 1000,
        'throughputPerMinute' => 4800.0, // 80 jobs/sec
        'avgDuration' => 500, // 0.5 seconds
        'oldestJobAge' => 3,
        'activeWorkers' => 40,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Little's Law: 80 jobs/s × 0.5s = 40 base workers
    // With 25% buffer: 40 × 1.25 = 50 workers
    expect($workers)->toBe(50);
});

test('reason includes both steady state and buffer', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 50,
        'throughputPerMinute' => 120.0,
        'avgDuration' => 1000,
        'oldestJobAge' => 5,
        'activeWorkers' => 2,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)
        ->toContain('steady state')
        ->toContain('base')
        ->toContain('buffer');
});
