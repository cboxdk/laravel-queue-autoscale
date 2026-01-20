<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

final class WorkerOutputBuffer
{
    /** @var array<int, string> Partial line buffers per PID */
    private array $buffers = [];

    /**
     * Collect available output from all workers without blocking
     *
     * @param  iterable<WorkerProcess>  $workers
     * @return array<int, array<int, string>> Lines grouped by PID
     */
    public function collectOutput(iterable $workers): array
    {
        $output = [];

        foreach ($workers as $worker) {
            $pid = $worker->pid();
            if ($pid === null || ! $worker->isRunning()) {
                continue;
            }

            $stdout = $worker->getIncrementalOutput();

            if ($stdout === '') {
                continue;
            }

            $buffer = $this->buffers[$pid] ?? '';
            $buffer .= $stdout;

            $lines = explode("\n", $buffer);

            if (! str_ends_with($stdout, "\n")) {
                $this->buffers[$pid] = array_pop($lines);
            } else {
                $this->buffers[$pid] = '';
                if (end($lines) === '') {
                    array_pop($lines);
                }
            }

            $lines = array_filter($lines, fn (string $line) => trim($line) !== '');

            if (! empty($lines)) {
                $output[$pid] = array_values($lines);
            }
        }

        return $output;
    }

    public function clearBuffer(int $pid): void
    {
        unset($this->buffers[$pid]);
    }

    public function clearAllBuffers(): void
    {
        $this->buffers = [];
    }
}
