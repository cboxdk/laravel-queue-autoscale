<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers\SpawnLatency;

use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Listens to JobProcessing events in the spawned worker process and triggers
 * recordFirstPickup() so the EMA spawn latency tracker can measure the
 * wall-clock time from process start to first job pickup.
 *
 * Worker identity is the OS process PID (as a string), which matches the PID
 * recorded by WorkerSpawner after calling Process::start(). This avoids any
 * UUID injection into the worker environment.
 *
 * The listener is idempotent: recordFirstPickup() is a no-op when no pending
 * key exists for the given worker ID (e.g. after the first pickup has already
 * been recorded, or for workers spawned outside the autoscaler).
 */
final class SpawnLatencyRecorder
{
    public function __construct(
        private readonly SpawnLatencyTrackerContract $tracker,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $this->tracker->recordFirstPickup(
            workerId: (string) getmypid(),
            pickupTimestamp: microtime(true),
        );
    }
}
