<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Illuminate\Console\Command;

class RestartAutoscaleCommand extends Command
{
    public $signature = 'queue:autoscale:restart';

    public $description = 'Signal queue autoscale managers to gracefully restart';

    public function handle(RestartSignal $restartSignal): int
    {
        $restartSignal->issue();

        $this->info('Broadcasting queue autoscale restart signal.');
        $this->line('Supervised managers will exit after the current evaluation tick and restart with fresh code/config.');

        return self::SUCCESS;
    }
}
