<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Support\Coerce;

/**
 * These sit on the read path of an evaluation cycle, where values arrive from
 * config, decoded JSON and the metrics package. They are deliberately total —
 * an unusable value becomes the zero value rather than throwing — because one
 * malformed field must not abort scaling for every other workload.
 */
test('toInt and toFloat accept anything numeric, including numeric strings', function (): void {
    expect(Coerce::toInt(7))->toBe(7)
        ->and(Coerce::toInt('7'))->toBe(7)
        ->and(Coerce::toInt(7.9))->toBe(7)
        ->and(Coerce::toFloat('1.5'))->toBe(1.5)
        ->and(Coerce::toFloat(2))->toBe(2.0);
});

test('a non-numeric value becomes zero rather than throwing', function (mixed $value): void {
    expect(Coerce::toInt($value))->toBe(0)
        ->and(Coerce::toFloat($value))->toBe(0.0);
})->with([
    'null' => [null],
    'empty string' => [''],
    'word' => ['abc'],
    'array' => [[1, 2]],
    'bool' => [true],
]);

test('toString passes strings through and stringifies scalars', function (): void {
    expect(Coerce::toString('redis'))->toBe('redis')
        ->and(Coerce::toString(42))->toBe('42')
        ->and(Coerce::toString(1.5))->toBe('1.5');
});

test('toString falls back to the default for anything it cannot represent', function (): void {
    expect(Coerce::toString(null, 'unknown'))->toBe('unknown')
        ->and(Coerce::toString(['a'], 'unknown'))->toBe('unknown')
        ->and(Coerce::toString(new stdClass, 'unknown'))->toBe('unknown');
});

test('toString defaults to an empty string when no fallback is given', function (): void {
    expect(Coerce::toString(null))->toBe('');
});
