<?php

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
        'Cbox\LaravelQueueAutoscale\Diagnostics\Severity',
    ]);

arch('profiles implement ProfileContract')
    ->expect('Cbox\LaravelQueueAutoscale\Configuration\Profiles')
    ->toImplement('Cbox\LaravelQueueAutoscale\Contracts\ProfileContract');
