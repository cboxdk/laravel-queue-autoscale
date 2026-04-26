<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Alerting;

use Illuminate\Support\Facades\Cache;

/**
 * Cooldown-based alert rate limiter for SLA/scaling events.
 *
 * Uses Laravel's atomic cache locks so repeated alert attempts within the
 * cooldown window are dropped silently. The first attempt wins and holds
 * the lock until TTL expires; subsequent calls return false and the
 * listener can skip sending the alert.
 *
 * Example usage inside a custom listener:
 *
 *     public function __construct(private AlertRateLimiter $limiter) {}
 *
 *     public function handle(SlaBreached $event): void
 *     {
 *         if (! $this->limiter->allow("slack:breach:{$event->connection}:{$event->queue}")) {
 *             return;
 *         }
 *         // ...send to Slack
 *     }
 *
 * The limiter is safe for concurrent callers: Cache::lock() is atomic
 * across processes/servers when using a shared backend (Redis, database).
 */
final readonly class AlertRateLimiter
{
    /**
     * @param  int  $cooldownSeconds  How long a unique key must wait before
     *                                an alert is allowed through again.
     */
    public function __construct(
        public int $cooldownSeconds = 300,
    ) {}

    /**
     * Try to acquire an alert slot. Returns true if the caller should
     * proceed with the alert, false if an alert for this key was recently
     * dispatched and we're still in its cooldown window.
     */
    public function allow(string $key): bool
    {
        return (bool) Cache::lock($this->lockKey($key), $this->cooldownSeconds)->get();
    }

    private function lockKey(string $key): string
    {
        return "autoscale:alert:{$key}";
    }
}
