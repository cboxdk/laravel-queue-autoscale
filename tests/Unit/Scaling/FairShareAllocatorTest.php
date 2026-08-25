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

it('scales the minimums down when they do not all fit', function () {
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

    // This spec used to assert the sum was 15 — larger than the capacity the
    // allocator was handed. That is not a guarantee the cluster can keep: the
    // caller then placed workloads until hosts filled and silently dropped
    // whatever was left, in metrics-discovery order, which is not stable
    // between cycles. A critical queue could be starved to zero while a bulk
    // queue kept its floor, and the victim changed from cycle to cycle.
    expect(array_sum($result))->toBe(10);

    // The shortfall is spread rather than aimed at whoever was evaluated last.
    foreach ($result as $workers) {
        expect($workers)->toBeGreaterThan(0)->toBeLessThan(3);
    }
});

it('produces the same allocation twice when the minimums do not fit', function () {
    // The point of scaling them down rather than dropping the overflow: the
    // outcome cannot depend on iteration order, so a queue is not starved one
    // cycle and restored the next.
    $allocator = new FairShareAllocator;

    $demands = ['a' => 9, 'b' => 9, 'c' => 9];
    $configs = [
        'a' => ['min' => 4, 'max' => 9],
        'b' => ['min' => 3, 'max' => 9],
        'c' => ['min' => 3, 'max' => 9],
    ];

    $first = $allocator->allocate($demands, $configs, 5);
    $second = $allocator->allocate($demands, $configs, 5);

    expect($first)->toBe($second)
        ->and(array_sum($first))->toBe(5);
});

it('caps at workers max and redistributes freed capacity', function () {
    $allocator = new FairShareAllocator;

    // A demands 15 but max=5, B demands 8 with max=20
    // Without water-filling: A=5, B=4, total=9 (1 wasted)
    // With water-filling: A=5, B=5, total=10
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
        'queue:redis:a' => 5,
        'queue:redis:b' => 5,
    ]);
});

