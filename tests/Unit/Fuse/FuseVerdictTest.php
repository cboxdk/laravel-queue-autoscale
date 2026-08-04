<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Fuse\FuseState;
use Cbox\LaravelQueueAutoscale\Fuse\FuseVerdict;

test('a closed verdict never imposes a ceiling', function (): void {
    $verdict = new FuseVerdict(FuseState::Closed, samples: 100, failures: 2, failureRate: 2.0);

    expect($verdict->workerCeiling(1))->toBeNull()
        ->and($verdict->isTripped())->toBeFalse()
        ->and($verdict->reason())->toBe('');
});

test('the closed factory produces an empty, unconstraining verdict', function (): void {
    $verdict = FuseVerdict::closed();

    expect($verdict->state)->toBe(FuseState::Closed)
        ->and($verdict->samples)->toBe(0)
        ->and($verdict->failures)->toBe(0)
        ->and($verdict->failureRate)->toBe(0.0)
        ->and($verdict->workerCeiling(5))->toBeNull();
});

test('an open verdict caps at the configured minimum', function (int $min): void {
    $verdict = new FuseVerdict(FuseState::Open, 40, 30, 75.0);

    expect($verdict->workerCeiling($min))->toBe($min)
        ->and($verdict->isTripped())->toBeTrue();
})->with([0, 1, 5, 50]);

test('a half-open verdict probes at the minimum but never at zero', function (int $min, int $expected): void {
    $verdict = new FuseVerdict(FuseState::HalfOpen, 0, 0, 0.0);

    expect($verdict->workerCeiling($min))->toBe($expected);
})->with([
    // A scale-to-zero queue must get one worker or it can never observe
    // recovery; a queue with a real floor keeps that floor.
    [0, 1],
    [1, 1],
    [5, 5],
]);

test('half-open counts as tripped', function (): void {
    expect((new FuseVerdict(FuseState::HalfOpen, 0, 0, 0.0))->isTripped())->toBeTrue();
});

describe('reason', function (): void {
    test('an open verdict quotes the evidence when it has any', function (): void {
        $reason = (new FuseVerdict(FuseState::Open, 40, 30, 75.0))->reason();

        expect($reason)->toContain('fuse OPEN')
            ->and($reason)->toContain('75.0% failure rate')
            ->and($reason)->toContain('40 jobs');
    });

    test('an open verdict stays honest on the cycle right after a window reset', function (): void {
        // Tripping clears the window, so the next cycle sees zero samples.
        // Quoting "0.0% failure rate over 0 jobs" there would read as though
        // the queue were healthy while it is being held down.
        $reason = (new FuseVerdict(FuseState::Open, 0, 0, 0.0))->reason();

        expect($reason)->toContain('fuse OPEN')
            ->and($reason)->not->toContain('0.0%')
            ->and($reason)->not->toContain('0 jobs');
    });

    test('a half-open verdict reports probe progress once samples arrive', function (): void {
        $reason = (new FuseVerdict(FuseState::HalfOpen, 8, 3, 37.5))->reason();

        expect($reason)->toContain('fuse HALF-OPEN')
            ->and($reason)->toContain('3/8');
    });

    test('a half-open verdict without samples describes the probe instead', function (): void {
        $reason = (new FuseVerdict(FuseState::HalfOpen, 0, 0, 0.0))->reason();

        expect($reason)->toContain('fuse HALF-OPEN')
            ->and($reason)->toContain('minimum worker count');
    });
});

test('the state enum reports which states constrain scaling', function (): void {
    expect(FuseState::Closed->isTripped())->toBeFalse()
        ->and(FuseState::Open->isTripped())->toBeTrue()
        ->and(FuseState::HalfOpen->isTripped())->toBeTrue();
});

test('state values are stable, because they are persisted across restarts', function (): void {
    expect(FuseState::Closed->value)->toBe('closed')
        ->and(FuseState::Open->value)->toBe('open')
        ->and(FuseState::HalfOpen->value)->toBe('half_open');
});
