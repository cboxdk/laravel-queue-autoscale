<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

/**
 * Whether a queue or connection name is safe to hand to a worker process.
 *
 * Queue names are discovered from the metrics store rather than read from
 * configuration — that is what lets a tenant-per-queue application work
 * without listing thousands of queues. It also means a name can originate
 * from whatever the application interpolated into `->onQueue(...)`, which in
 * a multi-tenant system is not always something the operator chose.
 *
 * Three characters change what a name *does* rather than merely what it is
 * called:
 *
 * A comma makes `queue:work` read the value as a priority list, so a queue
 * called `mine,theirs` spawns a worker that drains someone else's queue too.
 *
 * A leading dash makes the name parse as an option. The connection is passed
 * as a bare positional argument, so `--env=staging` would have the worker boot
 * against a different environment file — different database, different queue
 * credentials — and `--memory=1` would have it exit after one job, spawn-
 * looping forever.
 *
 * Whitespace and control characters are illegal in cache keys on some stores.
 * Memcached rejects them outright, so the fuse's counters would silently fail
 * to write, read back as zero, and leave the fuse unable to trip.
 *
 * Everything else is allowed. Dots are legitimate (`orders.fifo`), and so are
 * colons — the package's own workload keys use them.
 */
class WorkloadName
{
    public static function isSafe(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        if (str_starts_with($name, '-')) {
            return false;
        }

        if (str_contains($name, ',')) {
            return false;
        }

        // Control characters and whitespace, including the NUL the package
        // uses internally as a key separator.
        return preg_match('/[\x00-\x20\x7F]/', $name) !== 1;
    }

    /**
     * Why a name was rejected, for a log line or a diagnostic.
     */
    public static function reason(string $name): string
    {
        return match (true) {
            $name === '' => 'the name is empty',
            str_starts_with($name, '-') => 'a leading dash makes it parse as a command-line option',
            str_contains($name, ',') => 'a comma makes queue:work treat it as a list of queues',
            default => 'it contains whitespace or a control character',
        };
    }
}
