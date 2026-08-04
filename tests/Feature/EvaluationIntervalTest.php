<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Commands\LaravelQueueAutoscaleCommand;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * manager.evaluation_interval_seconds was documented as the knob in five
 * places and read by nothing — the interval came only from --interval, which
 * defaulted to a hardcoded 5, so editing the documented key had no effect.
 */
function resolvedInterval(?string $flag): int
{
    $command = app(LaravelQueueAutoscaleCommand::class);

    $input = new ArrayInput(
        $flag === null ? [] : ['--interval' => $flag],
        $command->getDefinition(),
    );
    (new ReflectionProperty(Command::class, 'input'))->setValue($command, $input);

    $method = new ReflectionMethod(LaravelQueueAutoscaleCommand::class, 'getInterval');

    return $method->invoke($command);
}

test('falls back to the configured interval when no flag is given', function (): void {
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 12);

    expect(resolvedInterval(null))->toBe(12);
});

test('the flag wins over the configured interval', function (): void {
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 12);

    expect(resolvedInterval('3'))->toBe(3);
});

test('an empty flag is treated as absent', function (): void {
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 12);

    expect(resolvedInterval(''))->toBe(12);
});

test('refuses an interval below one second', function (string $flag): void {
    // A zero or negative interval turns the control loop into a busy spin
    // against the metrics store.
    expect(resolvedInterval($flag))->toBe(1);
})->with(['0', '-5', 'not-a-number']);

test('a nonsensical configured interval still yields a usable loop', function (): void {
    config()->set('queue-autoscale.manager.evaluation_interval_seconds', 0);

    expect(resolvedInterval(null))->toBe(1);
});
