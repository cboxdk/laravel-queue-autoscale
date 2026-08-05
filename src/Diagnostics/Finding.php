<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Diagnostics;

/**
 * One thing the doctor noticed about a configuration.
 *
 * Every finding carries what was observed and what to do about it. A
 * diagnostic that names a problem without naming a fix moves the puzzle rather
 * than solving it, so `remedy` is required rather than optional.
 */
readonly class Finding
{
    public function __construct(
        public Severity $severity,
        public string $title,
        public string $detail,
        public string $remedy,
    ) {}

    public static function error(string $title, string $detail, string $remedy): self
    {
        return new self(Severity::Error, $title, $detail, $remedy);
    }

    public static function warning(string $title, string $detail, string $remedy): self
    {
        return new self(Severity::Warning, $title, $detail, $remedy);
    }

    public static function notice(string $title, string $detail, string $remedy): self
    {
        return new self(Severity::Notice, $title, $detail, $remedy);
    }
}
