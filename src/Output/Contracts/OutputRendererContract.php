<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\Contracts;

use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;

interface OutputRendererContract
{
    /**
     * Initialize the renderer (e.g., clear screen, setup TUI)
     */
    public function initialize(): void;

    /**
     * Render the current state
     */
    public function render(OutputData $data): void;

    /**
     * Handle worker stdout line
     */
    public function handleWorkerOutput(int $pid, string $line): void;

    /**
     * Cleanup on shutdown (e.g., restore terminal)
     */
    public function shutdown(): void;
}
