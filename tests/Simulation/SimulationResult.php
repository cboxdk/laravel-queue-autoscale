<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Tests\Simulation;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

/**
 * Results from a scaling simulation run
 *
 * Provides analysis methods and metrics for evaluating
 * autoscaler performance.
 */
final class SimulationResult
{
    public function __construct(
        private readonly WorkloadSimulator $simulator,
        /** @var array<int, array{tick: int, decision: ScalingDecision, metrics: array<string, mixed>}> */
        private readonly array $decisions,
        private readonly QueueConfiguration $config,
        private readonly int $durationTicks,
    ) {}

    /**
     * Get SLA compliance percentage
     */
    public function getSlaCompliance(): float
    {
        return $this->simulator->calculateSlaCompliance($this->config->sla->targetSeconds);
    }

    /**
     * Check if SLA was never breached
     */
    public function hadNoSlaBreaches(): bool
    {
        return $this->simulator->getPeakJobAge() <= $this->config->sla->targetSeconds;
    }

    /**
     * Get peak job age during simulation
     */
    public function getPeakJobAge(): float
    {
        return $this->simulator->getPeakJobAge();
    }

    /**
     * Get peak backlog during simulation
     */
    public function getPeakBacklog(): float
    {
        return $this->simulator->getPeakBacklog();
    }

    /**
     * Get average workers used
     */
    public function getAverageWorkers(): float
    {
        return $this->simulator->calculateAverageWorkers();
    }

    /**
     * Get average backlog
     */
    public function getAverageBacklog(): float
    {
        return $this->simulator->calculateAverageBacklog();
    }

    /**
     * Get final state
     *
     * @return array{tick: int, backlog: float, oldestJobAge: float, workers: int, totalProcessed: float}
     */
    public function getFinalState(): array
    {
        return $this->simulator->getState();
    }

    /**
     * Get all scaling decisions
     *
     * @return array<int, array{tick: int, decision: ScalingDecision, metrics: array<string, mixed>}>
     */
    public function getDecisions(): array
    {
        return $this->decisions;
    }

    /**
     * Count scale-up events
     */
    public function countScaleUpEvents(): int
    {
        $count = 0;
        foreach ($this->decisions as $data) {
            if ($data['decision']->shouldScaleUp()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count scale-down events
     */
    public function countScaleDownEvents(): int
    {
        $count = 0;
        foreach ($this->decisions as $data) {
            if ($data['decision']->shouldScaleDown()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count hold events
     */
    public function countHoldEvents(): int
    {
        $count = 0;
        foreach ($this->decisions as $data) {
            if ($data['decision']->shouldHold()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get max workers reached during simulation
     */
    public function getMaxWorkersReached(): int
    {
        $history = $this->simulator->getHistory();
        if (empty($history)) {
            return 0;
        }

        return (int) max(array_column($history, 'workers'));
    }

    /**
     * Get min workers during simulation
     */
    public function getMinWorkersReached(): int
    {
        $history = $this->simulator->getHistory();
        if (empty($history)) {
            return 0;
        }

        return (int) min(array_column($history, 'workers'));
    }

    /**
     * Calculate worker efficiency (processed jobs / worker-seconds)
     */
    public function getWorkerEfficiency(): float
    {
        $totalWorkerSeconds = 0;
        foreach ($this->simulator->getHistory() as $tick) {
            $totalWorkerSeconds += $tick['workers'];
        }

        if ($totalWorkerSeconds === 0) {
            return 0.0;
        }

        $state = $this->simulator->getState();

        return $state['totalProcessed'] / $totalWorkerSeconds;
    }

    /**
     * Get time to first scale-up from initial state
     */
    public function getTimeToFirstScaleUp(): ?int
    {
        foreach ($this->decisions as $tick => $data) {
            if ($data['decision']->shouldScaleUp()) {
                return $tick;
            }
        }

        return null;
    }

    /**
     * Get simulation history
     *
     * @return array<int, array{tick: int, arrivals: float, processed: float, backlog: float, workers: int, oldestAge: float}>
     */
    public function getHistory(): array
    {
        return $this->simulator->getHistory();
    }

    /**
     * Get duration in ticks
     */
    public function getDuration(): int
    {
        return $this->durationTicks;
    }

    /**
     * Check if backlog was fully drained at end
     */
    public function isBacklogDrained(): bool
    {
        return $this->simulator->getBacklog() < 1.0;
    }

    /**
     * Calculate response time to a spike (ticks until workers increased after spike start)
     *
     * @param  int  $spikeTick  When the spike started
     */
    public function getResponseTimeToSpike(int $spikeTick): ?int
    {
        foreach ($this->decisions as $tick => $data) {
            if ($tick > $spikeTick && $data['decision']->shouldScaleUp()) {
                return $tick - $spikeTick;
            }
        }

        return null;
    }

    /**
     * Get a summary of the simulation
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'duration_ticks' => $this->durationTicks,
            'sla_target' => $this->config->sla->targetSeconds,
            'sla_compliance' => round($this->getSlaCompliance(), 1),
            'peak_job_age' => round($this->getPeakJobAge(), 1),
            'peak_backlog' => round($this->getPeakBacklog(), 0),
            'average_workers' => round($this->getAverageWorkers(), 1),
            'max_workers' => $this->getMaxWorkersReached(),
            'min_workers' => $this->getMinWorkersReached(),
            'scale_up_events' => $this->countScaleUpEvents(),
            'scale_down_events' => $this->countScaleDownEvents(),
            'worker_efficiency' => round($this->getWorkerEfficiency(), 2),
            'final_backlog' => round($this->simulator->getBacklog(), 0),
        ];
    }

    /**
     * Print a detailed report (for debugging)
     */
    public function printReport(): void
    {
        $summary = $this->getSummary();

        echo "\n=== Simulation Report ===\n";
        echo sprintf("Duration: %d ticks (seconds)\n", $summary['duration_ticks']);
        echo sprintf("SLA Target: %d seconds\n", $summary['sla_target']);
        echo "\n--- Performance ---\n";
        echo sprintf("SLA Compliance: %.1f%%\n", $summary['sla_compliance']);
        echo sprintf("Peak Job Age: %.1f seconds\n", $summary['peak_job_age']);
        echo sprintf("Peak Backlog: %.0f jobs\n", $summary['peak_backlog']);
        echo "\n--- Workers ---\n";
        echo sprintf("Average Workers: %.1f\n", $summary['average_workers']);
        echo sprintf("Max Workers: %d\n", $summary['max_workers']);
        echo sprintf("Min Workers: %d\n", $summary['min_workers']);
        echo sprintf("Efficiency: %.2f jobs/worker-second\n", $summary['worker_efficiency']);
        echo "\n--- Scaling Events ---\n";
        echo sprintf("Scale Up: %d\n", $summary['scale_up_events']);
        echo sprintf("Scale Down: %d\n", $summary['scale_down_events']);
        echo sprintf("Final Backlog: %.0f jobs\n", $summary['final_backlog']);
        echo "========================\n\n";
    }
}
