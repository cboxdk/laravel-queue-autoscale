<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\SignalHandler;

/**
 * Only SIGTERM and SIGINT used to be registered. Under SIGQUIT or SIGHUP PHP
 * took the default action and terminated the manager outright, so graceful
 * shutdown never ran and every spawned worker was orphaned. SIGHUP is a
 * terminal hangup and is what some supervisors send.
 */
beforeEach(function (): void {
    $this->handler = new SignalHandler;
});

test('every stop signal reaches graceful shutdown', function (int $signal): void {
    $called = false;
    $this->handler->register(function () use (&$called): void {
        $called = true;
    });

    posix_kill(posix_getpid(), $signal);
    pcntl_signal_dispatch();

    expect($called)->toBeTrue()
        ->and($this->handler->shouldStop())->toBeTrue();

    // Leave the default disposition behind so a later signal in this process
    // is not swallowed by a handler this test installed.
    pcntl_signal($signal, SIG_DFL);
})->with([
    'SIGTERM' => [SIGTERM],
    'SIGINT' => [SIGINT],
    'SIGQUIT' => [SIGQUIT],
    'SIGHUP' => [SIGHUP],
])->skip(! extension_loaded('pcntl') || ! extension_loaded('posix'), 'requires pcntl and posix');

test('reports no stop before a signal arrives', function (): void {
    $this->handler->register(fn () => null);

    expect($this->handler->shouldStop())->toBeFalse();

    foreach ([SIGTERM, SIGINT, SIGQUIT, SIGHUP] as $signal) {
        pcntl_signal($signal, SIG_DFL);
    }
})->skip(! extension_loaded('pcntl'), 'requires pcntl');

test('a programmatic stop needs no signal', function (): void {
    $this->handler->requestStop();

    expect($this->handler->shouldStop())->toBeTrue();
});
