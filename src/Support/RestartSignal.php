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
        Cache::forever($this->managerCacheKey(), $timestamp);

        return $timestamp;
    }

    public function requestedAfter(int $timestamp): bool
    {
        $restartAt = max(
            $this->numericTimestamp(Cache::get($this->cacheKey())),
            $this->numericTimestamp(Cache::get($this->managerCacheKey())),
            $this->laravelRestartTimestamp(),
        );

        return $restartAt > $timestamp;
    }

    public function cacheKey(): string
    {
        return 'queue-autoscale:restart:'.AutoscaleConfiguration::restartScopeId();
    }

    public function managerCacheKey(): string
    {
        return 'queue-autoscale:restart:'.AutoscaleConfiguration::applicationScopeId().':'.AutoscaleConfiguration::managerId();
    }

    private function numericTimestamp(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function laravelRestartTimestamp(): int
    {
        if (! AutoscaleConfiguration::honorQueueRestart()) {
            return 0;
        }

        return $this->numericTimestamp(Cache::get('illuminate:queue:restart')) * 1000;
    }

    private function currentTimestamp(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
