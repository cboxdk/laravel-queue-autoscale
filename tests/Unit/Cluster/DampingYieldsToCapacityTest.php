<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Cluster\EvaluatedWorkload;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Manager\AutoscaleManager;
use Illuminate\Support\Carbon;

/**
 * Anti-flapping must not squat on capacity somebody else was allocated.
 *
 * "A scale-up is never held" is a promise about the damper, and on its own it
 * is not worth much: fair share runs first, the damper then republishes a held
 * workload ABOVE its allocation, and the distributor hands out hosts in order
 * and stops when they fill. The workload at the end of the queue is refused
 * capacity it was promised — by a neighbour declining to shrink, not by the
 * damper that never touched it.
 *
 * These drive the leader's real damping pass rather than a copy of its
 * arithmetic, so a change to the rule has to break them.
 */
function dampCluster(AutoscaleManager $manager, array $allocated, array $currentWorkers, int $clusterCapacity): array
{
    $meta = [];

    foreach ($allocated as $workloadKey => $target) {
        $queue = substr($workloadKey, strrpos($workloadKey, ':') + 1);

        $meta[$workloadKey] = new EvaluatedWorkload(
            isGroup: false,
            connection: 'redis',
            name: $queue,
            driver: 'redis',
            config: QueueConfiguration::fromConfig('redis', $queue),
            currentWorkers: $currentWorkers[$workloadKey],
            metrics: createMetrics(['connection' => 'redis', 'queue' => $queue]),
            memberQueues: [$queue],
        );
    }

    $decisions = (new ReflectionMethod($manager, 'dampClusterTargets'))
        ->invoke($manager, $allocated, $meta, $clusterCapacity);

    return array_map(static fn ($decision): int => $decision->targetWorkers, $decisions);
}

function primeHold(AutoscaleManager $manager, string $workloadKey, int $from, int $to): void
{
    $cooldown = (new ReflectionProperty($manager, 'cooldown'))->getValue($manager);
    $cooldown->apply($workloadKey, $from, $to, false, 60);
}

beforeEach(function (): void {
    config()->set('queue-autoscale.cluster.enabled', true);
    config()->set('queue-autoscale.scaling.cooldown_seconds', 60);
    config()->set('queue-autoscale.queues', [
        'a' => ['workers' => ['min' => 0, 'max' => 20]],
        'b' => ['workers' => ['min' => 0, 'max' => 20]],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a hold gives capacity back when the cluster is full', function (): void {
    $manager = app(AutoscaleManager::class);

    // A rose to 10 a moment ago, so its withdrawal is a damped reversal.
    primeHold($manager, 'queue:redis:a', from: 4, to: 10);

    Carbon::setTestNow(now()->addSeconds(5));

    // Fair share has wound A down to 1 and promised the freed capacity to B.
    $result = dampCluster(
        $manager,
        allocated: ['queue:redis:a' => 1, 'queue:redis:b' => 9],
        currentWorkers: ['queue:redis:a' => 10, 'queue:redis:b' => 0],
        clusterCapacity: 10,
    );

    expect(array_sum($result))->toBe(10)
        ->and($result['queue:redis:b'])->toBe(9)
        ->and($result['queue:redis:a'])->toBe(1);
});

test('a hold keeps its surplus when there is room for it', function (): void {
    // The yield is conditional. With capacity to spare the hold is untouched,
    // otherwise this would quietly disable anti-flapping altogether.
    $manager = app(AutoscaleManager::class);

    primeHold($manager, 'queue:redis:a', from: 4, to: 10);

    Carbon::setTestNow(now()->addSeconds(5));

    $result = dampCluster(
        $manager,
        allocated: ['queue:redis:a' => 1, 'queue:redis:b' => 4],
        currentWorkers: ['queue:redis:a' => 10, 'queue:redis:b' => 0],
        clusterCapacity: 40,
    );

    expect($result['queue:redis:a'])->toBe(10)
        ->and($result['queue:redis:b'])->toBe(4);
});

test('an undamped workload is never raided to pay for the overshoot', function (): void {
    // Only the surplus a hold created is available to give back. B asked for
    // what it was allocated and must keep all of it.
    $manager = app(AutoscaleManager::class);

    primeHold($manager, 'queue:redis:a', from: 2, to: 12);

    Carbon::setTestNow(now()->addSeconds(5));

    $result = dampCluster(
        $manager,
        allocated: ['queue:redis:a' => 0, 'queue:redis:b' => 6],
        currentWorkers: ['queue:redis:a' => 12, 'queue:redis:b' => 6],
        clusterCapacity: 6,
    );

    expect($result['queue:redis:b'])->toBe(6)
        ->and($result['queue:redis:a'])->toBe(0);
});
