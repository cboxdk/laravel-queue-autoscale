---
title: "Trend Prediction"
description: "How linear-regression forecasting blends into the arrival-rate estimate, and the forecast policies that gate it"
weight: 52
---

# Trend Prediction

Forecasting in this package is not a third worker-count calculation. It is a **correction to the
arrival rate** that feeds [Little's Law](littles-law.md): the estimator projects where the arrival
rate is heading and blends that projection into the observed rate, so the steady-state calculation
sizes for the next horizon rather than the last tick.

Everything below lives in four places:

- `src/Contracts/ForecasterContract.php` and `src/Contracts/ForecastPolicyContract.php`
- `src/Scaling/Calculators/LinearRegressionForecaster.php`
- `src/Scaling/Forecasting/ForecastResult.php` and `src/Scaling/Forecasting/Policies/`
- `src/Scaling/Calculators/ArrivalRateEstimator.php` — where the blend happens

## The contracts

```php
interface ForecasterContract
{
    /** @param list<array{timestamp: float, rate: float}> $history */
    public function forecast(array $history, int $horizonSeconds): ForecastResult;
}

interface ForecastPolicyContract
{
    /** Minimum R² for a forecast to be trusted. Returns > 1.0 to effectively disable. */
    public function minRSquared(): float;

    /** Blending weight for forecast in [0.0, 1.0]. */
    public function forecastWeight(): float;
}
```

`ForecastResult` is a readonly DTO of `projectedRate`, `rSquared`, `slope`, `sampleCount` and
`hasSufficientData`, with a `ForecastResult::insufficientData()` constructor for the "not enough
samples" case.

Two small interfaces, deliberately: the forecaster decides *what* the future rate is, the policy
decides *whether and how much* to believe it.

## LinearRegressionForecaster

The shipped forecaster is ordinary least squares over `(timestamp, rate)` pairs.

```text
requires at least MIN_SAMPLES = 5 points, else ForecastResult::insufficientData()

slope        = (sumXY - n * meanX * meanY) / (sumXX - n * meanX^2)
intercept    = meanY - slope * meanX
projectedRate = max(0, slope * (latestTimestamp + horizonSeconds) + intercept)
rSquared     = max(0, 1 - ssRes / ssTot)
```

Two degenerate cases are handled explicitly:

- Denominator below `1e-12` (all timestamps effectively identical): returns the mean rate with
  `rSquared = 1.0` and `slope = 0.0`.
- `ssTot` below `1e-12` (a perfectly flat rate): `rSquared = 1.0`.

Projected rate is floored at zero — a steep downward slope cannot project negative arrivals.

## Forecast policies

| Policy | `minRSquared()` | `forecastWeight()` | Effect |
|---|---|---|---|
| `AggressiveForecastPolicy` | 0.4 | 0.8 | Accepts loose fits, mostly trusts the projection |
| `ModerateForecastPolicy` | 0.6 | 0.5 | Even blend of projection and observation |
| `HintForecastPolicy` | 0.8 | 0.3 | Only very clean trends, and only nudges |
| `DisabledForecastPolicy` | 1.1 | 0.0 | `R²` can never reach 1.1, so no forecast is ever used |

`DisabledForecastPolicy` turns forecasting off through the same code path rather than a flag — the
blend simply never passes its gate.

## How the blend works

Inside `ArrivalRateEstimator::estimate()`, once the observed rate has been computed from backlog
growth, `maybeBlendForecast()` runs — but only if a forecaster and policy have been set and at least
two snapshots exist.

**1. Build the rate history** from consecutive backlog snapshots (pairs less than 1 ms apart are
skipped):

```text
growth = (backlog[i] - backlog[i-1]) / (timestamp[i] - timestamp[i-1])
rate   = max(0, processingRate + growth)
```

**2. Forecast** over `forecast.horizon_seconds`.

**3. Gate.** If `!hasSufficientData` or `rSquared < policy.minRSquared()`, the blend is abandoned and
the plain observed rate is returned.

**4. Blend:**

```text
weight  = policy.forecastWeight()
blended = max(0, weight * forecast.projectedRate + (1 - weight) * observedRate)
```

The returned `source` string records the whole thing, for example:

```text
forecast_blended: observed=8.20/s forecast=12.40/s R²=0.87
```

Note what the blend does **not** change: `confidence`. The confidence value returned alongside the
rate is always the window-quality confidence computed from the snapshot history, and it is that
value which the strategy tests against `scaling.min_arrival_rate_confidence`. A high-`R²` forecast
over a poor observation window is still rejected downstream.

## Configuration

Forecasting is configured per queue, in the profile's `forecast` block (or an override array
deep-merged over `sla_defaults`):

