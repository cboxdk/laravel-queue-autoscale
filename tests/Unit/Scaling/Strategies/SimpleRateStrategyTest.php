<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Strategies\SimpleRateStrategy;
use Tests\Helpers\MetricsHelper;

beforeEach(function () {
    $this->strategy = app(SimpleRateStrategy::class);
    $this->config = makeQueueConfig(['minWorkers' => 0]);
});

test('calculates workers using little\'s law', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 60.0, // 1 job/sec
        'avgDuration' => 2000, // 2 seconds
        'oldestJobAge' => 10,
        'activeWorkers' => 2,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Little's Law: L = λW = 1 job/s × 2s = 2 workers
    expect($workers)->toBe(2);
});

test('returns zero workers for idle queue', function () {
    $metrics = MetricsHelper::createMetrics();

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    expect($workers)->toBe(0);
});

test('uses fallback job time when metrics unavailable', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 50,
        'throughputPerMinute' => 60.0, // 1 job/sec
        'avgDuration' => 0, // No duration data
        'oldestJobAge' => 5,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // With no duration data, falls back to 1 second
    // Little's Law: L = 1 job/s × 1s = 1 worker
    expect($workers)->toBe(1);
});

test('provides descriptive reason', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 100,
        'throughputPerMinute' => 120.0, // 2 jobs/sec
        'avgDuration' => 1500, // 1.5 seconds
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
    $metrics = MetricsHelper::createMetrics([
        'pending' => 50,
        'throughputPerMinute' => 60.0,
        'avgDuration' => 2000,
        'oldestJobAge' => 5,
        'activeWorkers' => 2,
    ]);

    $this->strategy->calculateTargetWorkers($metrics, $this->config);
    $prediction = $this->strategy->getLastPrediction();

    // SimpleRateStrategy doesn't track backlog, so no prediction
    expect($prediction)->toBeNull();
});

test('handles high throughput scenarios', function () {
    $metrics = MetricsHelper::createMetrics([
        'pending' => 1000,
        'throughputPerMinute' => 6000.0, // 100 jobs/sec
        'avgDuration' => 500, // 0.5 seconds
        'oldestJobAge' => 2,
        'activeWorkers' => 50,
    ]);

    $workers = $this->strategy->calculateTargetWorkers($metrics, $this->config);

    // Little's Law: L = 100 jobs/s × 0.5s = 50 workers
    expect($workers)->toBe(50);
});
