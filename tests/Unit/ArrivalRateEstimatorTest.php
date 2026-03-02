<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;

beforeEach(function () {
    $this->estimator = new ArrivalRateEstimator;
});

afterEach(function () {
    $this->estimator->reset();
});

/**
 * Helper to shift all snapshot timestamps for a queue in the estimator history
 */
function shiftHistory(ArrivalRateEstimator $estimator, string $queueKey, float $offsetSeconds): void
{
    $history = $estimator->getHistory();
    if (! isset($history[$queueKey])) {
        return;
    }

    foreach ($history[$queueKey] as &$snapshot) {
        $snapshot['timestamp'] += $offsetSeconds;
    }
    unset($snapshot);

    $reflection = new ReflectionClass($estimator);
    $historyProperty = $reflection->getProperty('history');
    $historyProperty->setValue($estimator, $history);
}

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
            ->and($history['redis:default'])->toHaveCount(1)
            ->and($history['redis:default'][0]['backlog'])->toBe(100);
    });
});

describe('interval validation', function () {
    it('returns processing rate when interval is too short', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Immediate second measurement (interval < 1 second)
        $result = $this->estimator->estimate('redis:default', 110, 5.0);

        expect($result['confidence'])->toBe(0.3)
            ->and($result['source'])->toBe('interval_too_short');
    });

    it('uses estimated rate when interval is valid', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);

        shiftHistory($this->estimator, 'redis:default', -10);

        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        // Backlog grew by 50 in 10 seconds = 5/sec growth
        // Arrival = processing + growth = 5.0 + 5.0 = 10.0
        expect(abs($result['rate'] - 10.0))->toBeLessThan(0.01)
            ->and($result['confidence'])->toBeGreaterThan(0.3)
            ->and($result['source'])->toContain('estimated');
    });

    it('treats old history as stale and prunes it', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);

        // Shift beyond MAX_HISTORY_AGE (60s)
        shiftHistory($this->estimator, 'redis:default', -120);

        // The stale snapshot will be pruned, leaving only the new one
        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        // Only one snapshot remains after pruning, so no_history
        expect($result['confidence'])->toBe(0.3)
            ->and($result['source'])->toBe('no_history');
    });
});

describe('arrival rate calculation', function () {
    it('detects growing backlog indicating higher arrival rate', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -10);

        // Backlog grew from 100 to 200 (+100 in 10s = 10/sec growth)
        $result = $this->estimator->estimate('redis:default', 200, 5.0);

        // Arrival = processing + growth = 5.0 + 10.0 = 15.0
        expect(abs($result['rate'] - 15.0))->toBeLessThan(0.01);
    });

    it('detects shrinking backlog indicating lower arrival rate', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -10);

        // Backlog shrunk from 100 to 50 (-50 in 10s = -5/sec growth)
        $result = $this->estimator->estimate('redis:default', 50, 5.0);

        // Arrival = processing + growth = 5.0 + (-5.0) = 0.0 (clamped to 0)
        expect($result['rate'])->toBeLessThan(0.01);
    });

    it('clamps arrival rate to zero (cannot be negative)', function () {
        $this->estimator->estimate('redis:default', 100, 2.0);
        shiftHistory($this->estimator, 'redis:default', -10);

        // Backlog shrunk dramatically: 100 to 0 (-100 in 10s = -10/sec growth)
        // Arrival = 2.0 + (-10.0) = -8.0, but clamped to 0
        $result = $this->estimator->estimate('redis:default', 0, 2.0);

        expect($result['rate'])->toBe(0.0);
    });

    it('handles stable backlog (arrival equals processing)', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -10);

        // Backlog unchanged: growth rate = 0
        $result = $this->estimator->estimate('redis:default', 100, 5.0);

        // Arrival = processing + 0 = 5.0
        expect($result['rate'])->toBe(5.0);
    });
});

