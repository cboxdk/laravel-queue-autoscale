<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Manager;

class SignalHandler
{
    private bool $shouldStop = false;

    /**
     * Signals that mean "stop", all of which must reach graceful shutdown.
     *
     * SIGQUIT and SIGHUP were previously unhandled, so PHP took the default
     * action and terminated the manager outright — its spawned workers were
     * never signalled and lived on as orphans until --max-time. SIGHUP in
     * particular is a terminal hangup and is what some supervisors send.
     */
    private const STOP_SIGNALS = [SIGTERM, SIGINT, SIGQUIT, SIGHUP];

    public function register(callable $callback): void
    {
        foreach (self::STOP_SIGNALS as $signal) {
            pcntl_signal($signal, function () use ($callback): void {
                $this->shouldStop = true;
                $callback();
            });
        }
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
