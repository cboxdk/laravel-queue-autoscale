<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

/**
 * A collision-free cache-key segment for a connection and queue pair.
 *
 * The stores used to build keys by interpolating both names around a colon.
 * Queue names may legitimately contain colons, so connection `redis` with
 * queue `a:b` produced the same key as connection `redis:a` with queue `b`.
 * With per-tenant queue names that is reachable by anyone who can choose a
 * queue name: collide with another tenant's fuse counters, fail a batch of
 * your own jobs, and their queue is pinned to workers.min for the cooldown.
 *
 * Hashing the pair removes the ambiguity, and it also removes a second
 * problem the interpolated form had. Keys reach whatever cache store the
 * application configured, and memcached rejects keys containing spaces or
 * control characters and truncates past 250 bytes — so an awkward queue name
 * meant a write that silently failed, a read that returned zero, and a fuse
 * that could never trip. A fixed-length hex digest is legal everywhere.
 *
 * The queue name is kept in the key ahead of the digest so a human reading
 * `redis-cli --scan` can still tell what a key belongs to; only the part that
 * must be unambiguous is hashed.
 */
class WorkloadKey
{
    public static function for(string $connection, string $queue): string
    {
        // NUL cannot appear in either name — it is rejected by WorkloadName —
        // so it cannot be produced by any other pair.
        return substr(sha1("{$connection}\0{$queue}"), 0, 16);
    }

    /**
     * A readable prefix for the key, safe on every cache backend.
     *
     * Lossy on purpose: it exists to make a key recognisable while scanning,
     * not to identify it. Uniqueness comes from the digest.
     */
    public static function label(string $queue): string
    {
        $label = preg_replace('/[^A-Za-z0-9._-]/', '-', $queue) ?? '';

        return substr($label, 0, 48);
    }
}
