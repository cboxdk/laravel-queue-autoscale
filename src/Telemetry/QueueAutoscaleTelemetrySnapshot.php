<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Telemetry;

use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;

/**
 * Default telemetry snapshot backed by the Redis cluster store.
 *
 * The snapshot is cached briefly so concurrent scrapes do not all hit the
 * cluster store at once. Single-host mode yields an empty cluster snapshot.
 */
final readonly class QueueAutoscaleTelemetrySnapshot implements ProvidesTelemetrySnapshot
{
    public function __construct(private Container $container) {}

    public function snapshot(): array
    {
        $cacheTtl = config('queue-autoscale.telemetry.cache_ttl', 10);
        $cacheTtl = is_numeric($cacheTtl) ? (int) $cacheTtl : 10;

        if ($cacheTtl <= 0) {
            return $this->build();
        }

        return Cache::remember(
            'queue_autoscale:telemetry:snapshot',
            now()->addSeconds($cacheTtl),
            fn (): array => $this->build(),
        );
    }

    /**
     * @return array{cluster: array<string, mixed>}
     */
    private function build(): array
    {
        if (! AutoscaleConfiguration::clusterEnabled()) {
            return ['cluster' => []];
        }

        $store = $this->container->make(ClusterStore::class);
        $summary = $store->summary();

        return ['cluster' => $summary];
    }
}
