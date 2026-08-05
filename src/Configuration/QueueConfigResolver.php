<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

/**
 * Resolves an entry from `queue-autoscale.queues` for a given queue name.
 *
 * Two things make this more than an array lookup.
 *
 * Queue names may contain dots. `config('queue-autoscale.queues.tenant.42')`
 * splits on them and looks for a nested `tenant` key, so a queue literally
 * named `tenant.42` was unreachable — its configuration was silently ignored
 * and the queue ran on defaults. Reading the array directly fixes that.
 *
 * And a key may be a glob. An application that creates a queue per tenant
 * cannot enumerate them in config: the names are only known at runtime, and
 * there may be thousands. `tenant.*` lets one entry govern all of them, which
 * pairs with discovery — the queues appear on their own, and the pattern says
 * how they should be treated.
 */
class QueueConfigResolver
{
    /**
     * The configuration block governing a queue: its exact entry if one
     * exists, otherwise the first glob that matches it, otherwise nothing.
     *
     * Exact names win over globs so a single tenant can be given different
     * treatment without disturbing the rule covering the rest. Globs are tried
     * in declaration order, so overlapping patterns resolve by where they sit
     * in the config file rather than by an invisible specificity ranking.
     */
    public static function overrideFor(string $queue): mixed
    {
        $queues = self::all();

        if (array_key_exists($queue, $queues)) {
            return $queues[$queue];
        }

        foreach ($queues as $key => $override) {
            if (self::isPattern($key) && fnmatch($key, $queue)) {
                return $override;
            }
        }

        return [];
    }

    /**
     * Queue names configured literally.
     *
     * A glob is a rule about queues, not a queue. Treating `tenant.*` as a
     * configured queue name would have the manager discover it, decide it has
     * a backlog of zero, and hold workers on a queue that cannot exist.
     *
     * @return array<string, mixed>
     */
    public static function literal(): array
    {
        return array_filter(
            self::all(),
            static fn (string $key): bool => ! self::isPattern($key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Whether a configuration key is a glob rather than a queue name.
     *
     * Only the fnmatch metacharacters count. A key without them is an exact
     * name, already handled by the direct lookup, and testing it through
     * fnmatch would be equality with extra steps.
     */
    public static function isPattern(string $key): bool
    {
        return strpbrk($key, '*?[') !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        $queues = config('queue-autoscale.queues', []);

        if (! is_array($queues)) {
            return [];
        }

        $resolved = [];

        foreach ($queues as $key => $entry) {
            if (is_string($key)) {
                $resolved[$key] = $entry;
            }
        }

        return $resolved;
    }
}