it('handles multiple queues hitting max in sequence', function () {
    $allocator = new FairShareAllocator;

    // A max=3, B max=3, C max=20, all demand 15, capacity=10
    // Iteration 1: uncapped proportional gives each ~3.33, A and B clamped to 3
    // Iteration 2: freed capacity goes to C → C=4
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

    expect($result['queue:redis:a'])->toBe(3)
        ->and($result['queue:redis:b'])->toBe(3)
        ->and($result['queue:redis:c'])->toBe(4);

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

/*
 * The minimum-scaling path claimed in its own comment to break ties by key,
 * and did not — arsort is stable on INSERTION order, and these arrive in
 * metrics-discovery order. With identical floors the remainders tie, so the
 * queue discovered first won and the same cluster starved a different queue
 * depending on the order Redis returned its keys.
 *
 * Measured in an end-to-end simulation: with the discovery order shuffled each
 * cycle, every queue starved about 28% of cycles and the zero-slots migrated,
 * dragging cross-host churn with them.
 */
test('minimum scaling does not depend on discovery order', function (): void {
    $allocator = new FairShareAllocator;

    $keys = ['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'];
    $demands = array_fill_keys($keys, 5);
    $configs = array_fill_keys($keys, ['min' => 1, 'max' => 5]);

    $forward = $allocator->allocate($demands, $configs, 6);

    $reversed = array_reverse($keys);
    $backward = $allocator->allocate(
        array_fill_keys($reversed, 5),
        array_fill_keys($reversed, ['min' => 1, 'max' => 5]),
        6,
    );

    ksort($forward);
    ksort($backward);

    expect($backward)->toBe($forward);
});

/**
 * When the floors do not fit, somebody gets nothing. Who, and for how long, is
 * the whole question.
 *
 * Making the tie-break deterministic stopped the zero-slot wandering between
 * queues on discovery order, but it replaced a rotating victim with a permanent
 * one: identical floors give identical remainders, so the alphabetically-first
 * workloads won every cycle forever. Measured over 720 cycles with six queues
 * and capacity for four, two queues were never served once while holding real
 * backlog. Deterministic starvation is the worse kind, because nothing ends it.
 */
test('no workload is starved permanently when the floors do not fit', function (): void {
    $allocator = new FairShareAllocator;

    $demands = $configs = [];
    foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot'] as $queue) {
        $demands["queue:redis:{$queue}"] = 5;
        $configs["queue:redis:{$queue}"] = ['min' => 5, 'max' => 20];
    }

    $everServed = array_fill_keys(array_keys($demands), false);

    for ($cycle = 0; $cycle < 200; $cycle++) {
        foreach ($allocator->allocate($demands, $configs, 4) as $key => $workers) {
            if ($workers > 0) {
                $everServed[$key] = true;
            }
        }
    }

    expect(array_keys(array_filter($everServed, static fn (bool $served): bool => ! $served)))->toBe([]);
});

test('the allocation holds still between hand-overs', function (): void {
    // The other half: moving the slot every cycle would swap a starved queue
    // for a fleet that spends its life being rebuilt. Consecutive cycles
    // between hand-overs must be byte-identical, or nothing is gained. Measured
    // without the incumbent's margin: 2820 worker moves over 720 cycles.
    $allocator = new FairShareAllocator;

    $demands = $configs = [];
    foreach (['alpha', 'bravo', 'charlie'] as $queue) {
        $demands["queue:redis:{$queue}"] = 4;
        $configs["queue:redis:{$queue}"] = ['min' => 4, 'max' => 20];
    }

    $first = $allocator->allocate($demands, $configs, 2);
    $unchanged = 0;

    for ($cycle = 1; $cycle < 12; $cycle++) {
        if ($allocator->allocate($demands, $configs, 2) === $first) {
            $unchanged++;
        }
    }

    expect($unchanged)->toBe(11);
});

test('each workload receives its proportional share over time', function (): void {
    // Largest-remainder is a fair way to round ONE allocation and an unfair way
    // to repeat one: the smallest share has the smallest remainder, so applied
    // strictly on every cycle it loses the leftover forever. This asserts the
    // property the rounding was approximating — proportionality over time —
    // which is the one that can actually be kept.
    $allocator = new FairShareAllocator;

    // Floors 8/3/3 into capacity 5: exact shares 2.857 / 1.071 / 1.071.
    $demands = [
        'queue:redis:big' => 8,
        'queue:redis:small-a' => 3,
        'queue:redis:small-b' => 3,
    ];
    $configs = [
        'queue:redis:big' => ['min' => 8, 'max' => 20],
        'queue:redis:small-a' => ['min' => 3, 'max' => 20],
        'queue:redis:small-b' => ['min' => 3, 'max' => 20],
    ];

    $received = ['queue:redis:big' => 0, 'queue:redis:small-a' => 0, 'queue:redis:small-b' => 0];

    for ($cycle = 0; $cycle < 600; $cycle++) {
        $allocation = $allocator->allocate($demands, $configs, 5);

        expect(array_sum($allocation))->toBe(5);

        foreach ($allocation as $key => $workers) {
            $received[$key] += $workers;
        }
    }

    $total = array_sum($received);

    // Entitled to 57.1% / 21.4% / 21.4% of 3000 worker-cycles.
    expect($received['queue:redis:big'] / $total)->toBeGreaterThan(0.55)
        ->and($received['queue:redis:big'] / $total)->toBeLessThan(0.59)
        ->and(abs($received['queue:redis:small-a'] - $received['queue:redis:small-b']))->toBeLessThan(120);
});

test('a workload owed a fraction of a worker is served eventually, not never', function (): void {
    // The case a per-cycle largest-remainder rule cannot serve at all: one
    // large floor and one small one, where the small share rounds to zero and
    // its remainder is always the smaller of the two.
    $allocator = new FairShareAllocator;

    $demands = ['queue:redis:bulk' => 10, 'queue:redis:tiny' => 1];
    $configs = [
        'queue:redis:bulk' => ['min' => 10, 'max' => 20],
        'queue:redis:tiny' => ['min' => 1, 'max' => 20],
    ];

    $servedCycles = 0;

    for ($cycle = 0; $cycle < 400; $cycle++) {
        if ($allocator->allocate($demands, $configs, 5)['queue:redis:tiny'] > 0) {
            $servedCycles++;
        }
    }

    // Floors of 10 and 1 into five workers: exact shares 4.545 and 0.4545, so
    // the small queue's floor rounds to nothing and it lives entirely on the
    // single leftover — which it is entitled to about 45% of the time. Before
    // the shortfall was banked it received it exactly never.
    expect($servedCycles)->toBeGreaterThan(150)
        ->and($servedCycles)->toBeLessThan(220);
});

test('the leftover moves across separate allocate() calls on one instance', function (): void {
    // The behavioural half: the guarantee is about repeated calls, not one.
    $allocator = new FairShareAllocator;

    $demands = $configs = [];
    foreach (['alpha', 'bravo', 'charlie'] as $queue) {
        $demands["queue:redis:{$queue}"] = 4;
        $configs["queue:redis:{$queue}"] = ['min' => 4, 'max' => 20];
    }

    $seen = [];

    for ($cycle = 0; $cycle < 60; $cycle++) {
        $seen[json_encode($allocator->allocate($demands, $configs, 2))] = true;
    }

    // A frozen allocation yields exactly one distinct result forever.
    expect(count($seen))->toBeGreaterThan(1);
});

/**
 * A manager that has just taken the lease has no ledger. Opening every balance
 * at zero throws the ordering back to the fractional part and then the key —
 * which is where the alphabetically-first workloads win — so leadership that
 * keeps moving never lets a hand-over complete. Measured with leadership
 * changing every eleven cycles: two of six contending queues went back to never
 * being served at all.
 *
 * The gap between what a workload holds and what it is entitled to is already
 * visible to the leader, and it is the outcome of the history it missed.
 */
test('a fresh ledger opened from observation does not restart the ordering', function (): void {
    $demands = $configs = [];

    foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot'] as $queue) {
        $demands["queue:redis:{$queue}"] = 5;
        $configs["queue:redis:{$queue}"] = ['min' => 5, 'max' => 20];
    }

    // Leadership moves every eleven cycles: each new manager starts with an
    // empty ledger and is handed what the cluster is currently running.
    $allocator = new FairShareAllocator;
    $current = array_fill_keys(array_keys($demands), 0);
    $everServed = array_fill_keys(array_keys($demands), false);

    for ($cycle = 0; $cycle < 600; $cycle++) {
        if ($cycle > 0 && $cycle % 11 === 0) {
            $allocator = new FairShareAllocator;
        }

        $current = $allocator->allocate($demands, $configs, 4, $current);

        foreach ($current as $key => $workers) {
            if ($workers > 0) {
                $everServed[$key] = true;
            }
        }
    }

    expect(array_keys(array_filter($everServed, static fn (bool $served): bool => ! $served)))->toBe([]);
});

