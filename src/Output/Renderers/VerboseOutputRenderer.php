<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\Renderers;

use Cbox\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\QueueStats;
use Symfony\Component\Console\Output\OutputInterface;

final class VerboseOutputRenderer implements OutputRendererContract
{
    private int $verbosityLevel;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
        $this->verbosityLevel = match (true) {
            $output->isDebug() => 4,
            $output->isVeryVerbose() => 3,
            $output->isVerbose() => 2,
            default => 1,
        };
    }

    public function initialize(): void
    {
        // No special initialization
    }

    public function render(OutputData $data): void
    {
        foreach ($data->queueStats as $stats) {
            $this->renderQueueStats($stats);
        }

        foreach ($data->scalingLog as $logEntry) {
            $this->writeln($logEntry, 'info');
        }
    }

    private function renderQueueStats(QueueStats $stats): void
    {
        $this->writeln("Evaluating queue: {$stats->key()}", 'debug');
        $this->writeln(
            sprintf(
                '  Metrics: pending=%d, oldest_age=%ds, active_workers=%d, throughput=%.1f/min',
                $stats->pending,
                $stats->oldestJobAge,
                $stats->activeWorkers,
                $stats->throughputPerMinute
            ),
            'debug'
        );

        if ($stats->isBreaching()) {
            $this->writeln(
                sprintf(
                    '  SLA BREACH: oldest_age=%ds >= SLA=%ds',
                    $stats->oldestJobAge,
                    $stats->slaTarget
                ),
                'error'
            );
        } elseif ($stats->isWarning()) {
            $this->writeln(
                sprintf(
                    '  SLA WARNING: oldest_age=%ds approaching SLA=%ds',
                    $stats->oldestJobAge,
                    $stats->slaTarget
                ),
                'warn'
            );
        }

        $this->writeln(
            sprintf(
                '  Decision: %d -> %d workers',
                $stats->activeWorkers,
                $stats->targetWorkers
            ),
            'info'
        );
    }

    private function writeln(string $message, string $level): void
    {
        $minLevel = match ($level) {
            'debug' => 3,
            'info' => 2,
            'warn' => 1,
            'error' => 1,
            default => 1,
        };

        if ($this->verbosityLevel < $minLevel) {
            return;
        }

        $timestamp = now()->format('H:i:s');
        $prefix = match ($level) {
            'debug' => '<fg=gray>[DEBUG]</>',
            'info' => '<fg=cyan>[INFO]</>',
            'warn' => '<fg=yellow>[WARN]</>',
            'error' => '<fg=red>[ERROR]</>',
            default => '[INFO]',
        };

        $this->output->writeln("[{$timestamp}] {$prefix} {$message}");
    }

    public function handleWorkerOutput(int $pid, string $line): void
    {
        // Verbose mode shows worker output at -vvv level
        if ($this->verbosityLevel >= 4 && trim($line) !== '') {
            $this->output->writeln("<fg=gray>[Worker PID {$pid}]</> {$line}");
        }
    }

    public function shutdown(): void
    {
        $this->output->writeln('<fg=yellow>Shutdown complete</>');
    }
}
