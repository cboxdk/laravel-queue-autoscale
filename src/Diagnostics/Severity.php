<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Diagnostics;

/**
 * How much a diagnostic finding should worry the reader.
 *
 * The distinction that matters is between "this is wrong" and "this is a
 * choice with a consequence you may not have intended". A configuration can be
 * entirely valid and still quietly do something other than what its author
 * meant — a glob claiming a queue it was never aimed at, a cap that means
 * something different without cluster mode — and those need saying without
 * being dressed up as errors.
 */
enum Severity: string
{
    /** Broken: this configuration cannot work as written. */
    case Error = 'error';

    /** Valid, but very likely not what was intended. */
    case Warning = 'warning';

    /** Working as designed, and worth knowing about. */
    case Notice = 'notice';

    public function label(): string
    {
        return match ($this) {
            self::Error => 'ERROR',
            self::Warning => 'WARN',
            self::Notice => 'NOTE',
        };
    }

    /**
     * The Symfony console tag used to colour this severity.
     */
    public function style(): string
    {
        return match ($this) {
            self::Error => 'error',
            self::Warning => 'comment',
            self::Notice => 'info',
        };
    }

    /**
     * Whether a finding at this severity should fail the command.
     */
    public function isFailure(): bool
    {
        return $this === self::Error;
    }
}