test('an observation never overwrites a ledger already being kept', function (): void {
    // The snapshot is a starting point, not a correction. A balance that has
    // been accumulating is the real history, and letting a single cycle's
    // observation replace it would erase exactly what stops the starvation.
    $allocator = new FairShareAllocator;

    $demands = ['queue:redis:a' => 4, 'queue:redis:b' => 4];
    $configs = [
        'queue:redis:a' => ['min' => 4, 'max' => 20],
        'queue:redis:b' => ['min' => 4, 'max' => 20],
    ];

    for ($cycle = 0; $cycle < 30; $cycle++) {
        $allocator->allocate($demands, $configs, 3);
    }

    $ledger = (new ReflectionProperty($allocator, 'credits'))->getValue($allocator);

    // A wildly contradictory snapshot arrives; the ledger must ignore it.
    $allocator->allocate($demands, $configs, 3, ['queue:redis:a' => 99, 'queue:redis:b' => 0]);

    $after = (new ReflectionProperty($allocator, 'credits'))->getValue($allocator);

    foreach ($ledger as $key => $balance) {
        expect(abs($after[$key] - $balance))->toBeLessThan(2.0);
    }
});

test('omitting the observation leaves the allocation unchanged', function (): void {
    // The parameter is optional, and a caller that does not pass it must get
    // exactly what it got before the parameter existed.
    $demands = ['queue:redis:a' => 5, 'queue:redis:b' => 5, 'queue:redis:c' => 5];
    $configs = [
        'queue:redis:a' => ['min' => 5, 'max' => 20],
        'queue:redis:b' => ['min' => 5, 'max' => 20],
        'queue:redis:c' => ['min' => 5, 'max' => 20],
    ];

    $withNothing = new FairShareAllocator;
    $withEmpty = new FairShareAllocator;

    for ($cycle = 0; $cycle < 40; $cycle++) {
        expect($withNothing->allocate($demands, $configs, 2))
            ->toBe($withEmpty->allocate($demands, $configs, 2, []));
    }
});

