<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Strategies\SimpleRateStrategy;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;

beforeEach(function () {
    $this->strategy = app(SimpleRateStrategy::class);
    $this->config = makeQueueConfig(['minWorkers' => 0]);
});

test('calculates workers using little\'s law', function () {
    $metrics = QueueMetricsFactory::make([
        'pending' => 100,
        'throughputPerMinute' => 60.0, // 1 job/sec
        'avgDuration' => 2.0,
        'oldestJobAge' => 10,
        'activeWorkers' => 2,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Little's Law: L = λW = 1 job/s × 2s = 2 workers
    expect($workers)->toBe(2);
});

test('returns zero workers for idle queue', function () {
    $metrics = QueueMetricsFactory::make();

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(0);
});

test('uses fallback job time when metrics unavailable', function () {
    $metrics = QueueMetricsFactory::make([
        'pending' => 50,
        'throughputPerMinute' => 60.0, // 1 job/sec
        'avgDuration' => 0, // No duration data
        'oldestJobAge' => 5,
        'activeWorkers' => 20,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // With no duration data, falls back to the configured 2 second job time.
    // Little's Law: L = 1 job/s × 2s = 2 workers
    expect($workers)->toBe(2);
});

test('provides descriptive reason', function () {
    $metrics = QueueMetricsFactory::make([
        'pending' => 100,
        'throughputPerMinute' => 120.0, // 2 jobs/sec
        'avgDuration' => 1.5,
        'oldestJobAge' => 10,
        'activeWorkers' => 3,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $reason = $this->strategy->getLastReason();

    expect($reason)
        ->toContain('Little\'s Law')
        ->toContain('rate=')
        ->toContain('time=')
        ->toContain('workers');
});

test('returns null prediction for simple strategy', function () {
    $metrics = QueueMetricsFactory::make([
        'pending' => 50,
        'throughputPerMinute' => 60.0,
        'avgDuration' => 2.0,
        'oldestJobAge' => 5,
        'activeWorkers' => 2,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $prediction = $this->strategy->getLastPrediction();

    // SimpleRateStrategy doesn't track backlog, so no prediction
    expect($prediction)->toBeNull();
});

test('handles high throughput scenarios', function () {
    $metrics = QueueMetricsFactory::make([
        'pending' => 1000,
        'throughputPerMinute' => 6000.0, // 100 jobs/sec
        'avgDuration' => 0.5,
        'oldestJobAge' => 2,
        'activeWorkers' => 50,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Little's Law: L = 100 jobs/s × 0.5s = 50 workers
    expect($workers)->toBe(50);
});
