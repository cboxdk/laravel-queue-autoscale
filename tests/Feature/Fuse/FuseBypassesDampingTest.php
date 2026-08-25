<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\CapacityCalculator;
use Cbox\LaravelQueueAutoscale\Scaling\ResourceEstimateResolver;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;
use Cbox\LaravelQueueAutoscale\Testing\InMemoryFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Workers\WorkerProcess;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/**
 * The fuse outranks the anti-flapping damper.
 *
 * Damping a fuse-driven withdrawal is the one case where the guard delays a
 * safety mechanism rather than an ordinary preference. It is also the LIKELY
 * case, not a corner: failing jobs look like load — they retry, they queue, they
 * lengthen the backlog — so the fleet has usually just scaled up at the moment
 * the fuse trips, which is exactly the state that makes the withdrawal a damped
 * direction reversal. The cost is a full-size fleet hammering a dead dependency
 * for the rest of the cooldown window, stacked on top of the fuse's own
 * detection latency.
 *
 * Measured before the bypass: 55 seconds of a 20-worker fleet against a
 * downstream that was already failing 90% of its jobs.
 */
function fuseEngine(FailureWindowStoreContract $store): ScalingEngine
{
    return new ScalingEngine(
        app(HybridStrategy::class),
        new CapacityCalculator,
        new ResourceEstimateResolver,
        new FailureFuse($store),
    );
}

function fuseQueueConfig(): QueueConfiguration
{
    config()->set('queue-autoscale.queues', [
        'exports' => [
            'workers' => ['min' => 2, 'max' => 20],
            'fuse' => ['enabled' => true, 'min_samples' => 20, 'failure_threshold_percent' => 50.0],
        ],
    ]);

    return QueueConfiguration::fromConfig('redis', 'exports');
}

test('a tripped fuse is reported as constraining the queue', function (): void {
    $store = new InMemoryFailureWindowStore;
    $store->seedState('open', microtime(true), queue: 'exports');

    expect(fuseEngine($store)->isFuseConstraining(fuseQueueConfig()))->toBeTrue();
});

test('a half-open fuse still constrains, so its probe is not damped either', function (): void {
    $store = new InMemoryFailureWindowStore;
    $store->seedState('half_open', microtime(true), queue: 'exports');

    expect(fuseEngine($store)->isFuseConstraining(fuseQueueConfig()))->toBeTrue();
});

test('a closed fuse leaves the damper in charge', function (): void {
    // The signal has to be specific to the fuse. If it were true whenever any
    // ceiling applied, the bypass would disable anti-flapping entirely.
    $store = new InMemoryFailureWindowStore;
    $store->seedWindow(total: 200, failures: 0, queue: 'exports');

    expect(fuseEngine($store)->isFuseConstraining(fuseQueueConfig()))->toBeFalse();
});

test('a disabled fuse never constrains', function (): void {
    $store = new InMemoryFailureWindowStore;
    $store->seedState('open', microtime(true), queue: 'exports');

    config()->set('queue-autoscale.queues', [
        'exports' => [
            'workers' => ['min' => 2, 'max' => 20],
            'fuse' => ['enabled' => false],
        ],
    ]);

    expect(fuseEngine($store)->isFuseConstraining(QueueConfiguration::fromConfig('redis', 'exports')))->toBeFalse();
});

/**
 * Drive the real evaluateQueue() with a fleet running, a scale-up remembered,
 * and the fuse in a chosen state. Returns whether the manager got past the
 * anti-flapping gate — it records queue stats immediately after, and returns
 * before doing so when it holds.
 */
function reachesScalingWithFuse(string $fuseState): bool
{
    config()->set('queue.default', 'redis');
    config()->set('queue-autoscale.scaling.cooldown_seconds', 60);
    config()->set('queue-autoscale.queues', [
        'exports' => [
            'workers' => ['min' => 0, 'max' => 20],
            'fuse' => ['enabled' => true, 'min_samples' => 20, 'failure_threshold_percent' => 50.0],
        ],
    ]);

    $store = new InMemoryFailureWindowStore;

    if ($fuseState === 'open') {
        $store->seedState('open', microtime(true), queue: 'exports');
    } else {
        $store->seedWindow(total: 200, failures: 0, queue: 'exports');
    }

    app()->instance(ScalingEngine::class, fuseEngine($store));
    app()->forgetInstance(AutoscaleManager::class);

    $manager = app(AutoscaleManager::class);

    // A fleet that is running, and a rise remembered a moment ago, so the
    // withdrawal below is a damped reversal.
    $pool = (new ReflectionProperty($manager, 'pool'))->getValue($manager);

    for ($worker = 0; $worker < 10; $worker++) {
        // The pool only counts workers it believes are running, and starting
        // ten real `queue:work` children would measure the OS rather than the
        // manager. Package classes are deliberately not final for exactly this.
        $pool->add(new class(new Process(['true']), 'redis', 'exports', now()) extends WorkerProcess
        {
            public function isRunning(): bool
            {
                return true;
            }

            public function isTerminating(): bool
            {
                return false;
            }
        });
    }

    (new ReflectionProperty($manager, 'workloadState'))->getValue($manager)
        ->recordScale('redis:exports', 'up');

    Carbon::setTestNow(now()->addSeconds(5));

    // An empty queue: the engine asks for far fewer workers than are running.
    (new ReflectionMethod($manager, 'evaluateQueue'))->invoke(
        $manager,
        'redis',
        'exports',
        createMetrics(['connection' => 'redis', 'queue' => 'exports', 'active_workers' => 10]),
    );

    $stats = (new ReflectionProperty($manager, 'currentQueueStats'))->getValue($manager);

    return array_key_exists('redis:exports', $stats);
}

test('a tripped fuse gets its withdrawal past the damper', function (): void {
    // The wiring, not the predicate. Remove the fuse check from evaluateQueue
    // and this is the assertion that notices.
    expect(reachesScalingWithFuse('open'))->toBeTrue();
});

test('the same withdrawal is held when the fuse is closed', function (): void {
    // The control. Without it the test above would pass even if the damper had
    // stopped holding anything at all.
    expect(reachesScalingWithFuse('closed'))->toBeFalse();
});
