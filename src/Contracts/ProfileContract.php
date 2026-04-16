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
