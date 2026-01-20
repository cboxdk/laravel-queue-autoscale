<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\Renderers;

use Cbox\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;

final class QuietOutputRenderer implements OutputRendererContract
{
    public function initialize(): void
    {
        // No-op
    }

    public function render(OutputData $data): void
    {
        // No-op
    }

    public function handleWorkerOutput(int $pid, string $line): void
    {
        // No-op
    }

    public function shutdown(): void
    {
        // No-op
    }
}
