<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale;

use Cbox\LaravelQueueAutoscale\Commands\DebugQueueCommand;
use Cbox\LaravelQueueAutoscale\Commands\DispatchTestJobsCommand;
use Cbox\LaravelQueueAutoscale\Commands\LaravelQueueAutoscaleCommand;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Manager\SignalHandler;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;
use Cbox\LaravelQueueAutoscale\Workers\WorkerTerminator;
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
            ]);
        }
    }
}
