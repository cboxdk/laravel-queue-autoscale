<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Workers;

class WorkerOutputBuffer
{
    /**
     * How much of an unterminated line is held before it is flushed truncated.
     *
     * Generous enough that a real stack-trace line survives intact, small
     * enough that a worker streaming without newlines cannot exhaust the
     * manager it reports to.
     */
    private const MAX_PARTIAL_LINE_BYTES = 65536;

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

            // A worker that has already exited is deliberately still drained.
            // The stderr of a worker that OOMed or fatalled is the stderr an
            // operator most needs, and Symfony keeps it buffered after exit —
            // skipping dead workers here meant the manager reported only
            // "Removed dead worker" and dropped the stack trace that explained
            // it. cleanupDeadWorkers() clears the buffer immediately after this
            // runs, so this is the last chance to read it.
            if ($pid === null) {
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

            // A worker that has already exited is deliberately still drained.
            // The stderr of a worker that OOMed or fatalled is the stderr an
            // operator most needs, and Symfony keeps it buffered after exit —
            // skipping dead workers here meant the manager reported only
            // "Removed dead worker" and dropped the stack trace that explained
            // it. cleanupDeadWorkers() clears the buffer immediately after this
            // runs, so this is the last chance to read it.
            if ($pid === null) {
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
            $partial = (string) array_pop($lines);

            // Cap the held remainder. A worker emitting a large blob with no
            // trailing newline — a progress bar redrawing with \r, a dumped
            // payload — would otherwise grow this buffer for its whole
            // lifetime, which is the same unbounded retention this class was
            // written to stop, just moved from Symfony's buffer into ours.
            // Truncating loses the tail of one pathological line; not
            // truncating loses the manager.
            if (strlen($partial) > self::MAX_PARTIAL_LINE_BYTES) {
                // Cut on a character boundary. Worker output is application
                // log text, so a byte-wise substr can land mid-sequence and
                // emit invalid UTF-8 into the log channel. mb_strcut respects
                // the boundary and still counts bytes, which is what the cap
                // is denominated in.
                $lines[] = mb_strcut($partial, 0, self::MAX_PARTIAL_LINE_BYTES, 'UTF-8').' …[truncated]';
                $partial = '';
            }

            $buffers[$pid] = $partial;
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
