<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Fuse\ConfigurableFailureClassifier;

class PayloadValidationException extends RuntimeException {}

class NestedPayloadValidationException extends PayloadValidationException {}

test('counts every exception by default', function (Throwable $exception): void {
    $classifier = new ConfigurableFailureClassifier([]);

    expect($classifier->countsAsFailure($exception, 'redis', 'default'))->toBeTrue();
})->with([
    'runtime' => [new RuntimeException('boom')],
    'logic' => [new LogicException('nope')],
    'error' => [new Error('fatal')],
]);

test('does not count an ignored exception', function (): void {
    $classifier = new ConfigurableFailureClassifier([PayloadValidationException::class]);

    expect($classifier->countsAsFailure(new PayloadValidationException, 'redis', 'default'))->toBeFalse();
});

test('ignoring a base class covers its subclasses', function (): void {
    $classifier = new ConfigurableFailureClassifier([PayloadValidationException::class]);

    expect($classifier->countsAsFailure(new NestedPayloadValidationException, 'redis', 'default'))->toBeFalse();
});

test('still counts exceptions outside the ignore list', function (): void {
    $classifier = new ConfigurableFailureClassifier([PayloadValidationException::class]);

    expect($classifier->countsAsFailure(new RuntimeException('downstream down'), 'redis', 'default'))->toBeTrue();
});

test('does not ignore a parent of a listed class', function (): void {
    // Listing the narrow subclass must not silently exempt everything that
    // shares its parent.
    $classifier = new ConfigurableFailureClassifier([NestedPayloadValidationException::class]);

    expect($classifier->countsAsFailure(new PayloadValidationException, 'redis', 'default'))->toBeTrue();
});

test('counts rate limits and auth errors, unlike a job-level breaker', function (): void {
    // Deliberate divergence from the circuit-breaker convention: more workers
    // never fix a rate limit or a bad credential, so both are exactly the
    // situations where scaling up should stop.
    $classifier = new ConfigurableFailureClassifier([]);

    $rateLimited = new RuntimeException('429 Too Many Requests');
    $unauthorized = new RuntimeException('401 Unauthorized');

    expect($classifier->countsAsFailure($rateLimited, 'redis', 'default'))->toBeTrue()
        ->and($classifier->countsAsFailure($unauthorized, 'redis', 'default'))->toBeTrue();
});

describe('config-driven list', function (): void {
    test('reads the ignore list from config', function (): void {
        config()->set('queue-autoscale.fuse.ignored_exceptions', [PayloadValidationException::class]);

        $classifier = new ConfigurableFailureClassifier;

        expect($classifier->countsAsFailure(new PayloadValidationException, 'redis', 'default'))->toBeFalse()
            ->and($classifier->countsAsFailure(new RuntimeException, 'redis', 'default'))->toBeTrue();
    });

    test('counts everything when no list is configured', function (): void {
        expect((new ConfigurableFailureClassifier)->countsAsFailure(new RuntimeException, 'redis', 'default'))->toBeTrue();
    });

    test('survives an unusable ignore list rather than dropping every failure', function (mixed $configured): void {
        config()->set('queue-autoscale.fuse.ignored_exceptions', $configured);

        expect((new ConfigurableFailureClassifier)->countsAsFailure(new RuntimeException, 'redis', 'default'))->toBeTrue();
    })->with([
        'not an array' => ['nonsense'],
        'null' => [null],
        'array of junk' => [[123, null, '']],
    ]);

    test('skips junk entries but honours valid ones alongside them', function (): void {
        config()->set('queue-autoscale.fuse.ignored_exceptions', [null, PayloadValidationException::class, 42]);

        $classifier = new ConfigurableFailureClassifier;

        expect($classifier->countsAsFailure(new PayloadValidationException, 'redis', 'default'))->toBeFalse();
    });
});
