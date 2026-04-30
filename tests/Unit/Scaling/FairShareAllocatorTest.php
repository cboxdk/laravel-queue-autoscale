<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\FairShareAllocator;

it('returns demands unchanged when total demand is within capacity', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:fast' => 5,
        'queue:redis:slow' => 3,
    ];
    $configs = [
        'queue:redis:fast' => ['min' => 1, 'max' => 10],
        'queue:redis:slow' => ['min' => 1, 'max' => 10],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result)->toBe(['queue:redis:fast' => 5, 'queue:redis:slow' => 3]);
});

it('returns demands unchanged when total demand equals capacity', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:a' => 5,
        'queue:redis:b' => 5,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 1, 'max' => 10],
        'queue:redis:b' => ['min' => 1, 'max' => 10],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result)->toBe(['queue:redis:a' => 5, 'queue:redis:b' => 5]);
});

it('returns all zeros when all demands are zero', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:a' => 0,
        'queue:redis:b' => 0,
        'queue:redis:c' => 0,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 0, 'max' => 10],
        'queue:redis:b' => ['min' => 0, 'max' => 10],
        'queue:redis:c' => ['min' => 0, 'max' => 10],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result)->toBe(['queue:redis:a' => 0, 'queue:redis:b' => 0, 'queue:redis:c' => 0]);
});

it('handles single queue demanding more than capacity', function () {
    $allocator = new FairShareAllocator;

    $demands = ['queue:redis:only' => 20];
    $configs = ['queue:redis:only' => ['min' => 1, 'max' => 50]];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result)->toBe(['queue:redis:only' => 10]);
});

it('returns empty array when given empty inputs', function () {
    $allocator = new FairShareAllocator;

    $result = $allocator->allocate([], [], 10);

    expect($result)->toBe([]);
});
