<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale;

use Cbox\LaravelQueueAutoscale\Commands\DebugQueueCommand;
use Cbox\LaravelQueueAutoscale\Commands\DispatchTestJobsCommand;
use Cbox\LaravelQueueAutoscale\Commands\LaravelQueueAutoscaleCommand;
use Cbox\LaravelQueueAutoscale\Commands\MigrateConfigCommand;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Manager\SignalHandler;
use Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

class LaravelQueueAutoscaleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/queue-autoscale.php',
            'queue-autoscale'
        );

        // Register calculators
        $this->app->singleton(LittlesLawCalculator::class);
        $this->app->singleton(BacklogDrainCalculator::class);
        $this->app->singleton(CapacityCalculator::class);
        $this->app->singleton(ArrivalRateEstimator::class);

        // Register v2 contracts with their default implementations
        $this->app->singleton(SpawnLatencyTrackerContract::class, EmaSpawnLatencyTracker::class);
        $this->app->singleton(PickupTimeStoreContract::class, RedisPickupTimeStore::class);
        $this->app->singleton(PercentileCalculatorContract::class, SortBasedPercentileCalculator::class);

        // Register scaling strategy from config
        $this->app->singleton(ScalingStrategyContract::class, function ($app) {
            $strategyClass = AutoscaleConfiguration::strategyClass();

            return $app->make($strategyClass);
        });

        // Register scaling engine
        $this->app->singleton(ScalingEngine::class);

        // Register worker management
        $this->app->singleton(WorkerSpawner::class);
        $this->app->singleton(WorkerTerminator::class);

        // Register policies
        $this->app->singleton(PolicyExecutor::class);

        // Register manager
        $this->app->singleton(SignalHandler::class);
        $this->app->singleton(AutoscaleManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/queue-autoscale.php' => config_path('queue-autoscale.php'),
            ], 'queue-autoscale-config');

            $this->commands([
                LaravelQueueAutoscaleCommand::class,
                DispatchTestJobsCommand::class,
                DebugQueueCommand::class,
                MigrateConfigCommand::class,
            ]);
        }

        $this->app->make(Dispatcher::class)->listen(
            JobProcessing::class,
            PickupTimeRecorder::class,
        );
    }
}
