<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('contracts live in Contracts namespace and are interfaces')
    ->expect('Cbox\LaravelQueueAutoscale\Contracts')
    ->toBeInterfaces();

arch('configuration value objects are final readonly')
    ->expect([
        'Cbox\LaravelQueueAutoscale\Configuration\SlaConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\ForecastConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\WorkerConfiguration',
        'Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration',
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('forecast policies are final readonly and implement contract')
    ->expect('Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies')
    ->toBeFinal()
    ->toBeReadonly()
    ->toImplement('Cbox\LaravelQueueAutoscale\Contracts\ForecastPolicyContract');

arch('profiles implement ProfileContract')
    ->expect('Cbox\LaravelQueueAutoscale\Configuration\Profiles')
    ->toImplement('Cbox\LaravelQueueAutoscale\Contracts\ProfileContract');
