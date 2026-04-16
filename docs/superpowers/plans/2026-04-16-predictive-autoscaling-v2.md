# Predictive Autoscaling v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship v2.0.0 of `cboxdk/laravel-queue-autoscale` with genuine forecasting (linear regression with R² confidence blending), worker spawn latency compensation, and p95-based SLA signal — plus a clean config restructure with Spatie-style class-replaceable contracts.

**Architecture:** Introduce a new `Contracts/` namespace for swappable algorithms, new `Configuration/` value objects composed per-queue, and a new `Pickup/` namespace for pickup-time persistence. Rename `PredictiveStrategy` to `HybridStrategy` (two calculators now: rate-based on blended λ, backlog-drain on p95). All components remain overridable via Laravel container binding.

**Tech Stack:** PHP 8.3+, Laravel 11/12/13, Pest 4, PHPStan level 9, Pint, Redis (via `cboxdk/laravel-queue-metrics`), Symfony Process.

**Reference spec:** `docs/superpowers/specs/2026-04-16-predictive-autoscaling-v2-design.md`

---

## File Structure

```
src/
├── Contracts/                                          [NEW]
│   ├── ForecasterContract.php
│   ├── ForecastPolicyContract.php
│   ├── SpawnLatencyTrackerContract.php
│   ├── PickupTimeStoreContract.php
│   ├── PercentileCalculatorContract.php
│   └── ProfileContract.php
├── Scaling/
│   ├── Calculators/
│   │   ├── ArrivalRateEstimator.php                    [UPDATE]
│   │   ├── BacklogDrainCalculator.php                  [UPDATE]
│   │   └── LinearRegressionForecaster.php              [NEW]
│   ├── Forecasting/
│   │   ├── ForecastResult.php                          [NEW]
│   │   └── Policies/
│   │       ├── DisabledForecastPolicy.php              [NEW]
│   │       ├── HintForecastPolicy.php                  [NEW]
│   │       ├── ModerateForecastPolicy.php              [NEW]
│   │       └── AggressiveForecastPolicy.php            [NEW]
│   └── Strategies/
│       ├── PredictiveStrategy.php                      [DELETED]
│       └── HybridStrategy.php                          [NEW — former PredictiveStrategy refactored]
├── Workers/
│   ├── WorkerSpawner.php                               [UPDATE]
│   └── SpawnLatency/
│       └── EmaSpawnLatencyTracker.php                  [NEW]
├── Pickup/                                             [NEW]
│   ├── RedisPickupTimeStore.php
│   ├── SortBasedPercentileCalculator.php
│   └── PickupTimeRecorder.php
├── Configuration/
│   ├── SlaConfiguration.php                            [NEW]
│   ├── ForecastConfiguration.php                      [NEW]
│   ├── SpawnCompensationConfiguration.php              [NEW]
│   ├── WorkerConfiguration.php                         [NEW]
│   ├── QueueConfiguration.php                          [REWRITE]
│   ├── ProfilePresets.php                              [DELETED]
│   └── Profiles/
│       ├── CriticalProfile.php                         [NEW]
│       ├── HighVolumeProfile.php                       [NEW]
│       ├── BalancedProfile.php                         [NEW]
│       ├── BurstyProfile.php                           [NEW]
│       └── BackgroundProfile.php                       [NEW]
└── Commands/
    └── MigrateConfigCommand.php                        [NEW]

tests/
├── Unit/                                               [NEW files per component]
├── Feature/
│   └── V2MigrationTest.php                             [NEW]
├── Simulation/
│   └── SimulationTest.php                              [UPDATE — 3 new scenarios]
└── Arch.php                                            [NEW]
```

---

## Task 0: Create v2 feature branch

**Files:** none (branch operation)

- [ ] **Step 1: Verify clean working tree**

Run: `git status`
Expected: `nothing to commit, working tree clean`

- [ ] **Step 2: Create and switch to v2 branch**

Run: `git checkout -b feature/v2-predictive-autoscaling`
Expected: `Switched to a new branch 'feature/v2-predictive-autoscaling'`

- [ ] **Step 3: Push branch to remote**

Run: `git push -u origin feature/v2-predictive-autoscaling`
Expected: branch is pushed and tracking origin.

---

## Task 1: Create all six contracts

**Files:**
- Create: `src/Contracts/ForecasterContract.php`
- Create: `src/Contracts/ForecastPolicyContract.php`
- Create: `src/Contracts/SpawnLatencyTrackerContract.php`
- Create: `src/Contracts/PickupTimeStoreContract.php`
- Create: `src/Contracts/PercentileCalculatorContract.php`
- Create: `src/Contracts/ProfileContract.php`

Note: Contracts alone do not require failing tests — they have no behavior. Tests arrive with implementations in later tasks. Arch tests in Task 21 verify contracts are interfaces.

- [ ] **Step 1: Create `ForecasterContract`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;

interface ForecasterContract
{
    /**
     * @param  list<array{timestamp: float, rate: float}>  $history
     */
    public function forecast(array $history, int $horizonSeconds): ForecastResult;
}
```

- [ ] **Step 2: Create `ForecastPolicyContract`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface ForecastPolicyContract
{
    /** Minimum R² for a forecast to be trusted. Returns > 1.0 to effectively disable. */
    public function minRSquared(): float;

    /** Blending weight for forecast in [0.0, 1.0]. */
    public function forecastWeight(): float;
}
```

- [ ] **Step 3: Create `SpawnLatencyTrackerContract`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface SpawnLatencyTrackerContract
{
    public function recordSpawn(string $workerId, string $connection, string $queue): void;

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void;

    /** Current spawn latency in seconds for the given queue. */
    public function currentLatency(string $connection, string $queue): float;
}
```

- [ ] **Step 4: Create `PickupTimeStoreContract`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface PickupTimeStoreContract
{
    public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void;

    /**
     * @return list<array{timestamp: float, pickup_seconds: float}>
     */
    public function recentSamples(string $connection, string $queue, int $windowSeconds): array;
}
```

- [ ] **Step 5: Create `PercentileCalculatorContract`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface PercentileCalculatorContract
{
    /**
     * Compute the given percentile (0-100) over the values.
     *
     * @param  list<float>  $values
     * @return float|null Null if insufficient samples.
     */
    public function compute(array $values, int $percentile): ?float;
}
```

- [ ] **Step 6: Create `ProfileContract`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

interface ProfileContract
{
    /**
     * @return array{
     *     sla: array{target_seconds: int, percentile: int, window_seconds: int, min_samples: int},
     *     forecast: array{forecaster: class-string, policy: class-string, horizon_seconds: int, history_seconds: int},
     *     workers: array{min: int, max: int, tries: int, timeout_seconds: int, sleep_seconds: int, shutdown_timeout_seconds: int},
     *     spawn_compensation: array{enabled: bool, fallback_seconds: float, min_samples: int, ema_alpha: float},
     * }
     */
    public function resolve(): array;
}
```

- [ ] **Step 7: Run PHPStan to verify syntax**

Run: `vendor/bin/phpstan analyse src/Contracts --level=9 --no-progress`
Expected: `[OK] No errors`

- [ ] **Step 8: Commit**

```bash
git add src/Contracts/
git commit -m "feat(v2): add replaceable-algorithm contracts"
```

---

## Task 2: Add `ForecastResult` DTO

**Files:**
- Create: `src/Scaling/Forecasting/ForecastResult.php`
- Create: `tests/Unit/Scaling/Forecasting/ForecastResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;

test('insufficient data returns a result flagged as insufficient', function (): void {
    $result = ForecastResult::insufficientData();

    expect($result->hasSufficientData)->toBeFalse()
        ->and($result->projectedRate)->toBe(0.0)
        ->and($result->rSquared)->toBe(0.0)
        ->and($result->sampleCount)->toBe(0);
});

test('can construct a result with all values', function (): void {
    $result = new ForecastResult(
        projectedRate: 12.5,
        rSquared: 0.92,
        slope: 0.3,
        sampleCount: 60,
        hasSufficientData: true,
    );

    expect($result->projectedRate)->toBe(12.5)
        ->and($result->rSquared)->toBe(0.92)
        ->and($result->slope)->toBe(0.3)
        ->and($result->sampleCount)->toBe(60)
        ->and($result->hasSufficientData)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Scaling/Forecasting/ForecastResultTest.php`
Expected: FAIL with "class ForecastResult not found"

- [ ] **Step 3: Implement `ForecastResult`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Forecasting;

final readonly class ForecastResult
{
    public function __construct(
        public float $projectedRate,
        public float $rSquared,
        public float $slope,
        public int $sampleCount,
        public bool $hasSufficientData,
    ) {}

    public static function insufficientData(): self
    {
        return new self(0.0, 0.0, 0.0, 0, false);
    }
}
```

- [ ] **Step 4: Run test to verify pass + PHPStan**

Run: `vendor/bin/pest tests/Unit/Scaling/Forecasting/ForecastResultTest.php && vendor/bin/phpstan analyse src/Scaling/Forecasting --level=9 --no-progress`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty
git add src/Scaling/Forecasting/ tests/Unit/Scaling/Forecasting/
git commit -m "feat(v2): add ForecastResult DTO"
```

---

## Task 3: Implement `LinearRegressionForecaster`

**Files:**
- Create: `src/Scaling/Calculators/LinearRegressionForecaster.php`
- Create: `tests/Unit/Scaling/Calculators/LinearRegressionForecasterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;

test('returns insufficient data when history has fewer than 5 samples', function (): void {
    $forecaster = new LinearRegressionForecaster();
    $history = [
        ['timestamp' => 1000.0, 'rate' => 1.0],
        ['timestamp' => 1001.0, 'rate' => 2.0],
    ];

    $result = $forecaster->forecast($history, 60);

    expect($result->hasSufficientData)->toBeFalse();
});

test('perfectly linear input produces R² = 1.0 and correct slope', function (): void {
    $forecaster = new LinearRegressionForecaster();
    // Rate grows at 1/s: at t=0 rate=10, at t=10 rate=20 ...
    $history = [];
    for ($i = 0; $i < 10; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => 10.0 + (float) $i];
    }

    $result = $forecaster->forecast($history, 60);

    // Projects 60 seconds past last timestamp (9.0) → t=69, rate = 79.0.
    expect($result->hasSufficientData)->toBeTrue()
        ->and($result->rSquared)->toBeGreaterThan(0.999)
        ->and($result->slope)->toBeGreaterThan(0.999)->toBeLessThan(1.001)
        ->and($result->projectedRate)->toBeGreaterThan(78.9)->toBeLessThan(79.1);
});

test('flat line returns slope near zero and R² of 1.0', function (): void {
    $forecaster = new LinearRegressionForecaster();
    $history = [];
    for ($i = 0; $i < 10; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => 5.0];
    }

    $result = $forecaster->forecast($history, 60);

    // Zero variance → perfect fit (return 1.0 per spec risk #4).
    expect($result->hasSufficientData)->toBeTrue()
        ->and(abs($result->slope))->toBeLessThan(0.0001)
        ->and($result->rSquared)->toBe(1.0)
        ->and($result->projectedRate)->toBeGreaterThan(4.99)->toBeLessThan(5.01);
});

test('noisy data produces R² below 0.5', function (): void {
    $forecaster = new LinearRegressionForecaster();
    $rates = [5.0, 15.0, 3.0, 18.0, 2.0, 20.0, 4.0, 17.0];
    $history = [];
    foreach ($rates as $i => $rate) {
        $history[] = ['timestamp' => (float) $i, 'rate' => $rate];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->hasSufficientData)->toBeTrue()
        ->and($result->rSquared)->toBeLessThan(0.5);
});

test('negative slope allowed for declining arrival rate', function (): void {
    $forecaster = new LinearRegressionForecaster();
    $history = [];
    for ($i = 0; $i < 10; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => 100.0 - (float) $i];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->slope)->toBeLessThan(0.0)
        ->and($result->projectedRate)->toBeLessThan(100.0);
});

test('sample count reflects input size', function (): void {
    $forecaster = new LinearRegressionForecaster();
    $history = [];
    for ($i = 0; $i < 15; $i++) {
        $history[] = ['timestamp' => (float) $i, 'rate' => (float) $i];
    }

    $result = $forecaster->forecast($history, 60);

    expect($result->sampleCount)->toBe(15);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Scaling/Calculators/LinearRegressionForecasterTest.php`
