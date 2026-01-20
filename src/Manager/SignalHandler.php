<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Manager;

final class SignalHandler
{
    private bool $shouldStop = false;

    public function register(callable $callback): void
    {
        pcntl_signal(SIGTERM, function () use ($callback) {
            $this->shouldStop = true;
            $callback();
        });

        pcntl_signal(SIGINT, function () use ($callback) {
            $this->shouldStop = true;
            $callback();
        });
    }

    public function shouldStop(): bool
    {
        pcntl_signal_dispatch();

        return $this->shouldStop;
    }

    public function dispatch(): void
    {
        pcntl_signal_dispatch();
    }

    /**
     * Programmatically request a stop (e.g., from TUI quit)
     */
    public function requestStop(): void
    {
        $this->shouldStop = true;
    }
}
