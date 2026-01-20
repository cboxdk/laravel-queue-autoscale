<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Output\Renderers;

use Cbox\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use Cbox\LaravelQueueAutoscale\Output\DataTransferObjects\OutputData;
use Symfony\Component\Console\Output\OutputInterface;

final class DefaultOutputRenderer implements OutputRendererContract
{
    /** @var array<int, int> Maps worker PID to display ID */
    private array $workerIds = [];

    private int $nextWorkerId = 1;

    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    public function initialize(): void
    {
        // No special initialization needed
    }

    public function render(OutputData $data): void
    {
        // Default mode only shows worker output via handleWorkerOutput()
        // The render() method is essentially a no-op for default mode
    }

    public function handleWorkerOutput(int $pid, string $line): void
    {
        if (! isset($this->workerIds[$pid])) {
            $this->workerIds[$pid] = $this->nextWorkerId++;
        }

        $workerId = $this->workerIds[$pid];

        $formattedLine = $this->formatWorkerLine($workerId, $line);

        if ($formattedLine !== null) {
            $this->output->writeln($formattedLine);
        }
    }

    private function formatWorkerLine(int $workerId, string $line): ?string
    {
        if (trim($line) === '') {
            return null;
        }

        return sprintf('<fg=cyan>[Worker %d]</> %s', $workerId, $line);
    }

    public function shutdown(): void
    {
        // No cleanup needed
    }

    public function removeWorker(int $pid): void
    {
        unset($this->workerIds[$pid]);
    }
}
