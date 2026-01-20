<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;

beforeEach(function () {
    $this->estimator = new ArrivalRateEstimator;
});

afterEach(function () {
    $this->estimator->reset();
});

describe('first measurement (no history)', function () {
    it('returns processing rate with low confidence when no history exists', function () {
        $result = $this->estimator->estimate('redis:default', 100, 5.0);

        expect($result['rate'])->toBe(5.0)
            ->and($result['confidence'])->toBe(0.3)
            ->and($result['source'])->toBe('no_history');
    });

    it('stores the first measurement for future calculations', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);

        $history = $this->estimator->getHistory();
        expect($history)->toHaveKey('redis:default')
            ->and($history['redis:default']['backlog'])->toBe(100);
    });
});

describe('interval validation', function () {
    it('returns processing rate when interval is too short', function () {
        // First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Immediate second measurement (interval < 1 second)
        $result = $this->estimator->estimate('redis:default', 110, 5.0);

        expect($result['confidence'])->toBe(0.3)
            ->and($result['source'])->toBe('interval_too_short');
    });

    it('uses estimated rate when interval is valid', function () {
        // First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Simulate time passing by manipulating history
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10; // 10 seconds ago

        // Use reflection to set the manipulated history
        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        // Second measurement after 10 seconds
        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        // Backlog grew by 50 in 10 seconds = 5/sec growth
        // Arrival = processing + growth = 5.0 + 5.0 = 10.0
        expect(abs($result['rate'] - 10.0))->toBeLessThan(0.01)
            ->and($result['confidence'])->toBeGreaterThan(0.3)
            ->and($result['source'])->toContain('estimated');
    });

    it('treats old history as stale', function () {
        // First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Simulate very old timestamp (>60 seconds)
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 120; // 2 minutes ago

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        expect($result['confidence'])->toBe(0.4)
            ->and($result['source'])->toBe('history_stale');
    });
});

describe('arrival rate calculation', function () {
    it('detects growing backlog indicating higher arrival rate', function () {
        // Setup: First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Manipulate timestamp to simulate 10 second interval
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        // Backlog grew from 100 to 200 (+100 in 10s = 10/sec growth)
        $result = $this->estimator->estimate('redis:default', 200, 5.0);

        // Arrival = processing + growth = 5.0 + 10.0 = 15.0
        expect(abs($result['rate'] - 15.0))->toBeLessThan(0.01);
    });

    it('detects shrinking backlog indicating lower arrival rate', function () {
        // Setup: First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Manipulate timestamp
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        // Backlog shrunk from 100 to 50 (-50 in 10s = -5/sec growth)
        $result = $this->estimator->estimate('redis:default', 50, 5.0);

        // Arrival = processing + growth = 5.0 + (-5.0) = 0.0 (clamped to 0)
        expect($result['rate'])->toBeLessThan(0.01);
    });

    it('clamps arrival rate to zero (cannot be negative)', function () {
        // Setup: First measurement
        $this->estimator->estimate('redis:default', 100, 2.0);

        // Manipulate timestamp
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        // Backlog shrunk dramatically: 100 to 0 (-100 in 10s = -10/sec growth)
        // Arrival = 2.0 + (-10.0) = -8.0, but clamped to 0
        $result = $this->estimator->estimate('redis:default', 0, 2.0);

        expect($result['rate'])->toBe(0.0);
    });

    it('handles stable backlog (arrival equals processing)', function () {
        // Setup: First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Manipulate timestamp
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        // Backlog unchanged: growth rate = 0
        $result = $this->estimator->estimate('redis:default', 100, 5.0);

        // Arrival = processing + 0 = 5.0
        expect($result['rate'])->toBe(5.0);
    });
});

describe('confidence calculation', function () {
    it('gives higher confidence for optimal interval (5-30 seconds)', function () {
        // First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Optimal interval (10 seconds) with significant change
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        // Should have high confidence for optimal interval with significant change
        expect($result['confidence'])->toBeGreaterThanOrEqual(0.7);
    });

    it('gives lower confidence for small backlog changes', function () {
        // First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Good interval but tiny change (might be noise)
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        $result = $this->estimator->estimate('redis:default', 102, 5.0);

        // Small change (<3) should reduce confidence
        expect($result['confidence'])->toBeLessThan(0.7);
    });
});

describe('multi-queue support', function () {
    it('tracks multiple queues independently', function () {
        // First measurements for two queues
        $this->estimator->estimate('redis:default', 100, 5.0);
        $this->estimator->estimate('redis:emails', 50, 2.0);

        $history = $this->estimator->getHistory();

        expect($history)->toHaveKey('redis:default')
            ->and($history)->toHaveKey('redis:emails')
            ->and($history['redis:default']['backlog'])->toBe(100)
            ->and($history['redis:emails']['backlog'])->toBe(50);
    });

    it('clears history for a specific queue', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);
        $this->estimator->estimate('redis:emails', 50, 2.0);

        $this->estimator->clearHistory('redis:default');

        $history = $this->estimator->getHistory();

        expect($history)->not->toHaveKey('redis:default')
            ->and($history)->toHaveKey('redis:emails');
    });

    it('resets all history', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);
        $this->estimator->estimate('redis:emails', 50, 2.0);

        $this->estimator->reset();

        expect($this->estimator->getHistory())->toBeEmpty();
    });
});

describe('source information', function () {
    it('provides detailed source info for estimated rates', function () {
        // First measurement
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Manipulate timestamp
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        expect($result['source'])->toContain('estimated')
            ->and($result['source'])->toContain('processing=')
            ->and($result['source'])->toContain('growth=')
            ->and($result['source'])->toContain('delta=');
    });
});

describe('edge cases', function () {
    it('handles zero processing rate', function () {
        // First measurement
        $this->estimator->estimate('redis:default', 100, 0.0);

        // Manipulate timestamp
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        // Backlog grew even with no processing
        $result = $this->estimator->estimate('redis:default', 150, 0.0);

        // Arrival = 0 + 5.0 = 5.0
        expect(abs($result['rate'] - 5.0))->toBeLessThan(0.01);
    });

    it('handles empty backlog', function () {
        // First measurement with empty backlog
        $this->estimator->estimate('redis:default', 0, 5.0);

        // Manipulate timestamp
        $history = $this->estimator->getHistory();
        $history['redis:default']['timestamp'] -= 10;

        $reflection = new ReflectionClass($this->estimator);
        $historyProperty = $reflection->getProperty('history');
        $historyProperty->setValue($this->estimator, $history);

        // Still empty
        $result = $this->estimator->estimate('redis:default', 0, 5.0);

        expect($result['rate'])->toBe(5.0);
    });
});
