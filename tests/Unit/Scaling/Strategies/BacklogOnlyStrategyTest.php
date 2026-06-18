<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Strategies\BacklogOnlyStrategy;
use Tests\Helpers\MetricsHelper;

beforeEach(function () {
    $this->strategy = app(BacklogOnlyStrategy::class);
    $this->config = makeQueueConfig(['minWorkers' => 0]);
});

test('scales based on backlog only', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 60.0, // Ignored by this strategy
        'avgDuration' => 1.0,
        'oldestJobAge' => 15, // Half SLA age
        'activeWorkers' => 2,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Should calculate workers needed to drain backlog within SLA
    expect($workers)->toBeGreaterThan(0);
});

test('returns zero workers for empty backlog', function () {
    $metrics = MetricsHelper::createMetrics();

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(0);
});

test('uses fallback job time instead of deriving it from active workers', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 30,
        'throughputPerMinute' => 60.0,
        'avgDuration' => 0.0,
        'oldestJobAge' => 0,
        'activeWorkers' => 20,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Fallback job time: 2s. 30 jobs can be cleared inside the 30s SLA by 2 workers.
    expect($workers)->toBe(2);
});

test('scales aggressively for old backlog', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 50,
        'throughputPerMinute' => 30.0,
        'avgDuration' => 2.0,
        'oldestJobAge' => 25, // Close to SLA breach (30s)
        'activeWorkers' => 1,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Should scale up significantly to prevent SLA breach
    expect($workers)->toBeGreaterThan(5);
});

test('provides descriptive reason', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 60.0,
        'avgDuration' => 1.0,
        'oldestJobAge' => 10,
        'activeWorkers' => 2,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)
        ->toContain('Backlog drain')
        ->toContain('jobs')
        ->toContain('SLA=');
});

test('provides pickup time prediction', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 60.0,
        'avgDuration' => 1.0,
        'oldestJobAge' => 25, // 83% of SLA - above breach threshold
        'activeWorkers' => 2,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $prediction = $this->strategy->getLastPrediction();

    expect($prediction)
        ->toBeFloat()
        ->toBeGreaterThan(0.0);
});

test('handles large backlogs efficiently', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 10000, // Large backlog
        'throughputPerMinute' => 300.0,
        'avgDuration' => 0.5,
        'oldestJobAge' => 20,
        'activeWorkers' => 10,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Should calculate substantial workers for large backlog
    expect($workers)->toBeGreaterThan(50);
});

test('reason shows no backlog when queue empty', function () {
    $metrics = MetricsHelper::createMetrics();

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)->toContain('No backlog');
});
