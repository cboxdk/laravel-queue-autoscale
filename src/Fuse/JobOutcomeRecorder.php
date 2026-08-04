<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Fuse;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\FailureClassifierContract;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;

/**
 * Feeds the failure fuse from inside the worker processes.
 *
 * Failures are counted from JobExceptionOccurred rather than JobFailed on
 * purpose: JobFailed only fires once a job has exhausted its retries, so with
 * the default tries=3 the fuse would react three attempts late — long after a
 * dead downstream has already caused the backlog the fuse exists to ignore.
 *
 * The two events are mutually exclusive on the success path (Laravel raises
 * JobProcessed only when the job did not throw), so no outcome is counted
 * twice. A job that calls $job->fail() manually without throwing is not
 * counted as a failure.
 *
 * Which exceptions count is the classifier's decision — see
 * FailureClassifierContract.
 */
final class JobOutcomeRecorder
{
    /**
     * Window size per queue, memoised because this runs on every job.
     *
     * @var array<string, int>
     */
    private array $windowSeconds = [];

    public function __construct(
        private readonly FailureWindowStoreContract $store,
        private readonly FailureClassifierContract $classifier,
    ) {}

    public function handleProcessed(JobProcessed $event): void
    {
        $this->record($event->connectionName, $event->job->getQueue() ?: 'default', failed: false);
    }

    public function handleException(JobExceptionOccurred $event): void
    {
        $connection = $event->connectionName;
        $queue = $event->job->getQueue() ?: 'default';

        // A classified-out exception is dropped entirely rather than recorded
        // as a success: it says nothing about whether the dependency is
        // healthy, so it should not dilute the failure rate either way.
        if (! $this->classifier->countsAsFailure($event->exception, $connection, $queue)) {
            return;
        }

        $this->record($connection, $queue, failed: true);
    }

    private function record(string $connection, string $queue, bool $failed): void
    {
        if (AutoscaleConfiguration::isExcluded($queue)) {
            return;
        }

        $this->store->recordOutcome(
            connection: $connection,
            queue: $queue,
            failed: $failed,
            windowSeconds: $this->windowSecondsFor($connection, $queue),
        );
    }

    private function windowSecondsFor(string $connection, string $queue): int
    {
        // Keyed on both, because the window is resolved from both. Keying on
        // the queue alone gave every connection the first-seen connection's
        // bucket size, so counters were written into differently-sized buckets
        // than the manager reads and the fuse saw zeros forever.
        return $this->windowSeconds["{$connection}\0{$queue}"]
            ??= QueueConfiguration::fromConfig($connection, $queue)->fuse->windowSeconds;
    }
}
