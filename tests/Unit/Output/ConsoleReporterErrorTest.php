<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Output\ConsoleReporter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console reporting is silent unless the operator asked for detail, which is
 * right for narration and wrong for a failure. A manager whose every cycle
 * throws — an unreachable cache, a database the metrics package cannot read —
 * printed its start-up banner and then nothing, looked entirely healthy, and
 * did nothing at all. It is also the likeliest moment for it to happen, since
 * that is what a fresh misconfiguration looks like.
 */
test('an error is written even at normal verbosity', function (): void {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
    $reporter = new ConsoleReporter;
    $reporter->setOutput($output);

    $reporter->error('Evaluation cycle failed: no such table: cache');

    expect($output->fetch())->toContain('no such table: cache');
});

test('narration is still silent at normal verbosity', function (): void {
    // The exception must stay an exception, or the console becomes unreadable
    // and the errors are lost among the noise.
    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
    $reporter = new ConsoleReporter;
    $reporter->setOutput($output);

    $reporter->verbose('Evaluating queue: redis:default', 'debug');

    expect($output->fetch())->toBe('');
});

test('an error without a console attached is discarded, not fatal', function (): void {
    // The manager runs with no output in tests and under some supervisors.
    $reporter = new ConsoleReporter;

    $reporter->error('Evaluation cycle failed');
})->throwsNoExceptions();