Expected: FAIL with "class LinearRegressionForecaster not found"

- [ ] **Step 3: Implement `LinearRegressionForecaster`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Calculators;

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;

final class LinearRegressionForecaster implements ForecasterContract
{
    private const MIN_SAMPLES = 5;

    public function forecast(array $history, int $horizonSeconds): ForecastResult
    {
        $n = count($history);

        if ($n < self::MIN_SAMPLES) {
            return ForecastResult::insufficientData();
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;
        $latestT = 0.0;

        foreach ($history as $point) {
            $sumX += $point['timestamp'];
            $sumY += $point['rate'];
            $sumXY += $point['timestamp'] * $point['rate'];
            $sumXX += $point['timestamp'] * $point['timestamp'];
            if ($point['timestamp'] > $latestT) {
                $latestT = $point['timestamp'];
            }
        }

        $meanX = $sumX / $n;
        $meanY = $sumY / $n;

        $denominator = $sumXX - $n * $meanX * $meanX;

        if (abs($denominator) < 1e-12) {
            // All timestamps identical — undefined slope; treat as flat.
            return new ForecastResult(
                projectedRate: $meanY,
                rSquared: 1.0,
                slope: 0.0,
                sampleCount: $n,
                hasSufficientData: true,
            );
        }

        $slope = ($sumXY - $n * $meanX * $meanY) / $denominator;
        $intercept = $meanY - $slope * $meanX;

        // R² = 1 - SS_res / SS_tot
        $ssTot = 0.0;
        $ssRes = 0.0;
        foreach ($history as $point) {
            $predicted = $slope * $point['timestamp'] + $intercept;
            $ssRes += ($point['rate'] - $predicted) ** 2;
            $ssTot += ($point['rate'] - $meanY) ** 2;
        }

        $rSquared = $ssTot < 1e-12
            ? 1.0                                // constant series — perfect fit per spec risk #4
            : max(0.0, 1.0 - ($ssRes / $ssTot));

        $projectedRate = max(0.0, $slope * ($latestT + $horizonSeconds) + $intercept);

        return new ForecastResult(
            projectedRate: $projectedRate,
            rSquared: $rSquared,
            slope: $slope,
            sampleCount: $n,
            hasSufficientData: true,
        );
    }
}
```

- [ ] **Step 4: Run tests + PHPStan**

Run: `vendor/bin/pest tests/Unit/Scaling/Calculators/LinearRegressionForecasterTest.php && vendor/bin/phpstan analyse src/Scaling/Calculators/LinearRegressionForecaster.php --level=9 --no-progress`
Expected: all PASS + `[OK] No errors`

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty
git add src/Scaling/Calculators/LinearRegressionForecaster.php tests/Unit/Scaling/Calculators/LinearRegressionForecasterTest.php
git commit -m "feat(v2): add LinearRegressionForecaster with R² confidence"
```

---

## Task 4: Implement the four forecast policy classes

**Files:**
- Create: `src/Scaling/Forecasting/Policies/DisabledForecastPolicy.php`
- Create: `src/Scaling/Forecasting/Policies/HintForecastPolicy.php`
- Create: `src/Scaling/Forecasting/Policies/ModerateForecastPolicy.php`
- Create: `src/Scaling/Forecasting/Policies/AggressiveForecastPolicy.php`
- Create: `tests/Unit/Scaling/Forecasting/ForecastPolicyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\AggressiveForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\DisabledForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\HintForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

test('disabled policy sets min R² above 1 so forecast is never trusted', function (): void {
    $policy = new DisabledForecastPolicy();

    expect($policy->minRSquared())->toBeGreaterThan(1.0)
        ->and($policy->forecastWeight())->toBe(0.0);
});

test('hint policy requires strong fit and uses small forecast weight', function (): void {
    $policy = new HintForecastPolicy();

    expect($policy->minRSquared())->toBe(0.8)
        ->and($policy->forecastWeight())->toBe(0.3);
});

test('moderate policy is balanced', function (): void {
    $policy = new ModerateForecastPolicy();

    expect($policy->minRSquared())->toBe(0.6)
        ->and($policy->forecastWeight())->toBe(0.5);
});

test('aggressive policy trusts forecast with noise', function (): void {
    $policy = new AggressiveForecastPolicy();

    expect($policy->minRSquared())->toBe(0.4)
        ->and($policy->forecastWeight())->toBe(0.8);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Scaling/Forecasting/ForecastPolicyTest.php`
Expected: FAIL

- [ ] **Step 3: Implement all four policies**

`src/Scaling/Forecasting/Policies/DisabledForecastPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

final readonly class DisabledForecastPolicy implements ForecastPolicyContract
{
    public function minRSquared(): float
    {
        return 1.1;
    }

    public function forecastWeight(): float
    {
        return 0.0;
    }
}
```

`src/Scaling/Forecasting/Policies/HintForecastPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

final readonly class HintForecastPolicy implements ForecastPolicyContract
{
    public function minRSquared(): float
    {
        return 0.8;
    }

    public function forecastWeight(): float
    {
        return 0.3;
    }
}
```

`src/Scaling/Forecasting/Policies/ModerateForecastPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

final readonly class ModerateForecastPolicy implements ForecastPolicyContract
{
    public function minRSquared(): float
    {
        return 0.6;
    }

    public function forecastWeight(): float
    {
        return 0.5;
    }
}
```

`src/Scaling/Forecasting/Policies/AggressiveForecastPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies;

use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;

final readonly class AggressiveForecastPolicy implements ForecastPolicyContract
{
    public function minRSquared(): float
    {
        return 0.4;
    }

    public function forecastWeight(): float
    {
        return 0.8;
    }
}
```

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Scaling/Forecasting/ForecastPolicyTest.php && vendor/bin/phpstan analyse src/Scaling/Forecasting --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Scaling/Forecasting/Policies/ tests/Unit/Scaling/Forecasting/ForecastPolicyTest.php
git commit -m "feat(v2): add four forecast policy implementations"
```

---

## Task 5: Implement `SortBasedPercentileCalculator`

**Files:**
- Create: `src/Pickup/SortBasedPercentileCalculator.php`
- Create: `tests/Unit/Pickup/SortBasedPercentileCalculatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;

test('returns null when fewer than 20 samples', function (): void {
    $calc = new SortBasedPercentileCalculator();

    expect($calc->compute([1.0, 2.0, 3.0], 95))->toBeNull();
});

test('computes p95 correctly on 100-value range', function (): void {
    $calc = new SortBasedPercentileCalculator();
    $values = [];
    for ($i = 1; $i <= 100; $i++) {
        $values[] = (float) $i;
    }

    $p95 = $calc->compute($values, 95);

    expect($p95)->toBe(95.0);
});

test('computes p50 as median', function (): void {
    $calc = new SortBasedPercentileCalculator();
    $values = [];
    for ($i = 1; $i <= 100; $i++) {
        $values[] = (float) $i;
    }

    expect($calc->compute($values, 50))->toBe(50.0);
});

test('computes p99 near top of range', function (): void {
    $calc = new SortBasedPercentileCalculator();
    $values = [];
    for ($i = 1; $i <= 100; $i++) {
        $values[] = (float) $i;
    }

    expect($calc->compute($values, 99))->toBe(99.0);
});

test('unsorted input is handled correctly', function (): void {
    $calc = new SortBasedPercentileCalculator();
    $values = [];
    for ($i = 100; $i >= 1; $i--) {
        $values[] = (float) $i;
    }

    expect($calc->compute($values, 95))->toBe(95.0);
});

test('all-same values return that value', function (): void {
    $calc = new SortBasedPercentileCalculator();
    $values = array_fill(0, 30, 7.5);

    expect($calc->compute($values, 95))->toBe(7.5);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Pickup/SortBasedPercentileCalculatorTest.php`
Expected: FAIL

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;

final class SortBasedPercentileCalculator implements PercentileCalculatorContract
{
    private const MIN_SAMPLES = 20;

    public function compute(array $values, int $percentile): ?float
    {
        $count = count($values);

        if ($count < self::MIN_SAMPLES) {
            return null;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return $values[$index];
    }
}
```

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Pickup/SortBasedPercentileCalculatorTest.php && vendor/bin/phpstan analyse src/Pickup --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Pickup/SortBasedPercentileCalculator.php tests/Unit/Pickup/SortBasedPercentileCalculatorTest.php
git commit -m "feat(v2): add SortBasedPercentileCalculator"
```

---

## Task 6: Implement `RedisPickupTimeStore`

**Files:**
- Create: `src/Pickup/RedisPickupTimeStore.php`
- Create: `tests/Unit/Pickup/RedisPickupTimeStoreTest.php`

The tests use an in-memory Redis fake (`Illuminate\Redis\Connections\PredisConnection` or array-driven mock). Look at existing tests in the repo that interact with Redis for the exact fake style — likely `Illuminate\Support\Facades\Redis::shouldReceive(...)` or similar. If no existing pattern found, use `Redis::fake()` via `laravel/framework`'s test helpers.

- [ ] **Step 1: Find existing Redis testing patterns**

Run: `vendor/bin/pest --filter=Redis 2>&1 | head -30`
Look at any matching test file to mimic its mocking style. Alternative: `grep -r "Redis::" tests/ src/` to see current conventions.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::flushall();
});

test('records and retrieves pickup samples in order', function (): void {
    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    $store->record('redis', 'default', 1000.0, 1.5);
    $store->record('redis', 'default', 1001.0, 2.0);

    $samples = $store->recentSamples('redis', 'default', 60);

    expect($samples)->toHaveCount(2);
    expect(array_column($samples, 'pickup_seconds'))->toContain(1.5, 2.0);
});

test('caps storage at max_samples_per_queue', function (): void {
    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 3);

    for ($i = 0; $i < 5; $i++) {
        $store->record('redis', 'default', 1000.0 + $i, (float) $i);
    }

    $samples = $store->recentSamples('redis', 'default', 60);
    expect($samples)->toHaveCount(3);
});

test('filters samples outside window', function (): void {
    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);
    $now = (float) time();

    $store->record('redis', 'default', $now - 500, 1.0);   // outside 60s window
    $store->record('redis', 'default', $now - 30, 2.0);    // inside window
    $store->record('redis', 'default', $now, 3.0);         // inside window

    $samples = $store->recentSamples('redis', 'default', 60);

    expect($samples)->toHaveCount(2);
    expect(array_column($samples, 'pickup_seconds'))->toContain(2.0, 3.0);
});

test('returns empty list for queue with no recorded samples', function (): void {
    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    expect($store->recentSamples('redis', 'empty', 60))->toBe([]);
});

test('different queues have isolated storage', function (): void {
    $store = new RedisPickupTimeStore(maxSamplesPerQueue: 100);

    $store->record('redis', 'a', 1000.0, 1.0);
    $store->record('redis', 'b', 1000.0, 2.0);

    expect($store->recentSamples('redis', 'a', 60))->toHaveCount(1);
    expect($store->recentSamples('redis', 'b', 60))->toHaveCount(1);
});
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Pickup/RedisPickupTimeStoreTest.php`
Expected: FAIL

- [ ] **Step 4: Implement**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Illuminate\Support\Facades\Redis;

final class RedisPickupTimeStore implements PickupTimeStoreContract
{
    public function __construct(
        private readonly int $maxSamplesPerQueue = 1000,
    ) {}

    public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
    {
        $key = $this->key($connection, $queue);
        $entry = sprintf('%.6f|%.6f', $timestamp, $pickupSeconds);

        Redis::pipeline(function ($pipe) use ($key, $entry): void {
            $pipe->lpush($key, $entry);
            $pipe->ltrim($key, 0, $this->maxSamplesPerQueue - 1);
        });
    }

    public function recentSamples(string $connection, string $queue, int $windowSeconds): array
    {
        $key = $this->key($connection, $queue);
        /** @var list<string> $entries */
        $entries = Redis::lrange($key, 0, -1);
        $cutoff = microtime(true) - $windowSeconds;

        $samples = [];
        foreach ($entries as $entry) {
            $parts = explode('|', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $ts = (float) $parts[0];
            if ($ts < $cutoff) {
                continue;
            }

            $samples[] = [
                'timestamp' => $ts,
                'pickup_seconds' => (float) $parts[1],
            ];
        }

        return $samples;
    }

    private function key(string $connection, string $queue): string
    {
        return sprintf('autoscale:pickup:%s:%s', $connection, $queue);
    }
}
```

- [ ] **Step 5: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Pickup/RedisPickupTimeStoreTest.php && vendor/bin/phpstan analyse src/Pickup/RedisPickupTimeStore.php --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 6: Commit**

```bash
git add src/Pickup/RedisPickupTimeStore.php tests/Unit/Pickup/RedisPickupTimeStoreTest.php
git commit -m "feat(v2): add RedisPickupTimeStore with sliding window"
```

---

## Task 7: Implement `PickupTimeRecorder` event listener

**Files:**
- Create: `src/Pickup/PickupTimeRecorder.php`
- Create: `tests/Unit/Pickup/PickupTimeRecorderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

test('records pickup time derived from payload pushedAt', function (): void {
    $recorded = [];
    $store = new class($recorded) implements PickupTimeStoreContract {
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };

    $recorder = new PickupTimeRecorder($store);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['pushedAt' => microtime(true) - 2.0]);
    $job->shouldReceive('getQueue')->andReturn('default');

    $recorder->handle(new JobProcessing('redis', $job));

    expect($recorded)->toHaveCount(1);
    expect($recorded[0]['connection'])->toBe('redis');
    expect($recorded[0]['queue'])->toBe('default');
    expect($recorded[0]['pickupSeconds'])->toBeGreaterThan(1.9)->toBeLessThan(2.1);
});

test('silently skips when pushedAt is absent from payload', function (): void {
    $recorded = [];
    $store = new class($recorded) implements PickupTimeStoreContract {
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };

    $recorder = new PickupTimeRecorder($store);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['id' => 'abc']);
    $job->shouldReceive('getQueue')->andReturn('default');

    $recorder->handle(new JobProcessing('redis', $job));

    expect($recorded)->toHaveCount(0);
});

test('uses default queue name when none provided', function (): void {
    $recorded = [];
    $store = new class($recorded) implements PickupTimeStoreContract {
        public function __construct(private array &$recorded) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void
        {
            $this->recorded[] = compact('connection', 'queue', 'timestamp', 'pickupSeconds');
        }

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return [];
        }
    };

    $recorder = new PickupTimeRecorder($store);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['pushedAt' => microtime(true) - 1.0]);
    $job->shouldReceive('getQueue')->andReturn(null);

    $recorder->handle(new JobProcessing('redis', $job));

    expect($recorded[0]['queue'])->toBe('default');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Pickup/PickupTimeRecorderTest.php`
Expected: FAIL

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Pickup;

use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Illuminate\Queue\Events\JobProcessing;

final class PickupTimeRecorder
{
    public function __construct(
        private readonly PickupTimeStoreContract $store,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $pushedAt = $payload['pushedAt'] ?? null;

        if (! is_numeric($pushedAt)) {
            return;
        }

        $now = microtime(true);
        $pickupSeconds = max(0.0, $now - (float) $pushedAt);
        $queue = $event->job->getQueue() ?? 'default';

        $this->store->record(
            connection: $event->connectionName,
            queue: $queue,
            timestamp: $now,
            pickupSeconds: $pickupSeconds,
        );
    }
}
```

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Pickup/PickupTimeRecorderTest.php && vendor/bin/phpstan analyse src/Pickup/PickupTimeRecorder.php --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Pickup/PickupTimeRecorder.php tests/Unit/Pickup/PickupTimeRecorderTest.php
git commit -m "feat(v2): add PickupTimeRecorder event listener"
```

---

## Task 8: Implement `EmaSpawnLatencyTracker`

**Files:**
- Create: `src/Workers/SpawnLatency/EmaSpawnLatencyTracker.php`
- Create: `tests/Unit/Workers/SpawnLatency/EmaSpawnLatencyTrackerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::flushall();
});

