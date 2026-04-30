<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling\DTOs;

enum EstimateSource: string
{
    case Measured = 'measured';
    case Config = 'config';
    case Default = 'default';
}
