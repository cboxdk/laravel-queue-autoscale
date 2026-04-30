<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\TargetSmoother;

beforeEach(function () {
    $this->smoother = new TargetSmoother;
});

describe('first call behaviour', function () {
    it('returns raw target on first call', function () {
        $result = $this->smoother->smooth('redis:default', 8, 1440.0);

        expect($result)->toBe(8);
    });

    it('reports no smoothing applied on first call', function () {
        $this->smoother->smooth('redis:default', 8, 1440.0);

        $info = $this->smoother->getLastSmoothing();
        expect($info['applied'])->toBeFalse();
    });
});

describe('scale-up is unrestricted', function () {
    it('allows immediate scale-up regardless of throughput stability', function () {
        // Build stable throughput history
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 8, 1440.0);
        }

        // Large scale-up: 8 → 12
        $result = $this->smoother->smooth('redis:default', 12, 1440.0);

        expect($result)->toBe(12);
    });

    it('does not apply smoothing on scale-up', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 8, 1440.0);
        }

        $this->smoother->smooth('redis:default', 12, 1440.0);

        expect($this->smoother->getLastSmoothing()['applied'])->toBeFalse();
    });
});

describe('scale-down with stable throughput', function () {
    it('limits scale-down to 1 worker per cycle when throughput is stable', function () {
        // Build stable throughput history at target=12
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 12, 1440.0);
        }

        // Strategy wants to drop to 8 (4 worker drop)
        $result = $this->smoother->smooth('redis:default', 8, 1440.0);

        // Should only drop by 1: 12 → 11
        expect($result)->toBe(11);
    });

    it('reports smoothing was applied', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 12, 1440.0);
        }

        $this->smoother->smooth('redis:default', 8, 1440.0);
        $info = $this->smoother->getLastSmoothing();

        expect($info['applied'])->toBeTrue()
            ->and($info['raw_target'])->toBe(8)
            ->and($info['smoothed_target'])->toBe(11)
            ->and($info['stable'])->toBeTrue();
    });

    it('allows gradual scale-down over multiple cycles', function () {
        // Build stable throughput history at target=12
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 12, 1440.0);
        }

        // Each cycle: strategy wants 8, smoother allows -1 each time
        $targets = [];
        for ($i = 0; $i < 5; $i++) {
            $targets[] = $this->smoother->smooth('redis:default', 8, 1440.0);
        }

        // Should step down gradually: 11, 10, 9, 8, 8
        expect($targets)->toBe([11, 10, 9, 8, 8]);
    });

    it('does not overshoot below the raw target', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 10, 1440.0);
        }

        // Strategy wants 9 (only 1 less) — smoothing should allow it directly
        $result = $this->smoother->smooth('redis:default', 9, 1440.0);

        expect($result)->toBe(9);
        expect($this->smoother->getLastSmoothing()['applied'])->toBeFalse();
    });
});

describe('scale-down with volatile throughput', function () {
    it('allows full scale-down when throughput varies significantly', function () {
        // Build volatile throughput history: swings between 800 and 1600
        $this->smoother->smooth('redis:default', 12, 800.0);
        $this->smoother->smooth('redis:default', 12, 1600.0);
        $this->smoother->smooth('redis:default', 12, 800.0);
        $this->smoother->smooth('redis:default', 12, 1600.0);
        $this->smoother->smooth('redis:default', 12, 800.0);

        // Strategy wants to drop to 6
        $result = $this->smoother->smooth('redis:default', 6, 1600.0);

        // Throughput CV is high → allow full drop
        expect($result)->toBe(6);
    });

    it('allows full scale-down with insufficient history', function () {
        // Only 1 warm-up sample — the scale-down call adds a 2nd, still below minimum of 3
        $this->smoother->smooth('redis:default', 12, 1440.0);

        // Strategy wants to drop to 8
        $result = $this->smoother->smooth('redis:default', 8, 1440.0);

        // Not enough history to determine stability → allow full drop
        expect($result)->toBe(8);
    });
});

