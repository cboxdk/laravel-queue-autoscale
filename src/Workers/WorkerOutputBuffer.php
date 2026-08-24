<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerOutputBuffer
{
    /** @var array<int, string> Partial stdout line buffers per PID */
    private array $buffers = [];

    /** @var array<int, string> Partial stderr line buffers per PID */
    private array $errorBuffers = [];

    /**
     * Collect available stdout from all workers without blocking
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

            $lines = $this->drain($pid, $worker->getIncrementalOutput(), $this->buffers);
            $worker->clearOutput();

            if ($lines !== []) {
                $output[$pid] = $lines;
            }
        }

        return $output;
    }

    /**
     * Collect available stderr from all workers without blocking
     *
     * @param  iterable<WorkerProcess>  $workers
     * @return array<int, array<int, string>> Lines grouped by PID
     */
    public function collectErrorOutput(iterable $workers): array
    {
        $output = [];

        foreach ($workers as $worker) {
            $pid = $worker->pid();
            if ($pid === null || ! $worker->isRunning()) {
                continue;
            }

            $lines = $this->drain($pid, $worker->getIncrementalErrorOutput(), $this->errorBuffers);
            $worker->clearErrorOutput();

            if ($lines !== []) {
                $output[$pid] = $lines;
            }
        }

        return $output;
    }

    /**
     * Split a stream chunk into complete lines, holding any trailing partial
     * line in the given per-PID buffer until the rest of it arrives.
     *
     * @param  array<int, string>  $buffers
     * @return array<int, string>
     */
    private function drain(int $pid, string $chunk, array &$buffers): array
    {
        if ($chunk === '') {
            return [];
        }

        $buffer = $buffers[$pid] ?? '';
        $buffer .= $chunk;

        $lines = explode("\n", $buffer);

        if (! str_ends_with($chunk, "\n")) {
            $buffers[$pid] = (string) array_pop($lines);
        } else {
            $buffers[$pid] = '';
            if (end($lines) === '') {
                array_pop($lines);
            }
        }

        $lines = array_filter($lines, fn (string $line) => trim($line) !== '');

        return array_values($lines);
    }

    public function clearBuffer(int $pid): void
    {
        unset($this->buffers[$pid], $this->errorBuffers[$pid]);
    }

    public function clearAllBuffers(): void
    {
        $this->buffers = [];
        $this->errorBuffers = [];
    }
}