test('returns fallback when fewer than 5 samples recorded', function (): void {
    $tracker = new EmaSpawnLatencyTracker(fallbackSeconds: 2.5, minSamples: 5, alpha: 0.2);

    expect($tracker->currentLatency('redis', 'default'))->toBe(2.5);
});

test('converges toward true latency after multiple samples', function (): void {
    $tracker = new EmaSpawnLatencyTracker(fallbackSeconds: 10.0, minSamples: 5, alpha: 0.2);

    for ($i = 0; $i < 20; $i++) {
        $tracker->recordSpawn("w{$i}", 'redis', 'default');
        // Simulate 1.0 second spawn latency each time
        $spawnTs = (float) time() - 1.0;
        Redis::set("autoscale:spawn:pending:w{$i}", json_encode([
            'ts' => $spawnTs,
            'connection' => 'redis',
            'queue' => 'default',
        ]));
        $tracker->recordFirstPickup("w{$i}", $spawnTs + 1.0);
    }

    $latency = $tracker->currentLatency('redis', 'default');
    expect($latency)->toBeGreaterThan(0.9)->toBeLessThan(1.1);
});

test('ignores pickup for unknown worker id', function (): void {
    $tracker = new EmaSpawnLatencyTracker(fallbackSeconds: 2.5, minSamples: 5, alpha: 0.2);

    $tracker->recordFirstPickup('nonexistent-worker', microtime(true));

    // Count must still be zero → fallback applies.
    expect($tracker->currentLatency('redis', 'default'))->toBe(2.5);
});

test('clamps extreme latencies into safe bounds', function (): void {
    $tracker = new EmaSpawnLatencyTracker(fallbackSeconds: 2.5, minSamples: 1, alpha: 1.0);

    for ($i = 0; $i < 3; $i++) {
        $tracker->recordSpawn("w{$i}", 'redis', 'default');
        $spawnTs = (float) time() - 500;   // absurdly long "spawn"
        Redis::set("autoscale:spawn:pending:w{$i}", json_encode([
            'ts' => $spawnTs,
            'connection' => 'redis',
            'queue' => 'default',
        ]));
        $tracker->recordFirstPickup("w{$i}", $spawnTs + 500);
    }

    // Must be clamped to upper bound (30s per spec).
    expect($tracker->currentLatency('redis', 'default'))->toBeLessThanOrEqual(30.0);
});

