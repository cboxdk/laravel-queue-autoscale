<?php

namespace Cbox\LaravelQueueAutoscale\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Cbox\LaravelQueueAutoscale\LaravelQueueAutoscale
 */
class LaravelQueueAutoscale extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Cbox\LaravelQueueAutoscale\LaravelQueueAutoscale::class;
    }
}
