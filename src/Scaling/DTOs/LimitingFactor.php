<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

/**
 * What actually decided the worker target this cycle.
 *
 * This was a bare string with a docblock naming three values while six were
 * live, and the drift had already produced two visible defects: the manager
 * gates its "held back by failure fuse" log on an exact string match, and the
 * -vvv explanation chain silently omitted both Fuse and SystemMetricsUnavailable
 * — so verbose mode printed nothing while the fuse was holding a queue down,
 * and leaked a raw internal token elsewhere. As an enum, an unhandled case is
 * a build failure instead.
 */
enum LimitingFactor: string
{
    /** Host CPU headroom ran out first. */
    case Cpu = 'cpu';

    /** Host memory headroom ran out first. */
    case Memory = 'memory';

    /** CPU and memory ran out together. */
    case Balanced = 'balanced';

    /** The configured workers.max ceiling. */
    case Config = 'config';

    /** Nothing constrained the strategy's recommendation. */
    case Strategy = 'strategy';

    /** The failure fuse is holding the queue at its floor. */
    case Fuse = 'fuse';

    /** The host could not be measured, so a conservative fallback was used. */
    case SystemMetricsUnavailable = 'system_metrics_unavailable';

    /**
     * Operator-facing explanation. Exhaustive by construction.
     */
    public function description(): string
    {
        return match ($this) {
            self::Cpu => 'constrained by CPU',
            self::Memory => 'constrained by memory',
            self::Balanced => 'CPU and memory constrain equally',
            self::Config => 'constrained by workers.max',
            self::Strategy => 'optimal based on demand',
            self::Fuse => 'held back by the failure fuse',
            self::SystemMetricsUnavailable => 'system metrics unavailable, using a conservative fallback',
        };
    }

    /**
     * Whether this represents a resource shortage on the host, as opposed to a
     * deliberate policy ceiling or an unconstrained decision.
     */
    public function isResourceConstrained(): bool
    {
        return match ($this) {
            self::Cpu, self::Memory, self::Balanced => true,
            default => false,
        };
    }
}
