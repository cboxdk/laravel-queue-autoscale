<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale;

use Cbox\LaravelQueueAutoscale\Alerting\AlertRateLimiter;
use Cbox\LaravelQueueAutoscale\Cluster\ClusterStore;
use Cbox\LaravelQueueAutoscale\Commands\ClusterAutoscaleCommand;
use Cbox\LaravelQueueAutoscale\Commands\DebugQueueCommand;
use Cbox\LaravelQueueAutoscale\Commands\InstallCommand;
use Cbox\LaravelQueueAutoscale\Commands\LaravelQueueAutoscaleCommand;
use Cbox\LaravelQueueAutoscale\Commands\MigrateConfigCommand;
use Cbox\LaravelQueueAutoscale\Commands\RestartAutoscaleCommand;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ForecasterContract;
use Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract;
use Cbox\LaravelQueueAutoscale\Contracts\PercentileCalculatorContract;
use Cbox\LaravelQueueAutoscale\Contracts\PickupTimeStoreContract;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Manager\SignalHandler;
use Cbox\LaravelQueueAutoscale\Pickup\NullPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\PickupTimeRecorder;
use Cbox\LaravelQueueAutoscale\Pickup\RedisPickupTimeStore;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\ArrivalRateEstimator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\BacklogDrainCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LittlesLawCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Support\ManagerProcessLock;
use Cbox\LaravelQueueAutoscale\Support\RestartSignal;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\EmaSpawnLatencyTracker;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\NullSpawnLatencyTracker;
use Cbox\LaravelQueueAutoscale\Workers\SpawnLatency\SpawnLatencyRecorder;
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

        // Alert rate limiter — reads default cooldown from config so operators
        // can tune it without writing a custom binding.
        $this->app->singleton(AlertRateLimiter::class, function (): AlertRateLimiter {
            $cooldown = config('queue-autoscale.alerting.cooldown_seconds', 300);

            return new AlertRateLimiter(
                cooldownSeconds: is_numeric($cooldown) ? (int) $cooldown : 300,
            );
        });

        // Register calculators
        $this->app->singleton(LittlesLawCalculator::class);
        $this->app->singleton(BacklogDrainCalculator::class);
        $this->app->singleton(CapacityCalculator::class);
        $this->app->singleton(ArrivalRateEstimator::class);

        // Register v2 contracts with their default implementations
        $this->app->singleton(SpawnLatencyTrackerContract::class, function () {
            $trackerClass = $this->resolveSpawnLatencyTrackerClass();

            if (! class_exists($trackerClass) || ! is_subclass_of($trackerClass, SpawnLatencyTrackerContract::class)) {
                throw new \RuntimeException("queue-autoscale.spawn_latency.tracker must be a class that implements SpawnLatencyTrackerContract, got: {$trackerClass}");
            }

            return new $trackerClass;
        });

        $this->app->bind(ForecasterContract::class, LinearRegressionForecaster::class);

        $this->app->bind(ForecastPolicyContract::class, ModerateForecastPolicy::class);

        $this->app->singleton(PickupTimeStoreContract::class, function () {
            $rawClass = $this->resolvePickupTimeStoreClass();
            $rawSamples = config('queue-autoscale.pickup_time.max_samples_per_queue', 1000);
            $maxSamples = is_numeric($rawSamples) ? (int) $rawSamples : 1000;

            if (! class_exists($rawClass) || ! is_subclass_of($rawClass, PickupTimeStoreContract::class)) {
                throw new \RuntimeException("queue-autoscale.pickup_time.store must be a class that implements PickupTimeStoreContract, got: {$rawClass}");
            }

            if ($rawClass === RedisPickupTimeStore::class) {
                return new RedisPickupTimeStore(maxSamplesPerQueue: $maxSamples);
            }

            return new $rawClass;
        });

        $this->app->singleton(PercentileCalculatorContract::class, function () {
            $rawClass = config('queue-autoscale.pickup_time.percentile_calculator', SortBasedPercentileCalculator::class);

            if (! is_string($rawClass) || ! class_exists($rawClass) || ! is_subclass_of($rawClass, PercentileCalculatorContract::class)) {
                $got = is_string($rawClass) ? $rawClass : gettype($rawClass);
                throw new \RuntimeException("queue-autoscale.pickup_time.percentile_calculator must be a class that implements PercentileCalculatorContract, got: {$got}");
            }

            return new $rawClass;
        });

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
        $this->app->singleton(LaravelQueueAutoscale::class);
        $this->app->singleton(ClusterStore::class);
        $this->app->singleton(ManagerProcessLock::class);
        $this->app->singleton(RestartSignal::class);
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
                InstallCommand::class,
                RestartAutoscaleCommand::class,
                ClusterAutoscaleCommand::class,
                DebugQueueCommand::class,
                MigrateConfigCommand::class,
            ]);
        }

        $dispatcher = $this->app->make(Dispatcher::class);

        $dispatcher->listen(
            JobProcessing::class,
            PickupTimeRecorder::class,
        );

        $dispatcher->listen(
            JobProcessing::class,
            SpawnLatencyRecorder::class,
        );
    }

    private function resolvePickupTimeStoreClass(): string
    {
        $configured = AutoscaleConfiguration::pickupTimeStore();

        return match ($configured) {
            '', 'auto' => AutoscaleConfiguration::clusterEnabled()
                ? RedisPickupTimeStore::class
                : NullPickupTimeStore::class,
            'redis' => RedisPickupTimeStore::class,
            'null' => NullPickupTimeStore::class,
            default => $configured,
        };
    }

    private function resolveSpawnLatencyTrackerClass(): string
    {
        $configured = AutoscaleConfiguration::spawnLatencyTracker();

        return match ($configured) {
            '', 'auto' => AutoscaleConfiguration::clusterEnabled()
                ? EmaSpawnLatencyTracker::class
                : NullSpawnLatencyTracker::class,
            'redis' => EmaSpawnLatencyTracker::class,
            'null' => NullSpawnLatencyTracker::class,
            default => $configured,
        };
    }
}