/**
 * The margin sets how often ONE workload hands its slot over, so with a fixed
 * margin the number of hand-overs across the CLUSTER grows with the number of
 * workloads sharing it. Measured on a saturated cluster at constant demand: 154
 * worker moves an hour at six workloads, 1368 at sixty-four, 5068 at two
 * hundred and fifty-six — better than one worker restart a second, at a load
 * that never changed. A tenant-per-queue deployment is exactly that shape.
 */
test('hand-over cost does not grow with the size of the fleet', function (int $workloads, int $capacity): void {
    $allocator = new FairShareAllocator;
    $demands = $configs = [];

    for ($index = 0; $index < $workloads; $index++) {
        $key = 'queue:redis:q'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $demands[$key] = 2;
        $configs[$key] = ['min' => 2, 'max' => 20];
    }

    $current = array_fill_keys(array_keys($demands), 0);
    $moves = 0;

    // 1440 cycles is two hours at the default five-second interval.
    for ($cycle = 0; $cycle < 1440; $cycle++) {
        $allocation = $allocator->allocate($demands, $configs, $capacity, $current);

        foreach ($allocation as $key => $workers) {
            $moves += abs($workers - $current[$key]);
        }

        $current = $allocation;
    }

    // A fixed margin puts 256 workloads at roughly ten thousand moves here.
    expect($moves)->toBeLessThan(1000);
})->with([
    'six workloads' => [6, 4],
    'sixty-four workloads' => [64, 48],
    'two hundred and fifty-six workloads' => [256, 200],
]);

test('a larger fleet waits longer for its turn, and still gets one', function (): void {
    // The other side of the trade, and the right way round: a workload sharing
    // capacity with 255 others is entitled to less of it and needs its turn
    // less often. What must not happen is never getting one.
    $allocator = new FairShareAllocator;
    $demands = $configs = [];

    for ($index = 0; $index < 64; $index++) {
        $key = 'queue:redis:q'.str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $demands[$key] = 2;
        $configs[$key] = ['min' => 2, 'max' => 20];
    }

    $current = array_fill_keys(array_keys($demands), 0);
    $everServed = array_fill_keys(array_keys($demands), false);

    for ($cycle = 0; $cycle < 2000; $cycle++) {
        $current = $allocator->allocate($demands, $configs, 48, $current);

        foreach ($current as $key => $workers) {
            if ($workers > 0) {
                $everServed[$key] = true;
            }
        }
    }

    expect(array_keys(array_filter($everServed, static fn (bool $served): bool => ! $served)))->toBe([]);
});

test('a workload with no floor gets nothing while the floors do not fit', function (): void {
    // Deliberate, and sharp enough to pin. workers.min is a claim on the
    // cluster; when the claims exceed what exists, capacity goes to those who
    // made one. Serving a floorless queue ahead of a floor that is itself being
    // scaled down would break the promise to the queue that asked for one.
    //
    // The cost is real: this queue holds backlog and gets no worker at all for
    // as long as the cluster stays over-committed. That is a configuration to
    // fix, not a rotation to widen.
    $allocator = new FairShareAllocator;

    $demands = [
        'queue:redis:claimed-a' => 8,
        'queue:redis:claimed-b' => 8,
        'queue:redis:floorless' => 20,
    ];
    $configs = [
        'queue:redis:claimed-a' => ['min' => 8, 'max' => 20],
        'queue:redis:claimed-b' => ['min' => 8, 'max' => 20],
        'queue:redis:floorless' => ['min' => 0, 'max' => 20],
    ];

    $current = array_fill_keys(array_keys($demands), 0);
    $floorlessEverServed = false;

    for ($cycle = 0; $cycle < 500; $cycle++) {
        $current = $allocator->allocate($demands, $configs, 10, $current);

        expect(array_sum($current))->toBe(10);

        if ($current['queue:redis:floorless'] > 0) {
            $floorlessEverServed = true;
        }
    }

    expect($floorlessEverServed)->toBeFalse();
});

