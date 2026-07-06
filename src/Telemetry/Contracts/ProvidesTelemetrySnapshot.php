<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Telemetry\Contracts;

/**
 * Snapshot source consumed by the optional telemetry integration.
 *
 * Implementations must stay cheap: callbacks run on every telemetry scrape.
 */
interface ProvidesTelemetrySnapshot
{
    /**
     * @return array{cluster: array<string, mixed>}
     */
    public function snapshot(): array;
}
