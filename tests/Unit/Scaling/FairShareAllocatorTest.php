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

it('distributes proportionally when demand exceeds capacity with equal demands', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:a' => 7,
        'queue:redis:b' => 7,
        'queue:redis:c' => 7,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 1, 'max' => 20],
        'queue:redis:b' => ['min' => 1, 'max' => 20],
        'queue:redis:c' => ['min' => 1, 'max' => 20],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    // Total must equal capacity
    expect(array_sum($result))->toBe(10);

    // Each queue gets at least min
    expect($result['queue:redis:a'])->toBeGreaterThanOrEqual(1)
        ->and($result['queue:redis:b'])->toBeGreaterThanOrEqual(1)
        ->and($result['queue:redis:c'])->toBeGreaterThanOrEqual(1);

    // Deterministic: sorted by key, equal fractionals → a gets extra
    // min=1 each (3 used), remaining=7, each headroom=6, share=7/3=2.33
    // floor: 1+2=3 each (3 used + 6 = 9), leftover=1 → a gets it (highest frac, tie-break by key)
    expect($result)->toBe([
        'queue:redis:a' => 4,
        'queue:redis:b' => 3,
        'queue:redis:c' => 3,
    ]);
});

it('distributes proportionally with unequal demands', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:high' => 20,
        'queue:redis:low' => 5,
    ];
    $configs = [
        'queue:redis:high' => ['min' => 1, 'max' => 50],
        'queue:redis:low' => ['min' => 1, 'max' => 50],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect(array_sum($result))->toBe(10);
    // high has 19 headroom above min, low has 4 headroom above min
    // high gets proportionally more
    expect($result['queue:redis:high'])->toBeGreaterThan($result['queue:redis:low']);
});

it('gives idle queue nothing and backlogged queue gets full capacity', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:idle1' => 0,
        'queue:redis:idle2' => 0,
        'queue:redis:idle3' => 0,
        'queue:redis:busy' => 20,
    ];
    $configs = [
        'queue:redis:idle1' => ['min' => 0, 'max' => 10],
        'queue:redis:idle2' => ['min' => 0, 'max' => 10],
        'queue:redis:idle3' => ['min' => 0, 'max' => 10],
        'queue:redis:busy' => ['min' => 0, 'max' => 50],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result)->toBe([
        'queue:redis:idle1' => 0,
        'queue:redis:idle2' => 0,
        'queue:redis:idle3' => 0,
        'queue:redis:busy' => 10,
    ]);
});

it('guarantees min workers even under heavy contention', function () {
    $allocator = new FairShareAllocator;

    // 5 queues each min=1, cluster cap 5, one queue demands 100
    $demands = [
        'queue:redis:a' => 1,
        'queue:redis:b' => 1,
        'queue:redis:c' => 1,
        'queue:redis:d' => 1,
        'queue:redis:hog' => 100,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 1, 'max' => 10],
        'queue:redis:b' => ['min' => 1, 'max' => 10],
        'queue:redis:c' => ['min' => 1, 'max' => 10],
        'queue:redis:d' => ['min' => 1, 'max' => 10],
        'queue:redis:hog' => ['min' => 1, 'max' => 200],
    ];

    $result = $allocator->allocate($demands, $configs, 5);

    // All queues get their min
    expect($result['queue:redis:a'])->toBeGreaterThanOrEqual(1)
        ->and($result['queue:redis:b'])->toBeGreaterThanOrEqual(1)
        ->and($result['queue:redis:c'])->toBeGreaterThanOrEqual(1)
        ->and($result['queue:redis:d'])->toBeGreaterThanOrEqual(1)
        ->and($result['queue:redis:hog'])->toBeGreaterThanOrEqual(1);

    // Total does not exceed capacity
    expect(array_sum($result))->toBeLessThanOrEqual(5);
});

