<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;

/**
 * Everything one evaluation cycle found to scale: the raw metric payloads for
 * every known queue, and the groups that are safe to act on.
 *
 * `groups` is empty when the group configuration failed validation, so a caller
 * cannot accidentally act on a config that was rejected.
 */
readonly class DiscoveredWorkloads
{
    /**
     * @param  array<string, array<string, mixed>>  $queues  keyed "connection:queue"
     * @param  array<string, GroupConfiguration>  $groups
     */
    public function __construct(
        public array $queues,
        public array $groups,
    ) {}
}
