<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Output\ConsoleReporter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A cycle that throws is caught so one bad workload cannot take the daemon
 * down, and the failure used to go to the log channel alone. That makes the
 * worst case invisible: a manager whose EVERY cycle fails prints its start-up
 * banner and then nothing, and looks perfectly healthy while doing nothing.
 *
 * Found by running the manager against real Redis with a cache driver that had
 * no table behind it — a misconfiguration that takes seconds to make and gave
 * no console hint whatsoever.
 */
function reportFailure(AutoscaleManager $manager, string $message): void
{
    (new ReflectionMethod($manager, 'reportCycleFailureToConsole'))
        ->invoke($manager, new RuntimeException($message));
}

beforeEach(function (): void {
    $this->output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);

    $reporter = new ConsoleReporter;
    $reporter->setOutput($this->output);
    app()->instance(ConsoleReporter::class, $reporter);
    app()->forgetInstance(AutoscaleManager::class);

    $this->manager = app(AutoscaleManager::class);
});

test('a failing cycle says so on the console without any verbosity flag', function (): void {
    reportFailure($this->manager, 'no such table: cache');

    expect($this->output->fetch())->toContain('no such table: cache');
});

test('a cycle failing every few seconds does not fill the terminal', function (): void {
    // The first is always shown. Repeats inside the window are not, or a daemon
    // failing on a three-second interval buries everything else.
    reportFailure($this->manager, 'first');
    $this->output->fetch();

    reportFailure($this->manager, 'second');
    reportFailure($this->manager, 'third');

    expect($this->output->fetch())->toBe('');
});

test('the report does not depend on the cache it may be reporting about', function (): void {
    // Throttled in process, not through the cache-backed alert limiter: an
    // unreachable cache is one of the things this exists to announce, and a
    // reporter that depends on the failing component announces nothing.
    $property = new ReflectionProperty($this->manager, 'cycleFailureReportedAt');

    expect($property->getValue($this->manager))->toBeNull();

    reportFailure($this->manager, 'cache is gone');

    expect($property->getValue($this->manager))->toBeFloat()
        ->and($this->output->fetch())->toContain('cache is gone');
});
