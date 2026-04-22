<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Illuminate\Support\Facades\Cache;

final class RestartSignal
{
    public function issue(): int
    {
        $timestamp = $this->currentTimestamp();

        Cache::forever($this->cacheKey(), $timestamp);

        return $timestamp;
    }

    public function requestedAfter(int $timestamp): bool
    {
        $restartAt = Cache::get($this->cacheKey());

        return is_numeric($restartAt) && (int) $restartAt > $timestamp;
    }

    public function cacheKey(): string
    {
        return 'queue-autoscale:restart:'.AutoscaleConfiguration::managerId();
    }

    private function currentTimestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
