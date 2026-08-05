<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Diagnostics\ConfigurationDoctor;
use Cbox\LaravelQueueAutoscale\Diagnostics\Finding;
use Cbox\LaravelQueueAutoscale\Diagnostics\QueueDiscovery;
use Cbox\LaravelQueueAutoscale\Diagnostics\Severity;
use Illuminate\Console\Command;

/**
 * Reports configurations that are valid and still do the wrong thing.
 *
 * `queue:autoscale:debug` answers "what is this one queue doing right now".
 * This answers the question nobody thinks to ask, because nothing prompts
 * them to: "is this configuration governing the queues I think it is".
 */
class DoctorCommand extends Command
{
    public $signature = 'queue:autoscale:doctor
                        {--strict : Exit non-zero on warnings as well as errors}';

    public $description = 'Check the autoscale configuration against the queues that actually exist';

    public function handle(ConfigurationDoctor $doctor, QueueDiscovery $discovery): int
    {
        $this->info('Queue Autoscale — configuration check');
        $this->newLine();

        try {
            $discovered = $discovery->names();
        } catch (\Throwable $e) {
            $this->error("Could not read queues from the metrics package: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($discovered === []) {
            $this->warn('No queues discovered yet.');
            $this->line(
                '  Queue names come from the metrics package, which sees a queue once it has received '
                .'a job. Until then there is nothing to check configuration against.'
            );

            return self::SUCCESS;
        }

        $this->line('Discovered queues: '.count($discovered));
        $this->newLine();

        $findings = $doctor->diagnose($discovered);

        if ($findings === []) {
            $this->info('✓ Nothing to report.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->render($finding);
        }

        return $this->exitCode($findings);
    }

    private function render(Finding $finding): void
    {
        $tag = $finding->severity->style();

        $this->line("<{$tag}>{$finding->severity->label()}</{$tag}>  {$finding->title}");
        $this->line("        {$finding->detail}");
        $this->line("        <fg=gray>→ {$finding->remedy}</>");
        $this->newLine();
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function exitCode(array $findings): int
    {
        $counts = [];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }

        $summary = [];

        foreach (Severity::cases() as $severity) {
            $count = $counts[$severity->value] ?? 0;

            if ($count > 0) {
                $summary[] = "{$count} ".strtolower($severity->label());
            }
        }

        $this->line(implode(', ', $summary));

        foreach ($findings as $finding) {
            if ($finding->severity->isFailure()) {
                return self::FAILURE;
            }
        }

        if ($this->option('strict')) {
            foreach ($findings as $finding) {
                if ($finding->severity === Severity::Warning) {
                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }
}
