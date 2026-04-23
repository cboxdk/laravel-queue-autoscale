<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Illuminate\Console\Command;

class RestartAutoscaleCommand extends Command
{
    public $signature = 'queue:autoscale:restart';

    public $description = 'Signal the queue autoscale manager to gracefully restart';

    public function handle(RestartSignal $restartSignal): int
    {
        $restartSignal->issue();

        $this->info('Broadcasting queue autoscale restart signal.');
        $this->line('The supervised manager will exit after the current evaluation tick and restart with fresh code/config.');

        return self::SUCCESS;
    }
}
