<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Output\Contracts\OutputRendererContract;
use Cbox\LaravelQueueAutoscale\Output\Renderers\DefaultOutputRenderer;
use Cbox\LaravelQueueAutoscale\Output\Renderers\QuietOutputRenderer;
use Cbox\LaravelQueueAutoscale\Output\Renderers\VerboseOutputRenderer;
use Cbox\LaravelQueueAutoscale\Support\ManagerProcessLock;
use Illuminate\Console\Command;

class LaravelQueueAutoscaleCommand extends Command
{
    public $signature = 'queue:autoscale
                        {--interval=5 : Evaluation interval in seconds}
                        {--replace : Stop the existing local manager and take over its host lock}';

    public $description = 'Intelligent queue autoscaling daemon with predictive SLA-based scaling';

    public function handle(AutoscaleManager $manager, ManagerProcessLock $lock): int
    {
        if (! AutoscaleConfiguration::isEnabled()) {
            $this->error('Queue autoscale is disabled in config');

            return self::FAILURE;
        }

        $renderer = $this->createRenderer();

        $this->info('Starting Queue Autoscale Manager');
        $this->info('   Manager ID: '.AutoscaleConfiguration::managerId());
        $this->info('   Mode: '.(AutoscaleConfiguration::clusterEnabled() ? 'cluster' : 'single-host'));
        if (AutoscaleConfiguration::clusterEnabled()) {
            $this->info('   Cluster ID: '.AutoscaleConfiguration::clusterAppId());
        }
        $interval = $this->getInterval();
        $this->info('   Evaluation interval: '.$interval.'s');
        $this->line('');

        $replace = $this->option('replace') === true;
        $heldLock = $lock->acquire($replace);

        $manager->configure($this->getInterval());
        $manager->setOutput($this->output);
        $manager->setRenderer($renderer);

        try {
            return $manager->run();
        } finally {
            $heldLock->release();
        }
    }

    private function createRenderer(): OutputRendererContract
    {
        if ($this->output->isQuiet()) {
            return new QuietOutputRenderer;
        }

        if ($this->output->isVerbose()) {
            return new VerboseOutputRenderer($this->output);
        }

        return new DefaultOutputRenderer($this->output);
    }

    private function getInterval(): int
    {
        $interval = $this->option('interval');

        return is_string($interval) ? (int) $interval : 5;
    }
}
