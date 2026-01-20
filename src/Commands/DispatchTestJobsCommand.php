<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Commands;

use Cbox\LaravelQueueAutoscale\Jobs\TestJob;
use Illuminate\Console\Command;

class DispatchTestJobsCommand extends Command
{
    public $signature = 'queue:autoscale:test
                        {count=10 : Number of jobs to dispatch}
                        {--queue=default : Queue name}
                        {--connection= : Queue connection (uses default if not specified)}
                        {--duration=100 : Job duration in milliseconds}
                        {--delay=0 : Delay before job execution in seconds}
                        {--spread=0 : Spread dispatches over N seconds (0 = all at once)}
                        {--fail-rate=0 : Percentage of jobs that should fail (0-100)}
                        {--burst : Dispatch in rapid bursts to simulate traffic spikes}';

    public $description = 'Dispatch test jobs to demonstrate and test autoscaling behavior';

    public function handle(): int
    {
        $countArg = $this->argument('count');
        $count = is_numeric($countArg) ? (int) $countArg : 10;

        $queueOpt = $this->option('queue');
        $queue = is_string($queueOpt) ? $queueOpt : 'default';

        $connectionOpt = $this->option('connection');
        $connection = is_string($connectionOpt) ? $connectionOpt : null;

        $durationOpt = $this->option('duration');
        $durationMs = is_numeric($durationOpt) ? (int) $durationOpt : 100;

        $delayOpt = $this->option('delay');
        $delay = is_numeric($delayOpt) ? (int) $delayOpt : 0;

        $spreadOpt = $this->option('spread');
        $spread = is_numeric($spreadOpt) ? (int) $spreadOpt : 0;

        $failRateOpt = $this->option('fail-rate');
        $failRate = min(100, max(0, is_numeric($failRateOpt) ? (int) $failRateOpt : 0));

        $burst = (bool) $this->option('burst');

        $this->info("Dispatching {$count} test jobs to queue '{$queue}'");
        $this->line("  Duration: {$durationMs}ms per job");

        if ($delay > 0) {
            $this->line("  Delay: {$delay}s before execution");
        }

        if ($failRate > 0) {
            $this->line("  Fail rate: {$failRate}%");
        }

        if ($spread > 0) {
            $this->line("  Spreading over: {$spread}s");
        }

        if ($burst) {
            $this->line('  Mode: Burst (rapid fire)');
        }

        $this->line('');

        $dispatched = 0;
        $sleepBetween = $spread > 0 ? ($spread * 1000000) / $count : 0;

        $progressBar = $this->output->createProgressBar($count);
        $progressBar->start();

        for ($i = 0; $i < $count; $i++) {
            $shouldFail = $failRate > 0 && mt_rand(1, 100) <= $failRate;
            $identifier = sprintf('test_%d_%s', $i + 1, substr(md5((string) microtime(true)), 0, 6));

            $job = new TestJob(
                durationMs: $durationMs,
                shouldFail: $shouldFail,
                identifier: $identifier,
            );

            $job->onQueue($queue);

            if ($connection !== null) {
                $job->onConnection($connection);
            }

            if ($delay > 0) {
                $job->delay(now()->addSeconds($delay));
            }

            dispatch($job);
            $dispatched++;
            $progressBar->advance();

            // Handle spread timing
            if ($sleepBetween > 0 && $i < $count - 1) {
                usleep((int) $sleepBetween);
            }

            // Burst mode: small batches with pauses
            if ($burst && $i > 0 && $i % 10 === 0 && $i < $count - 1) {
                usleep(500000); // 0.5s pause between bursts of 10
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');

        $this->info("Dispatched {$dispatched} jobs successfully");

        $expectedProcessingTime = ($count * $durationMs) / 1000;
        $this->line("  Expected total processing time: {$expectedProcessingTime}s (sequential)");

        return self::SUCCESS;
    }
}
