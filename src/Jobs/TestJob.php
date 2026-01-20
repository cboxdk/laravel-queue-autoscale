<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A configurable test job for autoscale testing and demonstrations.
 */
final class TestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $durationMs = 100,
        public readonly bool $shouldFail = false,
        public readonly ?string $identifier = null,
    ) {}

    public function handle(): void
    {
        $id = $this->identifier ?? uniqid('job_');

        if ($this->shouldFail) {
            throw new \RuntimeException("Test job [{$id}] configured to fail");
        }

        // Simulate work
        usleep($this->durationMs * 1000);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['test-job', $this->identifier ?? 'anonymous'];
    }
}