it('returns mins even when they exceed capacity', function () {
    $allocator = new FairShareAllocator;

    // 5 queues min=3 each, capacity=10 — mins alone = 15 > 10
    $demands = [
        'queue:redis:a' => 5,
        'queue:redis:b' => 5,
        'queue:redis:c' => 5,
        'queue:redis:d' => 5,
        'queue:redis:e' => 5,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 3, 'max' => 10],
        'queue:redis:b' => ['min' => 3, 'max' => 10],
        'queue:redis:c' => ['min' => 3, 'max' => 10],
        'queue:redis:d' => ['min' => 3, 'max' => 10],
        'queue:redis:e' => ['min' => 3, 'max' => 10],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    // Each queue gets exactly its min — mins are hard guarantees
    expect($result['queue:redis:a'])->toBe(3)
        ->and($result['queue:redis:b'])->toBe(3)
        ->and($result['queue:redis:c'])->toBe(3)
        ->and($result['queue:redis:d'])->toBe(3)
        ->and($result['queue:redis:e'])->toBe(3);

    // Total exceeds capacity — cluster is undersized, scale signal will flag it
    expect(array_sum($result))->toBe(15);
});

it('caps at workers max and redistributes freed capacity', function () {
    $allocator = new FairShareAllocator;

    // A demands 15 but max=5, B demands 8 with max=20
    // Proportional share with headroom: A headroom=4, B headroom=7, total=11
    // A gets floor(4*8/11)=2 + min=1 = 3+1=4 via remainder, B gets floor(7*8/11)=5 + min=1 = 6
    $demands = [
        'queue:redis:a' => 15,
        'queue:redis:b' => 8,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 1, 'max' => 5],
        'queue:redis:b' => ['min' => 1, 'max' => 20],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result)->toBe([
        'queue:redis:a' => 4,
        'queue:redis:b' => 6,
    ]);
});

it('handles multiple queues hitting max in sequence', function () {
    $allocator = new FairShareAllocator;

    // A max=3, B max=3, C max=20, all demand 15, capacity=10
    // Headroom: A=2, B=2, C=14, total=18, remaining=7
    // Proportional share: A=0.78, B=0.78, C=5.44
    // After floor + remainder: A=2, B=2, C=6
    $demands = [
        'queue:redis:a' => 15,
        'queue:redis:b' => 15,
        'queue:redis:c' => 15,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 1, 'max' => 3],
        'queue:redis:b' => ['min' => 1, 'max' => 3],
        'queue:redis:c' => ['min' => 1, 'max' => 20],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result['queue:redis:a'])->toBe(2)
        ->and($result['queue:redis:b'])->toBe(2)
        ->and($result['queue:redis:c'])->toBe(6);

    expect(array_sum($result))->toBe(10);
});

it('does not exceed demand even when capacity is available', function () {
    $allocator = new FairShareAllocator;

    // A demands 3, B demands 15, capacity=10
    // Headroom: A=2 (demand 3 - min 1), B=14 (demand 15 - min 1), total=16, remaining=8
    // Proportional: A share=2*(8/16)=1, B share=14*(8/16)=7 → A=2, B=8
    $demands = [
        'queue:redis:a' => 3,
        'queue:redis:b' => 15,
    ];
    $configs = [
        'queue:redis:a' => ['min' => 1, 'max' => 20],
        'queue:redis:b' => ['min' => 1, 'max' => 20],
    ];

    $result = $allocator->allocate($demands, $configs, 10);

    expect($result['queue:redis:a'])->toBe(2)
        ->and($result['queue:redis:b'])->toBe(8);

    expect(array_sum($result))->toBe(10);
});

it('produces deterministic results regardless of input order', function () {
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:z' => 7,
        'queue:redis:a' => 7,
        'queue:redis:m' => 7,
    ];
    $configs = [
        'queue:redis:z' => ['min' => 1, 'max' => 20],
        'queue:redis:a' => ['min' => 1, 'max' => 20],
        'queue:redis:m' => ['min' => 1, 'max' => 20],
    ];

    $result1 = $allocator->allocate($demands, $configs, 10);

    // Reverse input order
    $demandsReversed = array_reverse($demands, true);
    $configsReversed = array_reverse($configs, true);
    $result2 = $allocator->allocate($demandsReversed, $configsReversed, 10);

    // Both should produce identical results
    ksort($result1);
    ksort($result2);
    expect($result1)->toBe($result2);

    // Total must equal capacity
    expect(array_sum($result1))->toBe(10);

    // Tie-break by key ascending: 'a' gets the extra worker
    expect($result1['queue:redis:a'])->toBe(4)
        ->and($result1['queue:redis:m'])->toBe(3)
        ->and($result1['queue:redis:z'])->toBe(3);
});