/**
 * The ledger has to be kept in the currency the allocation actually pays in.
 *
 * Capacity is handed out by guaranteeing every floor and sharing what is left,
 * but entitlement was measured as a plain ceiling-proportional share. A
 * workload whose floor exceeds that share is therefore paid its floor every
 * cycle while its balance says it was owed less — and the matching credit
 * accrues to everyone else, every cycle, for a debt the allocation can never
 * settle because the capacity is permanently committed to the floor.
 *
 * Measured on the old basis: balances drifted 3.6 a cycle without limit,
 * 180,000 apart after 50,000 cycles. A balance that size decides every later
 * contest on history that no longer means anything — a tenant joining a cluster
 * that had been saturated for a day waited 41 hours for its first worker, and
 * the wait grew with the cluster's uptime rather than with anything about the
 * tenant.
 */
test('a floor above its proportional share does not drift the ledger', function (): void {
    $allocator = new FairShareAllocator;

    $demands = ['queue:redis:pinned' => 4, 'queue:redis:elastic' => 100];
    $configs = [
        'queue:redis:pinned' => ['min' => 4, 'max' => 4],
        'queue:redis:elastic' => ['min' => 0, 'max' => 100],
    ];

    $credits = new ReflectionProperty($allocator, 'credits');
    $current = array_fill_keys(array_keys($demands), 0);

    for ($cycle = 0; $cycle < 200; $cycle++) {
        $current = $allocator->allocate($demands, $configs, 10, $current);
    }

    $settled = $credits->getValue($allocator);

    for ($cycle = 0; $cycle < 5000; $cycle++) {
        $current = $allocator->allocate($demands, $configs, 10, $current);
    }

    // Settled, not merely small: five thousand further cycles move it nowhere.
    expect($credits->getValue($allocator))->toBe($settled);
});

test('a workload joining a long-saturated cluster waits its ordinary turn', function (): void {
    // The consequence of the drift, and the reason it mattered: a balance built
    // over a day of saturation is three orders of magnitude beyond anything a
    // newcomer can bank, so the newcomer loses every contest until the incumbent
    // balances unwind — a wait that grew with the cluster's age.
    $allocator = new FairShareAllocator;

    $demands = ['queue:redis:pinned' => 8];
    $configs = ['queue:redis:pinned' => ['min' => 8, 'max' => 8]];

    for ($tenant = 0; $tenant < 8; $tenant++) {
        $demands["queue:redis:tenant-{$tenant}"] = 10;
        $configs["queue:redis:tenant-{$tenant}"] = ['min' => 0, 'max' => 10];
    }

    $current = array_fill_keys(array_keys($demands), 0);

    // Four hours of saturation at a five-second interval.
    for ($cycle = 0; $cycle < 2880; $cycle++) {
        $current = $allocator->allocate($demands, $configs, 12, $current);
    }

    $demands['queue:redis:newcomer'] = 10;
    $configs['queue:redis:newcomer'] = ['min' => 0, 'max' => 10];
    $current['queue:redis:newcomer'] = 0;

    $waited = 0;

    while ($waited < 5000) {
        $current = $allocator->allocate($demands, $configs, 12, $current);

        if ($current['queue:redis:newcomer'] > 0) {
            break;
        }

        $waited++;
    }

    // One hysteresis window, not a function of how long the cluster has run.
    expect($waited)->toBeLessThan(200);
});