describe('per-queue isolation', function () {
    it('tracks state independently per queue', function () {
        // Build stable history for queue A at target=12
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:queue-a', 12, 1440.0);
        }

        // Queue B has no history
        $resultB = $this->smoother->smooth('redis:queue-b', 5, 1440.0);

        // Queue B should not be affected by queue A's history
        expect($resultB)->toBe(5);
    });

    it('smooths each queue independently', function () {
        // Build stable history for both queues
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:queue-a', 12, 1440.0);
            $this->smoother->smooth('redis:queue-b', 8, 1440.0);
        }

        // Both want to drop
        $resultA = $this->smoother->smooth('redis:queue-a', 6, 1440.0);
        $resultB = $this->smoother->smooth('redis:queue-b', 4, 1440.0);

        expect($resultA)->toBe(11) // 12 - 1
            ->and($resultB)->toBe(7); // 8 - 1
    });
});

describe('reset', function () {
    it('clears all state on full reset', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 12, 1440.0);
        }

        $this->smoother->reset();

        // After reset, no previous target → allows full drop
        $result = $this->smoother->smooth('redis:default', 5, 1440.0);
        expect($result)->toBe(5);
    });

    it('clears state for specific queue only', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:queue-a', 12, 1440.0);
            $this->smoother->smooth('redis:queue-b', 10, 1440.0);
        }

        $this->smoother->reset('redis:queue-a');

        // Queue A reset: allows full drop
        $resultA = $this->smoother->smooth('redis:queue-a', 5, 1440.0);
        expect($resultA)->toBe(5);

        // Queue B still has history: limits drop
        $resultB = $this->smoother->smooth('redis:queue-b', 5, 1440.0);
        expect($resultB)->toBe(9);
    });
});

describe('coefficient of variation diagnostics', function () {
    it('reports CV in last smoothing info', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 12, 1440.0);
        }

        $this->smoother->smooth('redis:default', 8, 1440.0);
        $info = $this->smoother->getLastSmoothing();

        // Perfectly stable throughput should have CV = 0
        expect($info['cv'])->toBe(0.0)
            ->and($info['stable'])->toBeTrue();
    });

    it('reports null CV when insufficient samples', function () {
        $this->smoother->smooth('redis:default', 12, 1440.0);
        $this->smoother->smooth('redis:default', 8, 1440.0);

        $info = $this->smoother->getLastSmoothing();

        expect($info['cv'])->toBeNull()
            ->and($info['stable'])->toBeFalse();
    });
});

describe('steady-state oscillation prevention', function () {
    it('holds target stable across 30 cycles with alternating pending-like inputs', function () {
        // Simulate: strategy alternates between wanting 12 and 8 workers
        // due to pending oscillating between 0 and 25. Throughput is constant.
        $equilibrium = 12;
        $throughput = 1440.0;

        // Warm-up: establish stable throughput at equilibrium
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', $equilibrium, $throughput);
        }

        // 30 cycles of oscillation: strategy alternates between 12 and 8
        $targets = [];
        for ($cycle = 0; $cycle < 30; $cycle++) {
            $rawTarget = ($cycle % 2 === 0) ? 8 : 12;
            $targets[] = $this->smoother->smooth('redis:default', $rawTarget, $throughput);
        }

        // Assert all targets stay within ±1 of equilibrium
        foreach ($targets as $i => $target) {
            expect($target)->toBeGreaterThanOrEqual($equilibrium - 1, "Cycle {$i}: target {$target} dropped below ".($equilibrium - 1))
                ->and($target)->toBeLessThanOrEqual($equilibrium, "Cycle {$i}: target {$target} exceeded {$equilibrium}");
        }
    });

    it('never drops more than 1 worker per cycle under stable throughput', function () {
        $throughput = 1440.0;

        // Warm-up at target=12
        for ($i = 0; $i < 5; $i++) {
            $this->smoother->smooth('redis:default', 12, $throughput);
        }

        // Simulate 20 cycles of consistent scale-down pressure
        $previousTarget = 12;
        for ($cycle = 0; $cycle < 20; $cycle++) {
            $target = $this->smoother->smooth('redis:default', 4, $throughput);
            $drop = $previousTarget - $target;

            expect($drop)->toBeLessThanOrEqual(1, "Cycle {$cycle}: dropped {$drop} workers (from {$previousTarget} to {$target})");

            $previousTarget = $target;
        }
    });
});
