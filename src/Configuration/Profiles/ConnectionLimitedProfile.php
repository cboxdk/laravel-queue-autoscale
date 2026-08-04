<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration\Profiles;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\DisabledForecastPolicy;

/**
 * Profile for queues whose parallelism is dictated by something downstream.
 *
 * An upstream API that accepts five concurrent callers per customer, a
 * database with a fixed connection budget, a vendor licence counted in seats:
 * in each case the limit is not a preference to be tuned but a number that
 * must not be exceeded.
 *
 * The usual answer is job middleware — `RateLimited` or `WithoutOverlapping`.
 * Both work by *releasing the job back onto the queue* when the lock is taken,
 * which makes the queue depth a count of jobs waiting for a lock rather than
 * work waiting to be done. A depth-driven autoscaler reads that as demand and
 * adds workers, which take more locks, which release more jobs. Attempts are
 * consumed by jobs that never ran, so a long enough queue exhausts its retry
 * budget on contention alone and fails work that was never attempted.
 *
 * Here the worker count IS the limit. Five workers on a queue are five
 * concurrent callers — no locks, no releases, no retry budget spent on
 * waiting. `workers.max` is applied to the cluster-wide target before it is
 * distributed across hosts, so the cap is a fleet cap: five stays five however
 * many machines are running. (That holds in cluster mode. Without it each
 * manager applies the cap independently and three hosts mean three times the
 * limit.)
 *
 * `workers.min` is zero so an idle queue costs nothing, which matters when
 * there is one queue per customer and most are quiet. Pair this profile with
 * a glob so it governs all of them at once:
 *
 *     'queues' => [
 *         'tenant.*' => [
 *             'profile' => ConnectionLimitedProfile::class,
 *             'workers' => ['max' => 5],
 *         ],
 *     ],
 *
 * When more queues want workers than the hosts can carry, the cluster's
 * fair-share allocator holds every queue's minimum first and then water-fills
 * the remainder, so a thousand tenants degrade into slower progress for
 * everyone rather than full speed for the first few and starvation for the
 * rest.
 *
 * The fuse is on. A queue that exists because a third party is slow is exactly
 * the one that should stop calling when that third party starts failing.
 */
readonly class ConnectionLimitedProfile implements ProfileContract
{
    public function resolve(): array
    {
        return [
            'sla' => [
                // Throughput work, not latency work. A pickup target this loose
                // keeps the SLA machinery reporting without letting it argue
                // for workers the downstream limit forbids anyway.
                'target_seconds' => 300,
                'percentile' => 95,
                'window_seconds' => 600,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                // Demand here arrives as a submitted batch, not as a trend
                // worth extrapolating, and the ceiling is fixed regardless.
                'policy' => DisabledForecastPolicy::class,
                'horizon_seconds' => 120,
                'history_seconds' => 600,
            ],
            'workers' => [
                'min' => 0,
                'max' => 5,
                'tries' => 3,
                'max_time_seconds' => 3600,
                'timeout_seconds' => 600,
                // Polling costs money on a metered queue driver and there is
                // one queue per customer, so idle queues poll gently.
                'sleep_seconds' => 5,
                'shutdown_timeout_seconds' => 120,
                'scalable' => true,
            ],
            'spawn_compensation' => [
                // Jobs here run for seconds or minutes; shaving spawn latency
                // off the SLA budget would be noise.
                'enabled' => false,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
            'fuse' => [
                'enabled' => true,
                'failure_threshold_percent' => 50.0,
                'min_samples' => 10,
                'window_seconds' => 120,
                'cooldown_seconds' => 120,
            ],
        ];
    }
}