describe('sliding window smoothing', function () {
    it('smooths out single-point outliers with multiple samples', function () {
        // Build up a window of stable measurements
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -5);

        $this->estimator->estimate('redis:default', 100, 5.0); // Stable
        shiftHistory($this->estimator, 'redis:default', -5);

        $this->estimator->estimate('redis:default', 100, 5.0); // Stable
        shiftHistory($this->estimator, 'redis:default', -5);

        // One spike (outlier): suddenly +500 jobs
        $result = $this->estimator->estimate('redis:default', 600, 5.0);

        // With weighted average, the spike is dampened by the stable history
        // A single-point estimator would report 100 jobs/sec growth
        // The weighted average should be lower because earlier pairs showed 0 growth
        expect($result['rate'])->toBeLessThan(5.0 + 100.0) // Less than raw spike rate
            ->and($result['rate'])->toBeGreaterThan(5.0); // But still reflects some growth
    });

    it('reacts quickly to sustained changes across multiple samples', function () {
        // Sustained growth pattern
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -5);

        $this->estimator->estimate('redis:default', 150, 5.0); // +50 in 5s = 10/s
        shiftHistory($this->estimator, 'redis:default', -5);

        $this->estimator->estimate('redis:default', 200, 5.0); // +50 in 5s = 10/s
        shiftHistory($this->estimator, 'redis:default', -5);

        $result = $this->estimator->estimate('redis:default', 250, 5.0); // +50 in 5s = 10/s

        // Consistent 10/sec growth across all pairs, so arrival = 5 + 10 = 15
        expect(abs($result['rate'] - 15.0))->toBeLessThan(0.5);
    });

    it('gives more weight to recent observations', function () {
        // Old observation: low growth
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -10);

        $this->estimator->estimate('redis:default', 110, 5.0); // +10 in 10s = 1/s
        shiftHistory($this->estimator, 'redis:default', -5);

        // Recent observation: high growth
        $result = $this->estimator->estimate('redis:default', 210, 5.0); // +100 in 5s = 20/s

        // Weighted average should be closer to recent 20/s than old 1/s
        $growthRate = $result['rate'] - 5.0;
        expect($growthRate)->toBeGreaterThan(10.0); // Closer to 20 than to 1
    });

    it('prunes snapshots beyond maximum window size', function () {
        // Add more than MAX_SNAPSHOTS (5)
        for ($i = 0; $i < 7; $i++) {
            $this->estimator->estimate('redis:default', 100 + ($i * 10), 5.0);
            if ($i < 6) {
                shiftHistory($this->estimator, 'redis:default', -5);
            }
        }

        $history = $this->estimator->getHistory();
        expect(count($history['redis:default']))->toBeLessThanOrEqual(5);
    });
});

describe('confidence calculation', function () {
    it('gives higher confidence for optimal interval (5-30 seconds)', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);

        shiftHistory($this->estimator, 'redis:default', -10);

        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        expect($result['confidence'])->toBeGreaterThanOrEqual(0.6);
    });

    it('gives lower confidence for small backlog changes', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);

        shiftHistory($this->estimator, 'redis:default', -10);

        $result = $this->estimator->estimate('redis:default', 102, 5.0);

        // Small change (<3) should reduce confidence
        expect($result['confidence'])->toBeLessThan(0.7);
    });

    it('increases confidence with more samples in window', function () {
        // Single pair
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -5);
        $singlePairResult = $this->estimator->estimate('redis:default', 150, 5.0);

        $this->estimator->reset();

        // Multiple pairs
        $this->estimator->estimate('redis:default', 100, 5.0);
        shiftHistory($this->estimator, 'redis:default', -5);
        $this->estimator->estimate('redis:default', 125, 5.0);
        shiftHistory($this->estimator, 'redis:default', -5);
        $this->estimator->estimate('redis:default', 150, 5.0);
        shiftHistory($this->estimator, 'redis:default', -5);
        $multiPairResult = $this->estimator->estimate('redis:default', 175, 5.0);

        // More samples should give equal or higher confidence
        expect($multiPairResult['confidence'])->toBeGreaterThanOrEqual($singlePairResult['confidence']);
    });
});

describe('multi-queue support', function () {
    it('tracks multiple queues independently', function () {
        $this->estimator->estimate('redis:default', 100, 5.0);
        $this->estimator->estimate('redis:emails', 50, 2.0);

        $history = $this->estimator->getHistory();

        expect($history)->toHaveKey('redis:default')
            ->and($history)->toHaveKey('redis:emails')
            ->and($history['redis:default'][0]['backlog'])->toBe(100)
            ->and($history['redis:emails'][0]['backlog'])->toBe(50);
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
        $this->estimator->estimate('redis:default', 100, 5.0);

        shiftHistory($this->estimator, 'redis:default', -10);

        $result = $this->estimator->estimate('redis:default', 150, 5.0);

        expect($result['source'])->toContain('estimated')
            ->and($result['source'])->toContain('processing=')
            ->and($result['source'])->toContain('growth=')
            ->and($result['source'])->toContain('delta=')
            ->and($result['source'])->toContain('samples');
    });
});

describe('edge cases', function () {
    it('handles zero processing rate', function () {
        $this->estimator->estimate('redis:default', 100, 0.0);

        shiftHistory($this->estimator, 'redis:default', -10);

        $result = $this->estimator->estimate('redis:default', 150, 0.0);

        // Arrival = 0 + 5.0 = 5.0
        expect(abs($result['rate'] - 5.0))->toBeLessThan(0.01);
    });

    it('handles empty backlog', function () {
        $this->estimator->estimate('redis:default', 0, 5.0);

        shiftHistory($this->estimator, 'redis:default', -10);

        $result = $this->estimator->estimate('redis:default', 0, 5.0);

        expect($result['rate'])->toBe(5.0);
    });
});
