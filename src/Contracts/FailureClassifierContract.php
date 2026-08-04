<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

use Throwable;

/**
 * Decides whether a thrown job exception tells the fuse anything.
 *
 * The question the fuse is really asking is narrower than "did this job
 * fail?" — it is "does this failure mean adding workers would make things
 * worse?". A dead dependency, a timeout or a rate limit all answer yes. A
 * job that threw a validation error on its own payload answers no: it never
 * touched the dependency, and holding the queue back over it would be wrong.
 *
 * Exceptions this returns false for are not recorded at all — they count
 * neither as failures nor as successes, because an outcome that carries no
 * signal should not vote either way.
 */
interface FailureClassifierContract
{
    public function countsAsFailure(Throwable $exception, string $connection, string $queue): bool;
}
