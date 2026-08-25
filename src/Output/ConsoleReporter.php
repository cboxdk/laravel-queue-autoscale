<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Timestamped progress reporting to the console the manager was started from.
 *
 * Separate from the log channel on purpose: these lines exist for an operator
 * watching a foreground `queue:autoscale` run and are silent unless the command
 * was invoked verbosely, whereas anything that must survive the session goes to
 * the log channel instead.
 *
 * error() is the one exception, and it has to be: a failure the operator did
 * not think to ask for detail about is exactly the one they need to see.
 *
 * Shared by the manager and its collaborators, so a single console attaches
 * once and every part of a cycle reports through it.
 */
class ConsoleReporter
{
    private ?OutputInterface $output = null;

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function verbose(string $message, string $level = 'info'): void
    {
        if (! $this->output) {
            return;
        }

        if (! $this->output->isVerbose()) {
            return;
        }

        $timestamp = now()->format('H:i:s');
        $prefix = (string) match ($level) {
            'debug' => '<fg=gray>[DEBUG]</>',
            'info' => '<fg=cyan>[INFO]</>',
            'warn' => '<fg=yellow>[WARN]</>',
            'error' => '<fg=red>[ERROR]</>',
            default => '[INFO]',
        };

        $this->output->writeln("[$timestamp] {$prefix} {$message}");
    }

    /**
     * Write a message the operator needs whatever verbosity they asked for.
     *
     * verbose() is gated on -v, which is right for narration and wrong for a
     * failure: a manager whose every cycle throws would print its banner and
     * then nothing, and look perfectly healthy while doing nothing at all.
     */
    public function error(string $message): void
    {
        if (! $this->output) {
            return;
        }

        $timestamp = now()->format('H:i:s');

        $this->output->writeln("[{$timestamp}] <fg=red>[ERROR]</> {$message}");
    }

    public function isVeryVerbose(): bool
    {
        if (! $this->output) {
            return false;
        }

        return $this->output->isVeryVerbose();
    }
}