```php
'forecast' => [
    'forecaster' => LinearRegressionForecaster::class,
    'policy' => ModerateForecastPolicy::class,
    'horizon_seconds' => 60,
    'history_seconds' => 300,
],
```

`ForecastConfiguration` validates on construction and throws `InvalidConfigurationException` when:

- `forecaster` does not implement `ForecasterContract`
- `policy` does not implement `ForecastPolicyContract`
- `horizon_seconds <= 0`
- `history_seconds < horizon_seconds`

Both classes are resolved from the container, so a forecaster or policy with constructor
dependencies works.

**`history_seconds` is validated but not consumed at runtime.** The window that actually feeds the
forecaster is `ArrivalRateEstimator`'s own fixed sliding window: at most 30 snapshots, nothing older
than 300 seconds. Setting `history_seconds` to 900 does not lengthen it.

### Shipped profile settings

| Profile | Policy | `horizon_seconds` | `history_seconds` |
|---|---|---|---|
| `BalancedProfile` | `ModerateForecastPolicy` | 60 | 300 |
| `CriticalProfile` | `AggressiveForecastPolicy` | 60 | 300 |
| `BurstyProfile` | `AggressiveForecastPolicy` | 120 | 600 |
| `HighVolumeProfile` | `ModerateForecastPolicy` | 60 | 300 |
| `BackgroundProfile` | `HintForecastPolicy` | 300 | 900 |
| `ExclusiveProfile` | `DisabledForecastPolicy` | 60 | 300 |

Every shipped profile uses `LinearRegressionForecaster`.

## Per-queue configuration is re-applied every call

`HybridStrategy` calls `ArrivalRateEstimator::setForecaster()` on **every** invocation, with the
current queue's forecaster, policy and horizon:

```php
$this->arrivalEstimator->setForecaster(
    forecaster: app($config->forecast->forecasterClass),
    policy: app($config->forecast->policyClass),
    horizonSeconds: $config->forecast->horizonSeconds,
);
```

The estimator is a container singleton holding those as instance state. Configuring it once would
mean the first queue evaluated set the forecast behaviour for every other queue for the manager's
whole lifetime.

## Worked example

A queue on `BalancedProfile` (`ModerateForecastPolicy`, horizon 60 s), with `processingRate = 5.0/s`
and a backlog climbing steadily over six snapshots.

```text
Observed:
  weighted growth       = 3.2 jobs/s
  observedRate          = 5.0 + 3.2 = 8.2 jobs/s
  confidence            = 0.9

Forecast (6 rate points, horizon 60 s):
  slope                 = 0.07 jobs/s per second
  projectedRate         = 12.4 jobs/s
  rSquared              = 0.87

Gate: 0.87 >= 0.6 (ModerateForecastPolicy)     -> blend

blended = 0.5 * 12.4 + 0.5 * 8.2 = 10.3 jobs/s

Confidence 0.9 >= 0.5 threshold                -> arrivalRate = 10.3 jobs/s
With avgJobTime 1.5 s: workers = 10.3 * 1.5 = 15.45  -> ceil 16
```

Without the forecast the same tick would have asked for `ceil(8.2 * 1.5) = 13` workers. Under
`HintForecastPolicy` the blend would be `0.3 * 12.4 + 0.7 * 8.2 = 9.46` — 15 workers. Under
`DisabledForecastPolicy` the gate never passes and the answer is 13.

## Writing a custom forecaster

```php
use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\ForecastResult;

class SeasonalForecaster implements ForecasterContract
{
    /** @param list<array{timestamp: float, rate: float}> $history */
    public function forecast(array $history, int $horizonSeconds): ForecastResult
    {
        if (count($history) < 5) {
            return ForecastResult::insufficientData();
        }

        return new ForecastResult(
            projectedRate: $this->project($history, $horizonSeconds),
            rSquared: $this->goodnessOfFit($history),
            slope: $this->slope($history),
            sampleCount: count($history),
            hasSufficientData: true,
        );
    }
}
```

Return an honest `rSquared` — it is the only thing standing between a bad projection and the worker
count, and the policy's `minRSquared()` gate depends on it entirely.

Point a queue at it:

```php
'queues' => [
    'checkout' => [
        'forecast' => [
            'forecaster' => SeasonalForecaster::class,
            'policy' => ModerateForecastPolicy::class,
            'horizon_seconds' => 120,
            'history_seconds' => 600,
        ],
    ],
],
```

## Properties

- **O(n)** in the number of snapshots, with `n <= 30`.
- Single pass for the regression, single pass for `R²`.
- No persistence: history is per-process and per-queue, and is discarded on manager restart.

## See also

- [Little's Law](littles-law.md) — where the blended arrival rate is consumed
- [Backlog Drain](backlog-drain.md) — the calculation forecasting does not touch
- [Architecture](architecture.md) — the full decision pipeline