test('isolates queues from one another', function (): void {
    $tracker = new EmaSpawnLatencyTracker(fallbackSeconds: 2.5, minSamples: 5, alpha: 0.2);

    for ($i = 0; $i < 10; $i++) {
        $tracker->recordSpawn("a{$i}", 'redis', 'queue-a');
        $spawnA = (float) time() - 1.0;
        Redis::set("autoscale:spawn:pending:a{$i}", json_encode([
            'ts' => $spawnA,
            'connection' => 'redis',
            'queue' => 'queue-a',
        ]));
        $tracker->recordFirstPickup("a{$i}", $spawnA + 1.0);
    }

    // queue-b has no samples → fallback
    expect($tracker->currentLatency('redis', 'queue-b'))->toBe(2.5);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Workers/SpawnLatency/EmaSpawnLatencyTrackerTest.php`
Expected: FAIL

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers\SpawnLatency;

use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Illuminate\Support\Facades\Redis;

final class EmaSpawnLatencyTracker implements SpawnLatencyTrackerContract
{
    private const MIN_LATENCY = 0.1;
    private const MAX_LATENCY = 30.0;
    private const PENDING_TTL = 300;

    public function __construct(
        private readonly float $fallbackSeconds = 2.0,
        private readonly int $minSamples = 5,
        private readonly float $alpha = 0.2,
    ) {}

    public function recordSpawn(string $workerId, string $connection, string $queue): void
    {
        $payload = json_encode([
            'ts' => microtime(true),
            'connection' => $connection,
            'queue' => $queue,
        ], JSON_THROW_ON_ERROR);

        Redis::setex($this->pendingKey($workerId), self::PENDING_TTL, $payload);
    }

    public function recordFirstPickup(string $workerId, float $pickupTimestamp): void
    {
        $raw = Redis::get($this->pendingKey($workerId));

        if (! is_string($raw)) {
            return;
        }

        /** @var array{ts: float, connection: string, queue: string} $payload */
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);

        $rawLatency = $pickupTimestamp - (float) $payload['ts'];
        $latency = max(self::MIN_LATENCY, min($rawLatency, self::MAX_LATENCY));

        $emaKey = $this->emaKey($payload['connection'], $payload['queue']);
        $countKey = $this->countKey($payload['connection'], $payload['queue']);

        $currentEma = Redis::get($emaKey);
        $newEma = is_numeric($currentEma)
            ? ($this->alpha * $latency) + ((1 - $this->alpha) * (float) $currentEma)
            : $latency;

        Redis::set($emaKey, (string) $newEma);
        Redis::incr($countKey);
        Redis::del($this->pendingKey($workerId));
    }

    public function currentLatency(string $connection, string $queue): float
    {
        $count = (int) (Redis::get($this->countKey($connection, $queue)) ?? 0);

        if ($count < $this->minSamples) {
            return $this->fallbackSeconds;
        }

        $ema = Redis::get($this->emaKey($connection, $queue));

        return is_numeric($ema) ? (float) $ema : $this->fallbackSeconds;
    }

    private function pendingKey(string $workerId): string
    {
        return sprintf('autoscale:spawn:pending:%s', $workerId);
    }

    private function emaKey(string $connection, string $queue): string
    {
        return sprintf('autoscale:spawn:ema:%s:%s', $connection, $queue);
    }

    private function countKey(string $connection, string $queue): string
    {
        return sprintf('autoscale:spawn:count:%s:%s', $connection, $queue);
    }
}
```

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Workers/SpawnLatency/EmaSpawnLatencyTrackerTest.php && vendor/bin/phpstan analyse src/Workers/SpawnLatency --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Workers/SpawnLatency/ tests/Unit/Workers/SpawnLatency/
git commit -m "feat(v2): add EmaSpawnLatencyTracker with Redis-backed EMA"
```

---

## Task 9: Configuration value objects (`SlaConfiguration`, `ForecastConfiguration`, `SpawnCompensationConfiguration`, `WorkerConfiguration`)

**Files:**
- Create: `src/Configuration/SlaConfiguration.php`
- Create: `src/Configuration/ForecastConfiguration.php`
- Create: `src/Configuration/SpawnCompensationConfiguration.php`
- Create: `src/Configuration/WorkerConfiguration.php`
- Create: `src/Configuration/InvalidConfigurationException.php`
- Create: `tests/Unit/Configuration/SlaConfigurationTest.php`
- Create: `tests/Unit/Configuration/ForecastConfigurationTest.php`
- Create: `tests/Unit/Configuration/SpawnCompensationConfigurationTest.php`
- Create: `tests/Unit/Configuration/WorkerConfigurationTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Configuration/SlaConfigurationTest.php`:
```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration;

test('constructs with valid values', function (): void {
    $sla = new SlaConfiguration(30, 95, 300, 20);

    expect($sla->targetSeconds)->toBe(30)
        ->and($sla->percentile)->toBe(95)
        ->and($sla->windowSeconds)->toBe(300)
        ->and($sla->minSamples)->toBe(20);
});

test('rejects percentile outside allowed values', function (): void {
    expect(fn () => new SlaConfiguration(30, 42, 300, 20))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects window shorter than 60 seconds', function (): void {
    expect(fn () => new SlaConfiguration(30, 95, 59, 20))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects non-positive target seconds', function (): void {
    expect(fn () => new SlaConfiguration(0, 95, 300, 20))
        ->toThrow(InvalidConfigurationException::class);
});
```

`tests/Unit/Configuration/ForecastConfigurationTest.php`:
```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

test('constructs with valid values', function (): void {
    $cfg = new ForecastConfiguration(
        forecasterClass: LinearRegressionForecaster::class,
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 60,
        historySeconds: 300,
    );

    expect($cfg->forecasterClass)->toBe(LinearRegressionForecaster::class);
});

test('rejects non-existent forecaster class', function (): void {
    expect(fn () => new ForecastConfiguration(
        forecasterClass: 'Nope\\NotAClass',
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 60,
        historySeconds: 300,
    ))->toThrow(InvalidConfigurationException::class);
});

test('rejects horizon <= 0', function (): void {
    expect(fn () => new ForecastConfiguration(
        forecasterClass: LinearRegressionForecaster::class,
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 0,
        historySeconds: 300,
    ))->toThrow(InvalidConfigurationException::class);
});

test('rejects history shorter than horizon', function (): void {
    expect(fn () => new ForecastConfiguration(
        forecasterClass: LinearRegressionForecaster::class,
        policyClass: ModerateForecastPolicy::class,
        horizonSeconds: 120,
        historySeconds: 60,
    ))->toThrow(InvalidConfigurationException::class);
});
```

`tests/Unit/Configuration/SpawnCompensationConfigurationTest.php`:
```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;

test('constructs with valid values', function (): void {
    $cfg = new SpawnCompensationConfiguration(true, 2.0, 5, 0.2);

    expect($cfg->enabled)->toBeTrue()
        ->and($cfg->fallbackSeconds)->toBe(2.0)
        ->and($cfg->minSamples)->toBe(5)
        ->and($cfg->emaAlpha)->toBe(0.2);
});

test('rejects alpha outside (0, 1]', function (): void {
    expect(fn () => new SpawnCompensationConfiguration(true, 2.0, 5, 1.5))
        ->toThrow(InvalidConfigurationException::class);

    expect(fn () => new SpawnCompensationConfiguration(true, 2.0, 5, 0.0))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects negative fallback', function (): void {
    expect(fn () => new SpawnCompensationConfiguration(true, -1.0, 5, 0.2))
        ->toThrow(InvalidConfigurationException::class);
});
```

`tests/Unit/Configuration/WorkerConfigurationTest.php`:
```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration;

test('constructs with valid values', function (): void {
    $cfg = new WorkerConfiguration(1, 10, 3, 3600, 3, 30);

    expect($cfg->min)->toBe(1)
        ->and($cfg->max)->toBe(10);
});

test('rejects min > max', function (): void {
    expect(fn () => new WorkerConfiguration(10, 5, 3, 3600, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects negative min', function (): void {
    expect(fn () => new WorkerConfiguration(-1, 10, 3, 3600, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});

test('rejects zero tries', function (): void {
    expect(fn () => new WorkerConfiguration(1, 10, 0, 3600, 3, 30))
        ->toThrow(InvalidConfigurationException::class);
});
```

- [ ] **Step 2: Run tests to verify failure**

Run: `vendor/bin/pest tests/Unit/Configuration`
Expected: FAIL

- [ ] **Step 3: Implement exception class**

`src/Configuration/InvalidConfigurationException.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use InvalidArgumentException;

final class InvalidConfigurationException extends InvalidArgumentException {}
```

- [ ] **Step 4: Implement `SlaConfiguration`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class SlaConfiguration
{
    private const ALLOWED_PERCENTILES = [50, 75, 90, 95, 99];

    public function __construct(
        public int $targetSeconds,
        public int $percentile,
        public int $windowSeconds,
        public int $minSamples,
    ) {
        if ($targetSeconds <= 0) {
            throw new InvalidConfigurationException("sla.target_seconds must be > 0, got {$targetSeconds}");
        }

        if (! in_array($percentile, self::ALLOWED_PERCENTILES, true)) {
            throw new InvalidConfigurationException(sprintf(
                'sla.percentile must be one of %s, got %d',
                implode(', ', self::ALLOWED_PERCENTILES),
                $percentile,
            ));
        }

        if ($windowSeconds < 60) {
            throw new InvalidConfigurationException("sla.window_seconds must be >= 60, got {$windowSeconds}");
        }

        if ($minSamples < 1) {
            throw new InvalidConfigurationException("sla.min_samples must be >= 1, got {$minSamples}");
        }
    }
}
```

- [ ] **Step 5: Implement `ForecastConfiguration`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;

final readonly class ForecastConfiguration
{
    /**
     * @param  class-string<ForecasterContract>  $forecasterClass
     * @param  class-string<ForecastPolicyContract>  $policyClass
     */
    public function __construct(
        public string $forecasterClass,
        public string $policyClass,
        public int $horizonSeconds,
        public int $historySeconds,
    ) {
        if (! class_exists($forecasterClass) || ! is_subclass_of($forecasterClass, ForecasterContract::class)) {
            throw new InvalidConfigurationException("forecast.forecaster must implement ForecasterContract: {$forecasterClass}");
        }

        if (! class_exists($policyClass) || ! is_subclass_of($policyClass, ForecastPolicyContract::class)) {
            throw new InvalidConfigurationException("forecast.policy must implement ForecastPolicyContract: {$policyClass}");
        }

        if ($horizonSeconds <= 0) {
            throw new InvalidConfigurationException("forecast.horizon_seconds must be > 0, got {$horizonSeconds}");
        }

        if ($historySeconds < $horizonSeconds) {
            throw new InvalidConfigurationException("forecast.history_seconds ({$historySeconds}) must be >= horizon_seconds ({$horizonSeconds})");
        }
    }
}
```

- [ ] **Step 6: Implement `SpawnCompensationConfiguration`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class SpawnCompensationConfiguration
{
    public function __construct(
        public bool $enabled,
        public float $fallbackSeconds,
        public int $minSamples,
        public float $emaAlpha,
    ) {
        if ($fallbackSeconds < 0.0) {
            throw new InvalidConfigurationException("spawn_compensation.fallback_seconds must be >= 0, got {$fallbackSeconds}");
        }

        if ($minSamples < 1) {
            throw new InvalidConfigurationException("spawn_compensation.min_samples must be >= 1, got {$minSamples}");
        }

        if ($emaAlpha <= 0.0 || $emaAlpha > 1.0) {
            throw new InvalidConfigurationException("spawn_compensation.ema_alpha must be in (0, 1], got {$emaAlpha}");
        }
    }
}
```

- [ ] **Step 7: Implement `WorkerConfiguration`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

final readonly class WorkerConfiguration
{
    public function __construct(
        public int $min,
        public int $max,
        public int $tries,
        public int $timeoutSeconds,
        public int $sleepSeconds,
        public int $shutdownTimeoutSeconds,
    ) {
        if ($min < 0) {
            throw new InvalidConfigurationException("workers.min must be >= 0, got {$min}");
        }

        if ($max < $min) {
            throw new InvalidConfigurationException("workers.max ({$max}) must be >= workers.min ({$min})");
        }

        if ($tries < 1) {
            throw new InvalidConfigurationException("workers.tries must be >= 1, got {$tries}");
        }

        if ($timeoutSeconds <= 0) {
            throw new InvalidConfigurationException("workers.timeout_seconds must be > 0, got {$timeoutSeconds}");
        }
    }
}
```

- [ ] **Step 8: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Configuration && vendor/bin/phpstan analyse src/Configuration --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 9: Commit**

```bash
git add src/Configuration/ tests/Unit/Configuration/
git commit -m "feat(v2): add configuration value objects with validation"
```

---

## Task 10: Implement the five profile classes

**Files:**
- Create: `src/Configuration/Profiles/CriticalProfile.php`
- Create: `src/Configuration/Profiles/HighVolumeProfile.php`
- Create: `src/Configuration/Profiles/BalancedProfile.php`
- Create: `src/Configuration/Profiles/BurstyProfile.php`
- Create: `src/Configuration/Profiles/BackgroundProfile.php`
- Create: `tests/Unit/Configuration/Profiles/ProfilesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BackgroundProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BurstyProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\HighVolumeProfile;
use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

test('all profiles implement ProfileContract', function (string $class): void {
    expect(new $class())->toBeInstanceOf(ProfileContract::class);
})->with([
    CriticalProfile::class,
    HighVolumeProfile::class,
    BalancedProfile::class,
    BurstyProfile::class,
    BackgroundProfile::class,
]);

test('all profiles return shape with required top-level keys', function (string $class): void {
    $resolved = (new $class())->resolve();

    expect($resolved)->toHaveKeys(['sla', 'forecast', 'workers', 'spawn_compensation']);
    expect($resolved['sla'])->toHaveKeys(['target_seconds', 'percentile', 'window_seconds', 'min_samples']);
    expect($resolved['forecast'])->toHaveKeys(['forecaster', 'policy', 'horizon_seconds', 'history_seconds']);
    expect($resolved['workers'])->toHaveKeys(['min', 'max', 'tries', 'timeout_seconds', 'sleep_seconds', 'shutdown_timeout_seconds']);
    expect($resolved['spawn_compensation'])->toHaveKeys(['enabled', 'fallback_seconds', 'min_samples', 'ema_alpha']);
})->with([
    CriticalProfile::class,
    HighVolumeProfile::class,
    BalancedProfile::class,
    BurstyProfile::class,
    BackgroundProfile::class,
]);

test('balanced profile uses p95 and 30s target', function (): void {
    $resolved = (new BalancedProfile())->resolve();
    expect($resolved['sla']['target_seconds'])->toBe(30);
    expect($resolved['sla']['percentile'])->toBe(95);
});

test('critical profile uses stricter SLA', function (): void {
    $resolved = (new CriticalProfile())->resolve();
    expect($resolved['sla']['target_seconds'])->toBeLessThanOrEqual(15);
    expect($resolved['sla']['percentile'])->toBeGreaterThanOrEqual(95);
});

test('background profile allows zero min workers', function (): void {
    $resolved = (new BackgroundProfile())->resolve();
    expect($resolved['workers']['min'])->toBe(0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Configuration/Profiles/ProfilesTest.php`
Expected: FAIL

- [ ] **Step 3: Implement all five profiles**

`src/Configuration/Profiles/BalancedProfile.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

final readonly class BalancedProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 30,
                'percentile' => 95,
                'window_seconds' => 300,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => ModerateForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 1,
                'max' => 10,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 3,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
```

`src/Configuration/Profiles/CriticalProfile.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\AggressiveForecastPolicy;

final readonly class CriticalProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 10,
                'percentile' => 99,
                'window_seconds' => 120,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => AggressiveForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 5,
                'max' => 50,
                'tries' => 5,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 1,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 3,
                'ema_alpha' => 0.3,
            ],
        ];
    }
}
```

`src/Configuration/Profiles/HighVolumeProfile.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

final readonly class HighVolumeProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 20,
                'percentile' => 95,
                'window_seconds' => 300,
                'min_samples' => 50,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => ModerateForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 3,
                'max' => 40,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 2,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
```

`src/Configuration/Profiles/BurstyProfile.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\AggressiveForecastPolicy;

final readonly class BurstyProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 60,
                'percentile' => 90,
                'window_seconds' => 600,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => AggressiveForecastPolicy::class,
                'horizon_seconds' => 120,
                'history_seconds' => 600,
            ],
            'workers' => [
                'min' => 0,
                'max' => 100,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 3,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
```

`src/Configuration/Profiles/BackgroundProfile.php`:
```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\HintForecastPolicy;

final readonly class BackgroundProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => 300,
                'percentile' => 95,
                'window_seconds' => 900,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => HintForecastPolicy::class,
                'horizon_seconds' => 300,
                'history_seconds' => 900,
            ],
            'workers' => [
                'min' => 0,
                'max' => 5,
                'tries' => 3,
                'timeout_seconds' => 3600,
                'sleep_seconds' => 10,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 3.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
        ];
    }
}
```

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Configuration/Profiles && vendor/bin/phpstan analyse src/Configuration/Profiles --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Configuration/Profiles/ tests/Unit/Configuration/Profiles/
git commit -m "feat(v2): add five profile classes replacing ProfilePresets"
```

---

## Task 11: Rewrite `QueueConfiguration` for v2 composition

**Files:**
- Modify: `src/Configuration/QueueConfiguration.php`
- Create: `tests/Unit/Configuration/QueueConfigurationTest.php`
- Delete: `src/Configuration/ProfilePresets.php` (done at end of task)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

beforeEach(function (): void {
    config([
        'queue-autoscale.sla_defaults' => BalancedProfile::class,
        'queue-autoscale.queues' => [
            'payments' => \Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile::class,
            'custom' => ['sla' => ['target_seconds' => 45]],
        ],
    ]);
});

test('falls back to default profile when queue not configured', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'unknown');

    expect($cfg->sla->targetSeconds)->toBe(30);
    expect($cfg->sla->percentile)->toBe(95);
});

