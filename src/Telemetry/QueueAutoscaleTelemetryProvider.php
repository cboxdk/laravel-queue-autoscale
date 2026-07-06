<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Telemetry;

use Cbox\LaravelQueueAutoscale\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\Telemetry\Contracts\TelemetryProvider;
use Cbox\Telemetry\Metrics\Registry;
use Illuminate\Contracts\Container\Container;

/**
 * Observable cluster gauges, evaluated at scrape time from the Redis-backed
 * cluster summary. Deliberately limited to what only the autoscaler knows —
 * queue depth, worker state and job metrics are owned by queue-metrics and
 * telemetry's own queue instrumentation.
 */
final readonly class QueueAutoscaleTelemetryProvider implements TelemetryProvider
{
    public function __construct(private Container $container) {}

    public function name(): string
    {
        return 'cbox.queue-autoscale';
    }

    public function register(Registry $registry): void
    {
        if (config('queue-autoscale.telemetry.gauges.cluster', true)) {
            $this->registerClusterGauges($registry);
        }
    }

    private function registerClusterGauges(Registry $registry): void
    {
        $summaryGauges = [
            'queue_autoscale.cluster.managers' => ['manager_count', 'Active autoscale managers in the cluster', '{managers}'],
            'queue_autoscale.cluster.workers' => ['total_workers', 'Autoscaler-spawned workers across the cluster', '{workers}'],
            'queue_autoscale.cluster.required_workers' => ['required_workers', 'Cluster-wide worker demand', '{workers}'],
            'queue_autoscale.cluster.capacity' => ['total_worker_capacity', 'Cluster-wide worker capacity', '{workers}'],
            'queue_autoscale.cluster.utilization' => ['utilization_percent', 'Cluster worker capacity utilization', '%'],
        ];

        foreach ($summaryGauges as $name => [$key, $description, $unit]) {
            $registry->gauge(
                $name,
                fn (): array => $this->summarySample($key),
                description: $description,
                unit: $unit,
            );
        }

        $registry->gauge(
            'queue_autoscale.cluster.recommended_hosts',
            fn (): array => $this->nestedSample('scale_signal', 'recommended_hosts'),
            description: 'Host count recommended by the cluster leader',
            unit: '{hosts}',
        );

        $registry->gauge(
            'queue_autoscale.cluster.host.workers',
            fn (): array => $this->perHost('total_workers'),
            description: 'Autoscaler-spawned workers per host',
            unit: '{workers}',
        );

        $registry->gauge(
            'queue_autoscale.cluster.host.capacity',
            fn (): array => $this->perHost('max_workers'),
            description: 'Worker capacity per host',
            unit: '{workers}',
        );
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function summarySample(string $key): array
    {
        $cluster = $this->cluster();

        if ($cluster === []) {
            return [];
        }

        return [[$this->toFloat($cluster[$key] ?? null), []]];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function nestedSample(string $group, string $key): array
    {
        $cluster = $this->cluster();

        if ($cluster === []) {
            return [];
        }

        $nested = $cluster[$group] ?? null;

        return [[$this->toFloat(is_array($nested) ? ($nested[$key] ?? null) : null), []]];
    }

    /**
     * @return list<array{0: float, 1: array<string, string>}>
     */
    private function perHost(string $key): array
    {
        $managers = $this->cluster()['managers'] ?? null;

        if (! is_array($managers)) {
            return [];
        }

        $samples = [];

        foreach ($managers as $manager) {
            if (! is_array($manager)) {
                continue;
            }

            $samples[] = [$this->toFloat($manager[$key] ?? null), ['host' => $this->toLabel($manager['host'] ?? null)]];
        }

        return $samples;
    }

    /**
     * @return array<string, mixed>
     */
    private function cluster(): array
    {
        return $this->container->make(ProvidesTelemetrySnapshot::class)->snapshot()['cluster'];
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function toLabel(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'unknown';
    }
}
