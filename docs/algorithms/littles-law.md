---
title: "Little's Law"
description: "How the steady-state worker count is derived from arrival rate and average job time using L = lambda W"
weight: 51
---

# Little's Law

`LittlesLawCalculator` produces the **steady-state** half of the hybrid strategy: the number of
workers needed to keep up with the rate at which jobs are arriving.

## The formula that actually runs

`src/Scaling/Calculators/LittlesLawCalculator.php` is the entire implementation:

```php
public function calculate(float $arrivalRate, float $avgProcessingTime): float
{
    if ($arrivalRate <= 0 || $avgProcessingTime <= 0) {
        return 0.0;
    }

    return $arrivalRate * $avgProcessingTime;
}
```

There is no queue depth, no SLA target and no division in it:

```text
workers = arrivalRate (jobs/second) x avgJobTime (seconds/job)
```

This is Little's Law, `L = lambda x W`, applied to the service itself. `lambda` is the arrival rate,
`W` is the time one job occupies one worker, so `L` is the number of jobs in service at any moment —
which is exactly the number of workers you need to serve them.

Both guards return `0.0` rather than a minimum: an arrival rate of zero or a non-positive job time
means "this calculation has nothing to say", and the caller's `workers.min` floor takes over.

The return value is a **float**. `HybridStrategy` is responsible for rounding and clamping.

## Where lambda (arrival rate) comes from

`HybridStrategy::calculateTargetWorkers()` builds the arrival rate in four steps.

**1. Baseline processing rate.** Metrics report throughput per minute; the strategy converts:

```text
processingRate = metrics.throughputPerMinute / 60
```

**2. Estimator with a confidence gate.** `ArrivalRateEstimator::estimate()` keeps a per-queue
sliding window of backlog snapshots (up to 30 snapshots, discarding anything older than 300 s) and
returns `['rate', 'confidence', 'source']`:

```text
rate = processingRate + weightedBacklogGrowthRate
```

Growth is measured between consecutive snapshots and weighted by `2^i`, so the most recent pair
dominates. Confidence is derived from the window length (0.9 for 5–30 s, 0.7 for 2–60 s, 0.5
otherwise), the number of sample pairs, and whether the overall backlog delta is large enough to be
signal rather than noise.

The estimate is used only when `confidence >= scaling.min_arrival_rate_confidence` (default `0.5`).
Below that the strategy falls back to `processingRate`, which is only correct in steady state — and
the reason string says so.

When a forecaster is configured (it always is, from the queue profile) the estimator may return a
forecast-blended rate instead. See [Trend Prediction](trend-prediction.md).

**3. Zero-rate fallback.** If the arrival rate is still exactly `0.0`,
`estimateFallbackArrivalRate()` runs — but only when the backlog is at least 3 jobs:

```text
if backlog < 3:                      0.0
else if oldestJobAge > 0:            (backlog / slaTarget) x min(oldestJobAge / max(slaTarget x 0.5, 1), 2.0)
else:                                backlog / slaTarget
```

**4. Retry-noise subtraction.** Retries look like new arrivals. When
`failureRate > 5%` and `processingRate > 0`:

```text
retryNoise   = min(processingRate x sqrt(failureRate / 100), arrivalRate x 0.3)
arrivalRate  = max(0, arrivalRate - retryNoise)
```

The `sqrt` dampens low lifetime failure rates; the 30% cap stops stale lifetime failure data from
permanently deflating the rate.

## Where W (average job time) comes from

`HybridStrategy::determineJobTime()`:

```text
if metrics.avgDuration > 0 and 0.01 <= metrics.avgDuration <= 600:
    avgJobTime = metrics.avgDuration        # already in seconds
else:
    avgJobTime = scaling.fallback_job_time_seconds   # default 2.0
```

`metrics->avgDuration` reaches the strategy in **seconds** — the metrics package emits milliseconds
and `AutoscaleManager::mapMetricsFields()` converts before building the DTO. The `[0.01, 600]` band
rejects sub-10 ms and over-10-minute readings as measurement artefacts.

## What the caller does with the result

