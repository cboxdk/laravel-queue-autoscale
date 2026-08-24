<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Fixtures;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;

/**
 * A profile that throws when resolved.
 *
 * Stands in for the real ways one workload can poison an evaluation cycle — a
 * malformed config entry, a policy that throws, a metric that cannot be read.
 * Resolution happens inside the leader's per-workload loop, which is precisely
 * where a missing guard takes the whole cluster's recommendation down with it.
 */
class ThrowingProfile implements ProfileContract
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        throw new \RuntimeException('this workload cannot be evaluated');
    }
}