test('uses per-queue profile class when configured', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'payments');

    expect($cfg->sla->targetSeconds)->toBe(10);
    expect($cfg->sla->percentile)->toBe(99);
});

test('deep merges array override on top of default profile', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'custom');

    // target_seconds overridden, rest inherited from balanced.
    expect($cfg->sla->targetSeconds)->toBe(45);
    expect($cfg->sla->percentile)->toBe(95);
    expect($cfg->workers->max)->toBe(10);
});

test('exposes all nested configuration value objects', function (): void {
    $cfg = QueueConfiguration::fromConfig('redis', 'default');

    expect($cfg->connection)->toBe('redis')
        ->and($cfg->queue)->toBe('default')
        ->and($cfg->sla->targetSeconds)->toBe(30)
        ->and($cfg->forecast->horizonSeconds)->toBe(60)
        ->and($cfg->workers->min)->toBe(1)
        ->and($cfg->spawnCompensation->enabled)->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Configuration/QueueConfigurationTest.php`
Expected: FAIL (old QueueConfiguration does not have new shape)

- [ ] **Step 3: Rewrite `QueueConfiguration`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

final readonly class QueueConfiguration
{
    public function __construct(
        public string $connection,
        public string $queue,
        public SlaConfiguration $sla,
        public ForecastConfiguration $forecast,
        public SpawnCompensationConfiguration $spawnCompensation,
        public WorkerConfiguration $workers,
    ) {}

    public static function fromConfig(string $connection, string $queue): self
    {
        $defaults = self::resolveProfileOrArray(config('queue-autoscale.sla_defaults'));
        $override = config("queue-autoscale.queues.{$queue}", []);

        $overrideArray = self::resolveProfileOrArray($override);
        $merged = self::deepMerge($defaults, $overrideArray);

        return new self(
            connection: $connection,
            queue: $queue,
            sla: new SlaConfiguration(
                targetSeconds: (int) $merged['sla']['target_seconds'],
                percentile: (int) $merged['sla']['percentile'],
                windowSeconds: (int) $merged['sla']['window_seconds'],
                minSamples: (int) $merged['sla']['min_samples'],
            ),
            forecast: new ForecastConfiguration(
                forecasterClass: (string) $merged['forecast']['forecaster'],
                policyClass: (string) $merged['forecast']['policy'],
                horizonSeconds: (int) $merged['forecast']['horizon_seconds'],
                historySeconds: (int) $merged['forecast']['history_seconds'],
            ),
            spawnCompensation: new SpawnCompensationConfiguration(
                enabled: (bool) $merged['spawn_compensation']['enabled'],
                fallbackSeconds: (float) $merged['spawn_compensation']['fallback_seconds'],
                minSamples: (int) $merged['spawn_compensation']['min_samples'],
                emaAlpha: (float) $merged['spawn_compensation']['ema_alpha'],
            ),
            workers: new WorkerConfiguration(
                min: (int) $merged['workers']['min'],
                max: (int) $merged['workers']['max'],
                tries: (int) $merged['workers']['tries'],
                timeoutSeconds: (int) $merged['workers']['timeout_seconds'],
                sleepSeconds: (int) $merged['workers']['sleep_seconds'],
                shutdownTimeoutSeconds: (int) $merged['workers']['shutdown_timeout_seconds'],
            ),
        );
    }

    /**
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private static function resolveProfileOrArray(mixed $value): array
    {
        if (is_string($value) && class_exists($value) && is_subclass_of($value, ProfileContract::class)) {
            /** @var ProfileContract $instance */
            $instance = new $value();

            return $instance->resolve();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
```

- [ ] **Step 4: Find and update all `QueueConfiguration` consumers**

Run: `grep -rn "maxPickupTimeSeconds\|minWorkers\|maxWorkers\|scaleCooldownSeconds" src/ tests/`

Each reference points to a consumer that used v1 properties directly. Replace with their v2 equivalents:
- `$config->maxPickupTimeSeconds` → `$config->sla->targetSeconds`
- `$config->minWorkers` → `$config->workers->min`
- `$config->maxWorkers` → `$config->workers->max`
- `$config->scaleCooldownSeconds` → read from global config `queue-autoscale.scaling.cooldown_seconds` (moved to global)

For each match, edit the file to use the new accessor. These changes are necessary for the test suite to pass.

- [ ] **Step 5: Delete old `ProfilePresets`**

```bash
git rm src/Configuration/ProfilePresets.php
```

- [ ] **Step 6: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Configuration && vendor/bin/phpstan analyse --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors` (fix any call sites you missed)

- [ ] **Step 7: Commit**

```bash
git add -u src/ tests/
git commit -m "feat(v2)!: rewrite QueueConfiguration with composed value objects

BREAKING: QueueConfiguration no longer exposes maxPickupTimeSeconds,
minWorkers, maxWorkers, scaleCooldownSeconds directly. Use
\$config->sla, \$config->workers, or global config('queue-autoscale.scaling.cooldown_seconds').
ProfilePresets removed; use Profile classes instead."
```

---

## Task 12: Update `ArrivalRateEstimator` to blend forecasts

**Files:**
- Modify: `src/Scaling/Calculators/ArrivalRateEstimator.php`
- Create: `tests/Unit/Scaling/Calculators/ArrivalRateEstimatorForecastTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

test('falls back to observed rate when forecast has low R²', function (): void {
    $forecaster = new class implements ForecasterContract {
        public function forecast(array $history, int $horizonSeconds): ForecastResult
        {
            return new ForecastResult(100.0, 0.1, 5.0, 10, true);   // R² below moderate threshold 0.6
        }
    };

    $estimator = new ArrivalRateEstimator();
    $estimator->setForecaster($forecaster, new ModerateForecastPolicy(), horizonSeconds: 60);

    // Prime with two snapshots to get a credible observed rate.
    $estimator->estimate('redis:default', 10, 2.0);
    usleep(1_500_000);
    $result = $estimator->estimate('redis:default', 12, 2.0);

    // Forecast should be rejected; observed rate (~2/s) returned.
    expect($result['rate'])->toBeLessThan(50.0);
});

test('blends forecast into observed rate when R² passes threshold', function (): void {
    $forecaster = new class implements ForecasterContract {
        public function forecast(array $history, int $horizonSeconds): ForecastResult
        {
            return new ForecastResult(100.0, 0.9, 5.0, 10, true);   // high R², will be trusted
        }
    };

    $estimator = new ArrivalRateEstimator();
    $estimator->setForecaster($forecaster, new ModerateForecastPolicy(), horizonSeconds: 60);

    $estimator->estimate('redis:default', 10, 2.0);
    usleep(1_500_000);
    $result = $estimator->estimate('redis:default', 12, 2.0);

    // Moderate = 50/50 blend. Expect between observed and 100.
    expect($result['rate'])->toBeGreaterThan(10.0)->toBeLessThan(100.0);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Scaling/Calculators/ArrivalRateEstimatorForecastTest.php`
Expected: FAIL (method setForecaster does not exist)

- [ ] **Step 3: Update `ArrivalRateEstimator`**

Add these changes to the existing file (reference: `src/Scaling/Calculators/ArrivalRateEstimator.php`). Locate the class declaration and:

1. Change `MAX_HISTORY_AGE` from `60.0` to `300.0` (support 5-minute window for forecasting).
2. Change `MAX_SNAPSHOTS` from `5` to `30` (enough data points for regression).
3. Add the forecaster fields and setter:

```php
// Near the top of the class, after private $history
private ?ForecasterContract $forecaster = null;
private ?ForecastPolicyContract $policy = null;
private int $forecastHorizonSeconds = 60;

public function setForecaster(
    ForecasterContract $forecaster,
    ForecastPolicyContract $policy,
    int $horizonSeconds,
): void {
    $this->forecaster = $forecaster;
    $this->policy = $policy;
    $this->forecastHorizonSeconds = $horizonSeconds;
}

public function hasForecaster(): bool
{
    return $this->forecaster !== null;
}
```

4. At the end of `estimate()`, before `return`, add forecast blending:

```php
$observedRate = $arrivalRate;

if ($this->forecaster !== null && $this->policy !== null) {
    $blended = $this->maybeBlendForecast($snapshots, $processingRate, $observedRate);
    if ($blended !== null) {
        $arrivalRate = $blended['rate'];
        $result = [
            'rate' => $blended['rate'],
            'confidence' => $confidence,
            'source' => sprintf(
                'forecast_blended: observed=%.2f/s forecast=%.2f/s R²=%.2f',
                $observedRate,
                $blended['forecast'],
                $blended['r_squared'],
            ),
        ];

        return $result;
    }
}
```

5. Add the helper method:

```php
/**
 * @param  list<array{backlog: int, timestamp: float}>  $snapshots
 * @return array{rate: float, forecast: float, r_squared: float}|null
 */
private function maybeBlendForecast(array $snapshots, float $processingRate, float $observedRate): ?array
{
    if ($this->forecaster === null || $this->policy === null) {
        return null;
    }

    if (count($snapshots) < 5) {
        return null;
    }

    // Convert snapshots to (timestamp, rate) pairs using backlog growth.
    $history = [];
    for ($i = 1; $i < count($snapshots); $i++) {
        $interval = $snapshots[$i]['timestamp'] - $snapshots[$i - 1]['timestamp'];
        if ($interval < 0.001) {
            continue;
        }
        $growth = ($snapshots[$i]['backlog'] - $snapshots[$i - 1]['backlog']) / $interval;
        $history[] = [
            'timestamp' => $snapshots[$i]['timestamp'],
            'rate' => max(0.0, $processingRate + $growth),
        ];
    }

    $forecast = $this->forecaster->forecast($history, $this->forecastHorizonSeconds);

    if (! $forecast->hasSufficientData || $forecast->rSquared < $this->policy->minRSquared()) {
        return null;
    }

    $weight = $this->policy->forecastWeight();
    $blended = ($weight * $forecast->projectedRate) + ((1 - $weight) * $observedRate);

    return [
        'rate' => max(0.0, $blended),
        'forecast' => $forecast->projectedRate,
        'r_squared' => $forecast->rSquared,
    ];
}
```

6. Add imports at top:

```php
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
```

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Scaling/Calculators && vendor/bin/phpstan analyse src/Scaling/Calculators --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Scaling/Calculators/ArrivalRateEstimator.php tests/Unit/Scaling/Calculators/ArrivalRateEstimatorForecastTest.php
git commit -m "feat(v2): blend forecast into ArrivalRateEstimator with R² gating"
```

---

## Task 13: Update `BacklogDrainCalculator` to accept effective SLA

**Files:**
- Modify: `src/Scaling/Calculators/BacklogDrainCalculator.php`
- Modify: existing `tests/Unit/Scaling/Calculators/BacklogDrainCalculatorTest.php` (update if present, or add cases)

- [ ] **Step 1: Read the current `BacklogDrainCalculator` signature**

Run: `cat src/Scaling/Calculators/BacklogDrainCalculator.php | head -30`

The current method likely takes `slaTarget` as an `int`. We want a new parameter `effectiveSlaSeconds` (float, since spawn latency is subtracted).

- [ ] **Step 2: Write the failing test**

Add to the existing test file (or create):
```php
test('uses effective SLA to compute workers when provided', function (): void {
    $calc = new BacklogDrainCalculator();

    // With raw SLA of 30s, effective 28s (after 2s spawn latency), backlog of 100,
    // avg job 1.5s, rate 2.0/s → expected a higher worker count than with raw SLA.
    $result = $calc->calculateRequiredWorkers(
        backlog: 100,
        oldestJobAge: 5,
        slaTarget: 30,
        avgJobTime: 1.5,
        breachThreshold: 0.5,
        effectiveSlaSeconds: 28.0,
    );

    expect($result)->toBeGreaterThan(0);
});
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Scaling/Calculators/BacklogDrainCalculatorTest.php`
Expected: FAIL (new parameter not supported)

- [ ] **Step 4: Update `BacklogDrainCalculator`**

Find the signature of `calculateRequiredWorkers` in the file. Add a new optional parameter `effectiveSlaSeconds` with default `null`. Inside, if the parameter is provided and differs from `slaTarget`, use it everywhere the method previously used `slaTarget` as the SLA budget.

Pattern:
```php
public function calculateRequiredWorkers(
    int $backlog,
    int $oldestJobAge,
    int $slaTarget,
    float $avgJobTime,
    float $breachThreshold = 0.5,
    ?float $effectiveSlaSeconds = null,
): int {
    $effectiveSla = $effectiveSlaSeconds ?? (float) $slaTarget;
    // ... rest of method uses $effectiveSla in place of $slaTarget for calculations.
}
```

Keep `$slaTarget` as the public interface and use `$effectiveSla` internally. This preserves backward compat for any call sites that pass only 5 arguments.

- [ ] **Step 5: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Scaling/Calculators && vendor/bin/phpstan analyse src/Scaling --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 6: Commit**

```bash
git add src/Scaling/Calculators/BacklogDrainCalculator.php tests/Unit/Scaling/Calculators/BacklogDrainCalculatorTest.php
git commit -m "feat(v2): BacklogDrainCalculator accepts effective SLA (post-spawn-compensation)"
```

---

## Task 14: Create `HybridStrategy` (rename + refactor `PredictiveStrategy`)

**Files:**
- Create: `src/Scaling/Strategies/HybridStrategy.php`
- Create: `tests/Unit/Scaling/Strategies/HybridStrategyTest.php`
- Delete: `src/Scaling/Strategies/PredictiveStrategy.php` (at end of task)

This is the integration point. `HybridStrategy` wires together forecaster, spawn latency tracker, pickup store, percentile calculator, Little's Law, and backlog drain.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

function makeConfig(): QueueConfiguration
{
    config(['queue-autoscale.sla_defaults' => BalancedProfile::class, 'queue-autoscale.queues' => []]);

    return QueueConfiguration::fromConfig('redis', 'default');
}

function fakeSpawnTracker(float $latency): SpawnLatencyTrackerContract
{
    return new class($latency) implements SpawnLatencyTrackerContract {
        public function __construct(private readonly float $latency) {}

        public function recordSpawn(string $workerId, string $connection, string $queue): void {}

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue): float
        {
            return $this->latency;
        }
    };
}

function fakePickupStore(array $samples): PickupTimeStoreContract
{
    return new class($samples) implements PickupTimeStoreContract {
        public function __construct(private readonly array $samples) {}

        public function record(string $connection, string $queue, float $timestamp, float $pickupSeconds): void {}

        public function recentSamples(string $connection, string $queue, int $windowSeconds): array
        {
            return $this->samples;
        }
    };
}

function fakeForecaster(ForecastResult $result): ForecasterContract
{
    return new class($result) implements ForecasterContract {
        public function __construct(private readonly ForecastResult $result) {}

        public function forecast(array $history, int $horizonSeconds): ForecastResult
        {
            return $this->result;
        }
    };
}

test('target workers is bounded by workers.max', function (): void {
    $strategy = new HybridStrategy(
        littles: new LittlesLawCalculator(),
        backlog: new BacklogDrainCalculator(),
        arrivalEstimator: new ArrivalRateEstimator(),
        spawnTracker: fakeSpawnTracker(1.0),
        pickupStore: fakePickupStore([]),
        percentileCalc: new \Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator(),
    );

    $config = makeConfig();
    $metrics = new QueueMetricsData(
        pending: 10_000,                      // massive backlog
        oldestJobAge: 300,
        activeWorkers: 1,
        throughputPerMinute: 10.0,
        avgDuration: 2.0,
        failureRate: 0.0,
        utilizationRate: 100.0,
    );

    $target = $strategy->calculateTargetWorkers($metrics, $config);

    expect($target)->toBeLessThanOrEqual($config->workers->max);
});

test('scales up when p95 exceeds effective SLA', function (): void {
    // Samples producing p95 ≈ 25s. Effective SLA = 30 - 2 = 28s. Within threshold.
    // But push p95 higher to force breach scaling.
    $samples = [];
    for ($i = 0; $i < 100; $i++) {
        $samples[] = ['timestamp' => (float) time(), 'pickup_seconds' => 26.0];
    }

    $strategy = new HybridStrategy(
        littles: new LittlesLawCalculator(),
        backlog: new BacklogDrainCalculator(),
        arrivalEstimator: new ArrivalRateEstimator(),
        spawnTracker: fakeSpawnTracker(2.0),
        pickupStore: fakePickupStore($samples),
        percentileCalc: new \Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator(),
    );

    $config = makeConfig();
    $metrics = new QueueMetricsData(
        pending: 50,
        oldestJobAge: 20,
        activeWorkers: 2,
        throughputPerMinute: 60.0,
        avgDuration: 1.0,
        failureRate: 0.0,
        utilizationRate: 50.0,
    );

    $target = $strategy->calculateTargetWorkers($metrics, $config);

    expect($target)->toBeGreaterThan(2);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Scaling/Strategies/HybridStrategyTest.php`
Expected: FAIL (class does not exist)

- [ ] **Step 3: Implement `HybridStrategy`**

Start by copying the existing `src/Scaling/Strategies/PredictiveStrategy.php` content to `src/Scaling/Strategies/HybridStrategy.php`, then modify:

1. Rename class to `HybridStrategy`.
2. Inject new dependencies: `SpawnLatencyTrackerContract`, `PickupTimeStoreContract`, `PercentileCalculatorContract`.
3. In `calculateTargetWorkers()`:
   - Before `$arrivalEstimate = ...`, configure forecaster on estimator using config values:
     ```php
     $forecastCfg = $config->forecast;
     if (! $this->arrivalEstimator->hasForecaster()) {
         $forecaster = app($forecastCfg->forecasterClass);
         $policy = app($forecastCfg->policyClass);
         $this->arrivalEstimator->setForecaster($forecaster, $policy, $forecastCfg->horizonSeconds);
     }
     ```
   - After job time + arrival rate estimation, compute effective SLA:
     ```php
     $spawnLatency = $config->spawnCompensation->enabled
         ? $this->spawnTracker->currentLatency($config->connection, $config->queue)
         : 0.0;
     $effectiveSla = max(1.0, $config->sla->targetSeconds - $spawnLatency);
     ```
   - Compute p95 and use it instead of oldestJobAge for breach decisions:
     ```php
     $samples = array_column(
         $this->pickupStore->recentSamples($config->connection, $config->queue, $config->sla->windowSeconds),
         'pickup_seconds',
     );
     $pickupTimes = array_map(static fn ($v): float => (float) $v, $samples);
     $p95 = $this->percentileCalc->compute($pickupTimes, $config->sla->percentile);
     $slaSignal = $p95 ?? (float) $metrics->oldestJobAge;
     ```
   - Pass `effectiveSla` + `slaSignal` into `BacklogDrainCalculator`:
     ```php
     $backlogDrainWorkers = $this->backlog->calculateRequiredWorkers(
         backlog: $backlogSize,
         oldestJobAge: (int) $slaSignal,
         slaTarget: $config->sla->targetSeconds,
         avgJobTime: $avgJobTime,
         breachThreshold: config('queue-autoscale.scaling.breach_threshold', 0.5),
         effectiveSlaSeconds: $effectiveSla,
     );
     ```
   - Remove the old `W_trend` (predictiveWorkers) calculation — the forecaster now handles this inside the arrival rate estimator. Just keep `max(W_rate, W_backlog)`.
   - Clamp to workers.min and workers.max:
     ```php
     $targetWorkers = max($config->workers->min, min($config->workers->max, $targetWorkers));
     ```
4. Update `lastCalculation` field set to reflect the v2 structure (drop `predictive`, add `effective_sla`, `spawn_latency`, `p95`).

Full implementation should preserve retry noise correction and utilization adjustment from v1 — those are still useful.

- [ ] **Step 4: Delete old `PredictiveStrategy`**

```bash
git rm src/Scaling/Strategies/PredictiveStrategy.php
```

Update `config/queue-autoscale.php` default strategy reference to `HybridStrategy::class` (done in Task 15).

- [ ] **Step 5: Find and fix all references to `PredictiveStrategy`**

Run: `grep -rn "PredictiveStrategy" src/ tests/ config/`

Update every reference to `HybridStrategy`. This includes service provider, tests, docs.

- [ ] **Step 6: Run the full unit test suite + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit && vendor/bin/phpstan analyse --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 7: Commit**

```bash
git add -u src/ tests/
git commit -m "feat(v2)!: replace PredictiveStrategy with HybridStrategy

BREAKING: PredictiveStrategy removed. HybridStrategy uses forecast-blended
arrival rate, p95 pickup time, and effective SLA (post-spawn-compensation)
for backlog drain decisions. Config strategy reference must be updated."
```

---

## Task 15: Update `WorkerSpawner` to stamp spawn timestamps

**Files:**
- Modify: `src/Workers/WorkerSpawner.php`
- Create: `tests/Unit/Workers/WorkerSpawnerSpawnStampTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;

test('calling spawn records spawn time on latency tracker', function (): void {
    $recorded = [];
    $tracker = new class($recorded) implements SpawnLatencyTrackerContract {
        public function __construct(private array &$recorded) {}

        public function recordSpawn(string $workerId, string $connection, string $queue): void
        {
            $this->recorded[] = compact('workerId', 'connection', 'queue');
        }

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue): float
        {
            return 0.0;
        }
    };

    $spawner = new WorkerSpawner($tracker);
    $spawner->spawn('redis', 'default', 1);

    expect($recorded)->toHaveCount(1);
    expect($recorded[0]['connection'])->toBe('redis');
    expect($recorded[0]['queue'])->toBe('default');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Workers/WorkerSpawnerSpawnStampTest.php`
Expected: FAIL (constructor argument missing)

- [ ] **Step 3: Update `WorkerSpawner`**

Inject `SpawnLatencyTrackerContract`. Before starting the process, generate a unique worker ID (e.g. `Str::uuid()`) and call `$this->spawnTracker->recordSpawn($workerId, $connection, $queue)`. Pass the worker ID to the spawned process via `--worker-id=` argument so the worker can include it in its heartbeat / pickup event. Example:

```php
public function __construct(
    private readonly SpawnLatencyTrackerContract $spawnTracker,
) {}

public function spawn(string $connection, string $queue, int $count): array
{
    $workerIds = [];
    for ($i = 0; $i < $count; $i++) {
        $workerId = (string) Str::uuid();
        $this->spawnTracker->recordSpawn($workerId, $connection, $queue);

        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            $connection,
            '--queue='.$queue,
            // ... existing args ...
        ]);
        $process->start();
        $workerIds[] = $workerId;
    }

    return $workerIds;
}
```

The `PickupTimeRecorder` already records pickup time in the store. A parallel `SpawnLatencyRecorder` listener (add as a small class in this task) can listen to the same `JobProcessing` event and map from `worker_id` (propagated via env var or `queue:work --hostname`) to `recordFirstPickup`. If propagating a worker ID through `queue:work` is non-trivial, use PID as surrogate: `recordSpawn($pid, ...)` and `recordFirstPickup($pid, ...)`.

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Unit/Workers && vendor/bin/phpstan analyse src/Workers --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add -u src/ tests/
git commit -m "feat(v2): WorkerSpawner stamps spawn time for latency tracking"
```

---

## Task 16: New `config/queue-autoscale.php` file

**Files:**
- Modify: `config/queue-autoscale.php`

- [ ] **Step 1: Read current config file**

Run: `cat config/queue-autoscale.php`

Keep it handy for reference — we're rewriting but want to preserve any custom env() patterns users rely on.

- [ ] **Step 2: Write new config file**

Replace contents of `config/queue-autoscale.php` with:

```php
<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
    'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID', gethostname()),

    /*
    |--------------------------------------------------------------------------
    | Default Profile (per-queue settings)
    |--------------------------------------------------------------------------
    |
    | Provide a ProfileContract class OR a literal array matching the shape
    | returned by BalancedProfile::resolve(). See docs/upgrade-guide-v2.md
    | for detailed migration from v1.
    |
    */
    'sla_defaults' => BalancedProfile::class,

    /*
    |--------------------------------------------------------------------------
    | Per-queue overrides
    |--------------------------------------------------------------------------
    |
    | Each value can be a ProfileContract class OR an array of partial
    | overrides that merges with sla_defaults.
    |
    */
    'queues' => [
        // 'payments' => CriticalProfile::class,
        // 'custom' => ['sla' => ['target_seconds' => 45]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pickup time storage (global)
    |--------------------------------------------------------------------------
    */
    'pickup_time' => [
        'store' => RedisPickupTimeStore::class,
        'percentile_calculator' => SortBasedPercentileCalculator::class,
        'max_samples_per_queue' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scaling algorithm tuning (global)
    |--------------------------------------------------------------------------
    */
    'scaling' => [
        'fallback_job_time_seconds' => env('QUEUE_AUTOSCALE_FALLBACK_JOB_TIME', 2.0),
        'breach_threshold' => 0.5,
        'cooldown_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource limits (global)
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_cpu_percent' => 85,
        'max_memory_percent' => 85,
        'worker_memory_mb_estimate' => 128,
        'reserve_cpu_cores' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manager process
    |--------------------------------------------------------------------------
    */
    'manager' => [
        'evaluation_interval_seconds' => 5,
        'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
    ],

    'strategy' => HybridStrategy::class,

    'policies' => [
        ConservativeScaleDownPolicy::class,
        BreachNotificationPolicy::class,
    ],
];
```

- [ ] **Step 3: Run PHPStan against config**

Run: `vendor/bin/phpstan analyse config --level=9 --no-progress`
Expected: `[OK] No errors`

- [ ] **Step 4: Run full test suite to catch config-consumer breakage**

Run: `vendor/bin/pest`
Expected: all pass. Fix any breakage.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty
git add config/queue-autoscale.php
git commit -m "feat(v2)!: restructure config into grouped sections with profile classes"
```

---

## Task 17: Service provider bindings

**Files:**
- Modify: `src/` service provider file (find via `ls src/*ServiceProvider.php`)

- [ ] **Step 1: Find the service provider**

Run: `ls src/*ServiceProvider.php src/**/ServiceProvider.php 2>/dev/null`

Identify the main service provider (e.g. `QueueAutoscaleServiceProvider`).

- [ ] **Step 2: Bind contracts in the service provider**

In the `register()` method, add:

```php
$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract::class,
    fn () => new ($this->resolveClass('pickup_time.store', \Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore::class))(
        maxSamplesPerQueue: (int) config('queue-autoscale.pickup_time.max_samples_per_queue', 1000),
    ),
);

$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract::class,
    fn () => new ($this->resolveClass('pickup_time.percentile_calculator', \Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator::class))(),
);

$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract::class,
    fn () => new \Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker(
        fallbackSeconds: 2.0,
        minSamples: 5,
        alpha: 0.2,
    ),
);
```

Add a small helper method:
```php
/** @return class-string */
private function resolveClass(string $key, string $default): string
{
    $value = config("queue-autoscale.{$key}", $default);

    return is_string($value) ? $value : $default;
}
```

And register the event listener in `boot()`:

```php
$this->app['events']->listen(
    \Illuminate\Queue\Events\JobProcessing::class,
    \Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder::class,
);
```

- [ ] **Step 3: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest && vendor/bin/phpstan analyse --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 4: Commit**

```bash
git add -u src/
git commit -m "feat(v2): wire new contracts and event listener in service provider"
```

---

## Task 18: `MigrateConfigCommand` for v1 → v2 migration

**Files:**
- Create: `src/Commands/MigrateConfigCommand.php`
- Create: `tests/Feature/V2MigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('migrate-config command writes a v2 file when v1 config detected', function (): void {
    $src = base_path('tests/Feature/fixtures/v1-config.php');
    $dst = base_path('tests/Feature/fixtures/queue-autoscale.v2.php');

    if (File::exists($dst)) {
        File::delete($dst);
    }

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    expect(File::exists($dst))->toBeTrue();

    $contents = File::get($dst);
    expect($contents)->toContain("'sla_defaults'");
    expect($contents)->toContain('BalancedProfile');
});
```

Create the fixture `tests/Feature/fixtures/v1-config.php` with a representative v1 array from the current config file (use `git show main:config/queue-autoscale.php` if needed).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/V2MigrationTest.php`
Expected: FAIL

- [ ] **Step 3: Implement `MigrateConfigCommand`**

```php
<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MigrateConfigCommand extends Command
{
    protected $signature = 'queue-autoscale:migrate-config
                            {--source= : v1 config file path (default: config/queue-autoscale.php)}
                            {--destination= : Output path for v2 config (default: config/queue-autoscale.v2.php)}';

    protected $description = 'Migrate a v1 queue-autoscale config file to v2 shape.';

    public function handle(): int
    {
        $source = $this->option('source') ?: config_path('queue-autoscale.php');
        $destination = $this->option('destination') ?: config_path('queue-autoscale.v2.php');

        if (! File::exists($source)) {
            $this->error("Source file not found: {$source}");

            return self::FAILURE;
        }

        /** @var array<string, mixed> $v1 */
        $v1 = require $source;

        if (! $this->looksLikeV1($v1)) {
            $this->warn('Source does not look like a v1 config. Skipping.');

            return self::SUCCESS;
        }

        $v2 = $this->translate($v1);
        File::put($destination, "<?php\n\nreturn ".var_export($v2, true).";\n");

        $this->info("v2 config written to: {$destination}");
        $this->line('Review it carefully, then replace the original.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function looksLikeV1(array $config): bool
    {
        if (! isset($config['sla_defaults']) || ! is_array($config['sla_defaults'])) {
            return false;
        }

        return array_key_exists('max_pickup_time_seconds', $config['sla_defaults']);
    }

    /**
     * @param  array<string, mixed>  $v1
     * @return array<string, mixed>
     */
    private function translate(array $v1): array
    {
        return [
            'enabled' => $v1['enabled'] ?? true,
            'manager_id' => $v1['manager_id'] ?? gethostname(),
            'sla_defaults' => BalancedProfile::class,
            'queues' => [],
            'pickup_time' => [
                'store' => \Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore::class,
                'percentile_calculator' => \Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator::class,
                'max_samples_per_queue' => 1000,
            ],
            'scaling' => [
                'fallback_job_time_seconds' => $v1['scaling']['fallback_job_time_seconds'] ?? 2.0,
                'breach_threshold' => $v1['scaling']['breach_threshold'] ?? 0.5,
                'cooldown_seconds' => $v1['sla_defaults']['scale_cooldown_seconds'] ?? 60,
            ],
            'limits' => $v1['limits'] ?? [],
            'manager' => $v1['manager'] ?? [],
            'strategy' => \Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy::class,
            'policies' => $v1['policies'] ?? [],
        ];
    }
}
```

Register the command in the service provider if not auto-discovered.

- [ ] **Step 4: Run tests + PHPStan + Pint**

Run: `vendor/bin/pest tests/Feature/V2MigrationTest.php && vendor/bin/phpstan analyse src/Commands --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: PASS + `[OK] No errors`

- [ ] **Step 5: Commit**

```bash
git add src/Commands/ tests/Feature/
git commit -m "feat(v2): add queue-autoscale:migrate-config command for v1 → v2 upgrade"
```

---

## Task 19: Simulation test — forecasting reduces breaches

**Files:**
- Modify: `tests/Simulation/SimulationTest.php`

- [ ] **Step 1: Read existing SimulationTest structure**

Run: `head -100 tests/Simulation/SimulationTest.php`

Identify the scenario-building helpers and assertion pattern. We'll add new test cases that reuse them.

- [ ] **Step 2: Write new scenario test**

Add to the existing SimulationTest:

```php
test('forecasting reduces SLA breaches on gradual ramp compared to disabled forecast', function (): void {
    // 3-minute linear ramp from 1/s to 10/s
    $events = [];
    for ($t = 0; $t < 180; $t++) {
        $ratePerSecond = 1.0 + (($t / 180.0) * 9.0);
        for ($i = 0; $i < (int) round($ratePerSecond); $i++) {
            $events[] = ['t' => (float) $t + ($i / max(1.0, $ratePerSecond)), 'duration' => 1.5];
        }
    }

    $breachesDisabled = runSimulation($events, forecastPolicy: 'disabled');
    $breachesModerate = runSimulation($events, forecastPolicy: 'moderate');

    // Forecasting should cut breaches by at least 30%.
    expect($breachesModerate)->toBeLessThan($breachesDisabled * 0.7);
})->group('simulation');
```

The `runSimulation()` helper needs extension to accept a `forecastPolicy` parameter. Modify or add the helper to build a `HybridStrategy` with the named policy (mapping strings to classes).

- [ ] **Step 3: Run the new scenario**

Run: `vendor/bin/pest tests/Simulation/SimulationTest.php --filter="forecasting reduces"`
Expected: PASS (may take 30-60 seconds).

- [ ] **Step 4: Commit**

```bash
git add tests/Simulation/SimulationTest.php
git commit -m "test(v2): simulation proves forecasting reduces SLA breaches by >= 30%"
```

---

## Task 20: Simulation test — spawn compensation prevents cold-start breach

**Files:**
- Modify: `tests/Simulation/SimulationTest.php`

- [ ] **Step 1: Write the scenario**

```php
test('spawn compensation prevents breach during slow cold-start', function (): void {
    // Sudden spike at t=0. Simulate slow spawn (3s).
    $events = [];
    for ($i = 0; $i < 100; $i++) {
        $events[] = ['t' => 0.0 + ($i * 0.1), 'duration' => 1.0];
    }

    $breachesWithoutComp = runSimulation($events, spawnLatencySeconds: 3.0, compensationEnabled: false);
    $breachesWithComp = runSimulation($events, spawnLatencySeconds: 3.0, compensationEnabled: true);

    expect($breachesWithComp)->toBeLessThan($breachesWithoutComp);
})->group('simulation');
```

Extend `runSimulation()` to model artificial spawn latency by delaying "worker available" events.

- [ ] **Step 2: Run it**

Run: `vendor/bin/pest tests/Simulation/SimulationTest.php --filter="spawn compensation"`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Simulation/SimulationTest.php
git commit -m "test(v2): simulation proves spawn compensation reduces cold-start breaches"
```

---

## Task 21: Simulation test — p95 resilient to outliers

**Files:**
- Modify: `tests/Simulation/SimulationTest.php`

- [ ] **Step 1: Write the scenario**

```php
test('p95 signal ignores rare stuck jobs that would trigger max-based breach', function (): void {
    // 100 normal pickups around 5s + 2 stuck jobs at 120s.
    $pickupTimes = array_fill(0, 100, 5.0);
    $pickupTimes[] = 120.0;
    $pickupTimes[] = 125.0;

    $calc = new \Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator();
    $p95 = $calc->compute($pickupTimes, 95);
    $max = max($pickupTimes);

    // p95 of 102 values: index = ceil(0.95 * 102) - 1 = 96 → sorted[96] = 5.0
    expect($p95)->toBe(5.0);
    // Max signal would be 125.0 — far exceeds a 30-second SLA.
    expect($max)->toBeGreaterThan(30.0);
})->group('simulation');
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/pest tests/Simulation/SimulationTest.php --filter="p95 signal ignores"`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Simulation/SimulationTest.php
git commit -m "test(v2): simulation proves p95 is resilient to outlier stuck jobs"
```

---

## Task 22: Arch tests + CHANGELOG + upgrade guide

**Files:**
- Create: `tests/Arch.php` (or update existing Arch test file)
- Modify: `CHANGELOG.md`
- Create: `docs/upgrade-guide-v2.md`

- [ ] **Step 1: Write Arch tests**

```php
<?php

declare(strict_types=1);

arch('contracts live in Contracts namespace and are interfaces')
    ->expect('Cbox\LaravelQueueAutoscale\Contracts')
    ->toBeInterfaces();

arch('concrete strategies do not depend on concrete calculators')
    ->expect('Cbox\LaravelQueueAutoscale\Scaling\Strategies')
    ->not->toUse([
        'Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster',
        'Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore',
        'Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator',
        'Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker',
    ]);

arch('configuration value objects are final readonly')
    ->expect('Cbox\LaravelQueueAutoscale\Configuration')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();
```

- [ ] **Step 2: Run Arch tests**

Run: `vendor/bin/pest tests/Arch.php`
Expected: PASS

- [ ] **Step 3: Update CHANGELOG.md**

Add at the top of the file:

```markdown
## [2.0.0] - 2026-XX-XX

### BREAKING CHANGES

- `PredictiveStrategy` removed. Replaced by `HybridStrategy`. Update
  `config('queue-autoscale.strategy')` references.
- `ProfilePresets` static methods removed. Replaced by `ProfileContract`
  implementations: `BalancedProfile`, `CriticalProfile`, `HighVolumeProfile`,
  `BurstyProfile`, `BackgroundProfile`.
- `QueueConfiguration` properties restructured:
  - `maxPickupTimeSeconds` → `$config->sla->targetSeconds`
  - `minWorkers` / `maxWorkers` → `$config->workers->min` / `->max`
  - `scaleCooldownSeconds` → global `config('queue-autoscale.scaling.cooldown_seconds')`
- Config file shape rewritten. Run `php artisan queue-autoscale:migrate-config`
  to produce a v2 file from a v1 file.
- `TrendScalingPolicy` enum replaced by `ForecastPolicyContract` with four
  classes: `DisabledForecastPolicy`, `HintForecastPolicy`,
  `ModerateForecastPolicy`, `AggressiveForecastPolicy`.

### Added

- Genuine forecasting via `LinearRegressionForecaster` (OLS + R²
  confidence blending).
- Worker spawn latency compensation via `EmaSpawnLatencyTracker`.
- p95 pickup time SLA signal via `RedisPickupTimeStore` +
  `SortBasedPercentileCalculator`.
- `Contracts/` namespace — every algorithm and backend is replaceable
  via Laravel container binding.
- `queue-autoscale:migrate-config` Artisan command.

### See also

`docs/upgrade-guide-v2.md` for step-by-step migration.
```

- [ ] **Step 4: Write upgrade guide**

Create `docs/upgrade-guide-v2.md` with frontmatter per repo conventions:

```markdown
---
title: "Upgrading from v1 to v2"
description: "Step-by-step migration guide for the breaking v2.0 release"
weight: 5
---

# Upgrading from v1 to v2

Version 2 is a ground-up redesign that introduces genuine forecasting, spawn-latency compensation, and p95-based SLA signals. This guide walks through the upgrade.

## Step 1 — Update the package

`composer require cboxdk/laravel-queue-autoscale:^2.0`

## Step 2 — Migrate the config file

`php artisan queue-autoscale:migrate-config`

This writes `config/queue-autoscale.v2.php` next to your current file. Review and replace.

## Step 3 — Update code references

| v1                                     | v2                                                |
|----------------------------------------|---------------------------------------------------|
| `$config->maxPickupTimeSeconds`        | `$config->sla->targetSeconds`                     |
| `$config->minWorkers`                  | `$config->workers->min`                           |
| `$config->maxWorkers`                  | `$config->workers->max`                           |
| `$config->scaleCooldownSeconds`        | `config('queue-autoscale.scaling.cooldown_seconds')` |
| `ProfilePresets::balanced()`           | `BalancedProfile::class` (resolved at runtime)    |
| `TrendScalingPolicy::MODERATE`         | `ModerateForecastPolicy::class`                   |
| `PredictiveStrategy`                   | `HybridStrategy`                                  |

## Step 4 — Verify

Run your test suite. The package now uses p95 pickup time over a sliding window, compensated for measured worker spawn latency. You do not need to do anything to benefit from forecasting — it activates automatically when your arrival rate history has 5+ samples and a high enough R² under the configured policy.

## Customising the pipeline

Every algorithm is class-replaceable. For example, to use your own forecaster:

\```php
// In AppServiceProvider::register()
$this->app->bind(
    \Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract::class,
    \App\MyCustomForecaster::class,
);
\```

See `docs/superpowers/specs/2026-04-16-predictive-autoscaling-v2-design.md` for the full architecture.
```

- [ ] **Step 5: Run full test suite + static analysis + Pint**

Run: `vendor/bin/pest && vendor/bin/phpstan analyse --level=9 --no-progress && vendor/bin/pint --dirty`
Expected: all PASS + `[OK] No errors`

- [ ] **Step 6: Commit and push**

```bash
git add tests/Arch.php CHANGELOG.md docs/upgrade-guide-v2.md
git commit -m "docs(v2): add arch tests, CHANGELOG entry, and upgrade guide"
git push
```

---

## Final Checklist

Before tagging `v2.0.0-alpha.1`:

- [ ] All 22 tasks completed and committed on `feature/v2-predictive-autoscaling`
- [ ] `vendor/bin/pest` passes (all unit + feature + arch tests)
- [ ] `vendor/bin/phpstan analyse --level=9` passes with no errors
- [ ] `vendor/bin/pint --test` reports no issues
- [ ] Simulation suite (tagged `simulation`) all pass locally (18+ min runtime)
- [ ] CHANGELOG mentions v2.0.0 breaking changes clearly
- [ ] `docs/upgrade-guide-v2.md` committed
- [ ] `composer.json` version bumped to `2.0.0-alpha.1`
- [ ] Pull request opened against `main` with the spec and this plan linked
