<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

/**
 * Which fleet a ScalingDecision's worker counts describe.
 *
 * Host is the default and describes one manager's share, which is every
 * decision the per-host paths have always produced. Cluster describes the
 * cluster-wide totals the leader computes before distribution, and only
 * exists so a policy expressing a global constraint can act on the number
 * that constraint is actually about.
 */
enum ScalingScope: string
{
    case Cluster = 'cluster';
    case Host = 'host';
}
