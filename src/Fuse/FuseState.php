<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Fuse;

enum FuseState: string
{
    /** Normal operation — the autoscaler scales freely. */
    case Closed = 'closed';

    /** Tripped — the queue is held at workers.min while the outage persists. */
    case Open = 'open';

    /** Probing — one worker runs to test whether the downstream has recovered. */
    case HalfOpen = 'half_open';

    public function isTripped(): bool
    {
        return $this !== self::Closed;
    }
}