```text
steadyStateWorkers  = littles.calculate(arrivalRate, avgJobTime)      # float
backlogDrainWorkers = backlog.calculateRequiredWorkers(...)           # float
targetWorkers       = max(steadyStateWorkers, backlogDrainWorkers)
targetWorkers       = max(workers.min, min(workers.max, ceil(targetWorkers)))
targetWorkers       = TargetSmoother::smooth(queueKey, targetWorkers, throughputPerMinute)
```

The maximum is over **two** calculations, not three. Fractional results are rounded up exactly once,
at this point.

## Worked examples

All examples assume `workers.min = 1`, `workers.max = 20`.

### Example 1: steady state, no usable estimate

| Input | Value |
|---|---|
| `throughputPerMinute` | 300 |
| Estimator confidence | 0.36 (below the 0.5 gate) |
| `avgDuration` | 2.0 s |

```text
processingRate = 300 / 60 = 5.0 jobs/s
arrivalRate    = 5.0 jobs/s        (confidence gate fell back to processing rate)
avgJobTime     = 2.0 s
workers        = 5.0 x 2.0 = 10.0  -> ceil 10
```

### Example 2: backlog growing during a spike

| Input | Value |
|---|---|
| `throughputPerMinute` | 300 |
| Backlog | 100 -> 190 over 30 s (6 snapshots) |
| Estimator confidence | 0.9 |
| `avgDuration` | 1.5 s |

```text
processingRate = 5.0 jobs/s
growthRate     = ~3.0 jobs/s       (weighted, recent pairs dominate)
arrivalRate    = 5.0 + 3.0 = 8.0 jobs/s
workers        = 8.0 x 1.5 = 12.0  -> ceil 12
```

Processing rate alone would have asked for 8 workers. The estimator is what makes Little's Law
react to a spike instead of trailing it.

### Example 3: retry noise removed

| Input | Value |
|---|---|
| `processingRate` | 4.0 jobs/s |
| Estimated `arrivalRate` | 6.0 jobs/s |
| `failureRate` | 25% |
| `avgJobTime` | 2.0 s |

```text
dampenedFactor = sqrt(25 / 100) = 0.5
retryNoise     = 4.0 x 0.5 = 2.0
maxCorrection  = 6.0 x 0.3 = 1.8      -> retryNoise capped to 1.8
arrivalRate    = 6.0 - 1.8 = 4.2 jobs/s
workers        = 4.2 x 2.0 = 8.4      -> ceil 9
```

### Example 4: no throughput data, backlog present

| Input | Value |
|---|---|
| `throughputPerMinute` | 0 |
| Backlog | 50 |
| `oldestJobAge` | 20 s |
| `sla.target_seconds` | 30 |
| `avgDuration` | 0 (no data) |

```text
processingRate = 0.0, estimate = 0.0  -> fallback estimator runs (backlog 50 >= 3)
urgencyFactor  = min(20 / max(30 x 0.5, 1), 2.0) = min(1.33, 2.0) = 1.33
baseRate       = 50 / 30 = 1.67 jobs/s
arrivalRate    = 1.67 x 1.33 = 2.22 jobs/s
avgJobTime     = 2.0 s               (configured fallback)
workers        = 2.22 x 2.0 = 4.44   -> ceil 5
```

### Example 5: idle queue

```text
arrivalRate = 0.0  ->  calculate() returns 0.0
targetWorkers = max(workers.min, min(workers.max, ceil(0.0))) = 1
```

The floor comes from `workers.min`, never from the calculator — and `workers.min`
is zero for a queue you never named, so the same example on a discovered queue
settles at 0 rather than 1. See
[Workload Profiles](../basic-usage/workload-profiles.md).

## Properties

- **O(1)** time and space per call; the sliding window in `ArrivalRateEstimator` is O(30) per queue.
- Accurate while arrival rate and job time are both well measured.
- Blind to the backlog that already exists — that is what
  [Backlog Drain](backlog-drain.md) is for, and why the strategy takes the maximum of the two.

## See also

- [Backlog Drain](backlog-drain.md) — the SLA-protection half of the maximum
- [Trend Prediction](trend-prediction.md) — forecast blending inside the arrival-rate estimate
- [Architecture](architecture.md) — the full decision pipeline
