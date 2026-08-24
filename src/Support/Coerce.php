<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

/**
 * Scalar coercion for values arriving from outside the package's own types:
 * config, decoded JSON, and the metrics package's array payloads.
 *
 * These are deliberately total — an unusable value becomes the zero value
 * rather than throwing — because they sit on the read path of an evaluation
 * cycle, where one malformed field must not abort scaling for every workload.
 */
class Coerce
{
    public static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function toString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }
}
