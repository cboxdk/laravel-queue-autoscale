<?php

use Cbox\LaravelQueueAutoscale\Cluster\CooldownDecision;
use Cbox\LaravelQueueAutoscale\Scaling\DiscoveredWorkloads;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\CpuBreakdown;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MeasuredResourceSample;
use Cbox\LaravelQueueAutoscale\Scaling\DTOs\MemoryBreakdown;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('contracts live in Contracts namespace and are interfaces')
    ->expect('Cbox\LaravelQueueAutoscale\Contracts')
    ->toBeInterfaces();

/*
 * Immutability is enforced; sealing is not. `final` blocks consumers from
 * extending, decorating or subclassing what the package ships, which is
 * friction a library should not impose — so package classes are open, and
 * this rule deliberately asserts only the load-bearing half.
 */
arch('configuration value objects are readonly')
    ->expect([
        'Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\FuseConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration',
    ])
    ->toBeReadonly();

arch('forecast policies are readonly and implement contract')
    ->expect('Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies')
    ->toBeReadonly()
    ->toImplement('Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract');

arch('package classes are not sealed against consumers')
    ->expect('Cbox\LaravelQueueAutoscale')
    ->not->toBeFinal()
    // Enums are implicitly final in PHP; nothing to unseal.
    ->ignoring([
        'Cbox\LaravelQueueAutoscale\Fuse\FuseState',
        'Cbox\LaravelQueueAutoscale\Scaling\DTOs\EstimateSource',
        'Cbox\LaravelQueueAutoscale\Scaling\DTOs\LimitingFactor',
        'Cbox\LaravelQueueAutoscale\Scaling\ScalingScope',
        'Cbox\LaravelQueueAutoscale\Diagnostics\Severity',
    ]);

arch('profiles implement ProfileContract')
    ->expect('Cbox\LaravelQueueAutoscale\Configuration\Profiles')
    ->toImplement('Cbox\LaravelQueueAutoscale\Contracts\ProfileContract');

/*
 * A readonly value object cannot be mocked — Mockery cannot generate a
 * non-readonly subclass — so when one is the declared return type of a public
 * method, a consumer stubbing that method has to build it by hand. Keeping the
 * zero-argument form valid is what makes that possible without reflection.
 */
test('readonly value objects returned by public methods can be constructed with no arguments', function (): void {
    $returned = [
        CpuBreakdown::class,
        MemoryBreakdown::class,
        MeasuredResourceSample::class,
        CooldownDecision::class,
        DiscoveredWorkloads::class,
    ];

    foreach ($returned as $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();

        expect($constructor?->getNumberOfRequiredParameters() ?? 0)
            ->toBe(0, "{$class} is a public return type but needs constructor arguments");
    }
});
