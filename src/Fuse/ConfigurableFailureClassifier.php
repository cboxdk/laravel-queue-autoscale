<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Fuse;

use Cbox\LaravelQueueAutoscale\Contracts\FailureClassifierContract;
use Throwable;

/**
 * Counts every thrown exception except those the operator has listed as
 * carrying no signal about capacity.
 *
 * Counting everything is the right default for an autoscaler, and differs
 * from a job-level circuit breaker on purpose. A job-level breaker ignores
 * rate limits because it wants the retry to happen later rather than the
 * circuit to open; the autoscaler's question is whether adding workers would
 * help, and against a rate limit the answer is emphatically no. The same
 * holds for auth errors and timeouts: more workers never fix them.
 *
 * The list is matched by instanceof, so listing a base class covers its
 * subclasses.
 */
class ConfigurableFailureClassifier implements FailureClassifierContract
{
    /**
     * @param  list<class-string<Throwable>>|null  $ignored  Null reads the list from config on
     *                                                       first use; pass explicitly to bypass config.
     */
    public function __construct(
        private ?array $ignored = null,
    ) {}

    public function countsAsFailure(Throwable $exception, string $connection, string $queue): bool
    {
        foreach ($this->ignoredExceptions() as $ignored) {
            if ($exception instanceof $ignored) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<class-string<Throwable>>
     */
    private function ignoredExceptions(): array
    {
        if ($this->ignored !== null) {
            return $this->ignored;
        }

        $configured = config('queue-autoscale.fuse.ignored_exceptions', []);

        if (! is_array($configured)) {
            return $this->ignored = [];
        }

        $classes = [];

        foreach ($configured as $class) {
            if (is_string($class) && $class !== '') {
                $classes[] = $class;
            }
        }

        /** @var list<class-string<Throwable>> $classes */
        return $this->ignored = $classes;
    }
}
