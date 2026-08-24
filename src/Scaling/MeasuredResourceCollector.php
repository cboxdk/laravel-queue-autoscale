<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MeasuredResourceSample;
use Cbox\LaravelQueueAutoscale\Support\Coerce;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

/**
 * Derives per-queue CPU and memory estimates from completed jobs, so capacity
 * planning uses what workers actually cost rather than a configured guess.
 *
 * CPU is expressed as cores per worker: a job burning 250ms of CPU over a
 * 1000ms wall-clock duration occupies a quarter of a core continuously, so the
 * ratio is the right unit for sizing a pool. Both dimensions are weighted by
 * how many jobs each class processed, so a rare expensive job cannot outvote
 * the bulk of the traffic.
 */
class MeasuredResourceCollector
{
    public function __construct(
        private readonly ResourceEstimateResolver $resolver,
    ) {}

    /**
     * Measure every queue the metrics layer knows about and push the results
     * into the resolver.
     *
     * Returns what was measured so the caller can report it. A metrics backend
     * that cannot be read yields no samples rather than an exception: a missing
     * measurement falls back to the configured estimate, which is a degraded
     * reading, not a reason to abort the cycle.
     *
     * @return list<MeasuredResourceSample>
     */
    public function collect(): array
    {
        try {
            $allJobs = QueueMetrics::getAllJobsWithMetrics();
        } catch (\Throwable) {
            return [];
        }

        $samples = [];

        foreach ($this->accumulate($allJobs) as $key => $totals) {
            [$connection, $queue] = explode(':', $key, 2);

            $sample = new MeasuredResourceSample(
                connection: $connection,
                queue: $queue,
                cpuCores: $totals['cpu_processed'] > 0 ? $totals['cpu_weighted'] / $totals['cpu_processed'] : 0.0,
                cpuSamples: $totals['cpu_processed'],
                memoryMb: $totals['mem_processed'] > 0 ? $totals['mem_weighted'] / $totals['mem_processed'] : 0.0,
                memorySamples: $totals['mem_processed'],
            );

            $this->apply($sample);

            $samples[] = $sample;
        }

        return $samples;
    }

    /**
     * @param  iterable<mixed>  $allJobs
     * @return array<string, array{cpu_weighted: float, mem_weighted: float, cpu_processed: int, mem_processed: int}>
     */
    private function accumulate(iterable $allJobs): array
    {
        $perQueue = [];

        foreach ($allJobs as $jobData) {
            if (! is_array($jobData)) {
                continue;
            }

            $connection = Coerce::toString($jobData['connection'] ?? null, 'default');
            $queue = Coerce::toString($jobData['queue'] ?? null, 'default');
            $key = "{$connection}:{$queue}";

            $cpu = is_array($jobData['cpu'] ?? null) ? $jobData['cpu'] : [];
            $duration = is_array($jobData['duration'] ?? null) ? $jobData['duration'] : [];
            $memory = is_array($jobData['memory'] ?? null) ? $jobData['memory'] : [];
            $execution = is_array($jobData['execution'] ?? null) ? $jobData['execution'] : [];

            $cpuAvgMs = Coerce::toFloat($cpu['avg'] ?? null);
            $durationAvgMs = Coerce::toFloat($duration['avg'] ?? null);
            $memAvgMb = Coerce::toFloat($memory['avg'] ?? null);
            $processed = Coerce::toInt($execution['total_processed'] ?? null);

            if ($processed <= 0) {
                continue;
            }

            if (! isset($perQueue[$key])) {
                $perQueue[$key] = ['cpu_weighted' => 0.0, 'mem_weighted' => 0.0, 'cpu_processed' => 0, 'mem_processed' => 0];
            }

            if ($durationAvgMs > 0 && $cpuAvgMs > 0) {
                $coresPerWorker = $cpuAvgMs / $durationAvgMs;
                $perQueue[$key]['cpu_weighted'] += $coresPerWorker * $processed;
                $perQueue[$key]['cpu_processed'] += $processed;
            }

            if ($memAvgMb > 0) {
                $perQueue[$key]['mem_weighted'] += $memAvgMb * $processed;
                $perQueue[$key]['mem_processed'] += $processed;
            }
        }

        return $perQueue;
    }

    private function apply(MeasuredResourceSample $sample): void
    {
        if ($sample->hasCpu() && $sample->hasMemory()) {
            $this->resolver->setMeasured(
                $sample->connection,
                $sample->queue,
                $sample->cpuCores,
                $sample->memoryMb,
                $sample->cpuSamples,
                $sample->memorySamples,
            );

            return;
        }

        if ($sample->hasCpu()) {
            $this->resolver->setMeasuredCpu(
                $sample->connection,
                $sample->queue,
                $sample->cpuCores,
                $sample->cpuSamples,
            );

            return;
        }

        if ($sample->hasMemory()) {
            $this->resolver->setMeasuredMemory(
                $sample->connection,
                $sample->queue,
                $sample->memoryMb,
                $sample->memorySamples,
            );
        }
    }
}
