<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\ClusterStoreContract;
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;
use Cbox\LaravelQueueAutoscale\Telemetry\Contracts\ProvidesTelemetrySnapshot;
use Cbox\LaravelQueueAutoscale\Telemetry\QueueAutoscaleTelemetryProvider;
use Cbox\Telemetry\Facades\Telemetry;

/**
 * The same number is published on two surfaces, and they used to disagree
 * about what it is called.
 *
 * `clusterMetrics()` is rendered by the host application into whatever format
 * its scraper wants; the telemetry provider registers gauges that
 * cboxdk/laravel-telemetry renders itself. Both describe the same cluster
 * summary, so a name differing between them is not a style question — a query
 * written against one surface silently returns nothing on the other, and an
 * operator cannot tell that apart from "the metric is zero".
 *
 * The check pins the agreement rather than the names, so renaming a metric
 * stays a one-line change that cannot be applied to only one half.
 */
function clusterSummaryFixture(): array
{
    return [
        'cluster_id' => 'test-cluster',
        'manager_count' => 2,
        'total_workers' => 14,
        'required_workers' => 18,
        'total_worker_capacity' => 40,
        'utilization_percent' => 35.0,
        'scale_signal' => ['recommended_hosts' => 3],
        'managers' => [
            ['manager_id' => 'a', 'host' => 'web-01', 'total_workers' => 8, 'max_workers' => 20],
        ],
    ];
}

/**
 * Telemetry declares gauges with dots and Prometheus renders them with
 * underscores, so the two surfaces are compared in the rendered form.
 */
function renderedTelemetryName(string $dotted, string $unit = ''): string
{
    return str_replace('.', '_', $dotted).($unit === '%' ? '_percent' : '');
}

beforeEach(function (): void {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.telemetry.gauges.cluster', true);

    // The facade reads the store; the provider reads the snapshot. Both are
    // given the same summary so a name present on one surface and absent from
    // the other is a real disagreement rather than missing data.
    $store = Mockery::mock(ClusterStoreContract::class);
    $store->shouldReceive('summary')->andReturn(clusterSummaryFixture());
    app()->instance(ClusterStoreContract::class, $store);

    $snapshot = Mockery::mock(ProvidesTelemetrySnapshot::class);
    $snapshot->shouldReceive('snapshot')->andReturn(['cluster' => clusterSummaryFixture()]);
    app()->instance(ProvidesTelemetrySnapshot::class, $snapshot);

    $this->fake = Telemetry::fake();
    $this->fake->provider(new QueueAutoscaleTelemetryProvider(app()));
});

test('every cluster metric the facade emits is also a telemetry gauge', function (): void {
    $facadeNames = [];

    foreach (LaravelQueueAutoscale::clusterMetrics() as $metric) {
        if (str_starts_with($metric['name'], 'queue_autoscale_cluster_')) {
            $facadeNames[$metric['name']] = true;
        }
    }

    expect($facadeNames)->not->toBeEmpty();

    foreach (array_keys($facadeNames) as $rendered) {
        // Every telemetry gauge is queue_autoscale.cluster.<rest>, so the
        // rendered name maps back unambiguously.
        $suffix = str_starts_with($rendered, 'queue_autoscale_cluster_')
            ? substr($rendered, strlen('queue_autoscale_cluster_'))
            : $rendered;

        $unit = str_ends_with($suffix, '_percent') ? '%' : '';
        $dotted = 'queue_autoscale.cluster.'.($unit === '%' ? substr($suffix, 0, -8) : $suffix);

        expect(renderedTelemetryName($dotted, $unit))->toBe(
            $rendered,
            "Facade metric {$rendered} has no matching telemetry gauge name",
        );

        $this->fake->assertGaugeSet($dotted);
    }
});

test('the host recommendation is named the same on both surfaces', function (): void {
    // The specific divergence that started this: the facade said
    // hosts_recommended while the gauge said recommended_hosts, so a KEDA
    // query written from one page returned nothing against the other endpoint.
    $names = array_column(LaravelQueueAutoscale::clusterMetrics(), 'name');

    expect($names)->toContain('queue_autoscale_cluster_recommended_hosts');
    $this->fake->assertGaugeSet('queue_autoscale.cluster.recommended_hosts');
});

test('no metric still uses the retired manager prefix', function (): void {
    // Per-host figures were queue_autoscale_manager_* on the facade and
    // cluster.host.* in telemetry — two names for one concept.
    foreach (LaravelQueueAutoscale::clusterMetrics() as $metric) {
        expect($metric['name'])->not->toStartWith('queue_autoscale_manager_');
    }
});

test('per-host gauges carry the host label on both surfaces', function (): void {
    $hostMetrics = array_values(array_filter(
        LaravelQueueAutoscale::clusterMetrics(),
        fn (array $m): bool => str_starts_with($m['name'], 'queue_autoscale_cluster_host_'),
    ));

    expect($hostMetrics)->not->toBeEmpty()
        ->and($hostMetrics[0]['labels'])->toHaveKey('host');

    $this->fake->assertGaugeSet('queue_autoscale.cluster.host_workers', ['host' => 'web-01']);
});
