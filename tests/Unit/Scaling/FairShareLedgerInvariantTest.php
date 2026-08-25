<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Scaling\FairShareAllocator;

/**
 * The two properties that make a whole class of defect unwritable.
 *
 * The ledger is double-entry accounting: it records what a workload was owed
 * against what it received. That only works if both sides come from ONE
 * calculation. Seven separate defects have come from computing them with
 * parallel formulas and keeping those formulas in step by hand — every one of
 * them shows up here, because every one of them books a debt the allocation
 * will never settle, and the ledger is cumulative, so any per-cycle
 * disagreement integrates into unbounded drift.
 *
 * 1. The balances sum to zero. Capacity handed to one workload is capacity
 *    taken from another; a ledger that does not conserve is measuring two
 *    different things.
 * 2. No balance moves by a whole worker in one cycle. The only thing a cycle
 *    can leave unpaid is the rounding, which is less than one by definition.
 *
 * These are asserted over deliberately hostile inputs — including demand above
 * workers.max, which a scaling policy can produce, because that is where the
 * parallel formulas diverged.
 */
function ledgerOf(FairShareAllocator $allocator): array
{
    return (new ReflectionProperty($allocator, 'credits'))->getValue($allocator);
}

function randomContendedConfig(int $seed): array
{
    mt_srand($seed);

    $workloads = mt_rand(2, 9);
    $demands = [];
    $configs = [];

    for ($index = 0; $index < $workloads; $index++) {
        $key = 'queue:redis:q'.chr(97 + $index);
        $min = mt_rand(0, 9);
        $max = $min + mt_rand(0, 12);

        // Deliberately allows demand ABOVE max: a scaling policy can raise a
        // target past the ceiling, and that is exactly where the ledger's two
        // sides used to disagree.
        $demands[$key] = mt_rand(0, $max + 8);
        $configs[$key] = ['min' => $min, 'max' => $max];
    }

    $usable = 0;

    foreach ($demands as $key => $demand) {
        $usable += max(0, min($demand, $configs[$key]['max']));
    }

    return [$demands, $configs, max(1, (int) ($usable * 0.6))];
}

test('the ledger conserves: what one workload is owed, another owes', function (): void {
    // Capacity handed to one workload is capacity taken from another, so the
    // balances must sum to zero. A ledger that does not conserve is measuring
    // two different things — which is exactly what a second, parallel
    // entitlement formula does.
    //
    // Asserted without observation seeding, which deliberately opens balances
    // from outside the allocation and is the one thing allowed to move the sum.
    $worst = 0.0;

    for ($seed = 1; $seed <= 300; $seed++) {
        [$demands, $configs, $capacity] = randomContendedConfig($seed);

        $allocator = new FairShareAllocator;

        for ($cycle = 0; $cycle < 200; $cycle++) {
            $allocator->allocate($demands, $configs, $capacity);
            $worst = max($worst, abs(array_sum(ledgerOf($allocator))));
        }
    }

    // Floating point only, never a real imbalance.
    expect($worst)->toBeLessThan(1.0e-6);
});

test('opening a ledger from observation is the only thing that moves the sum', function (): void {
    // The exception, stated so it cannot be widened by accident: seeding is
    // allowed to inject a balance because it is reconstructing history the
    // allocation itself never saw. Every cycle after it must conserve again.
    [$demands, $configs, $capacity] = randomContendedConfig(11);

    $allocator = new FairShareAllocator;
    $current = array_fill_keys(array_keys($demands), 1);

    $allocator->allocate($demands, $configs, $capacity, $current);
    $afterSeeding = array_sum(ledgerOf($allocator));

    for ($cycle = 0; $cycle < 500; $cycle++) {
        $current = $allocator->allocate($demands, $configs, $capacity, $current);

        expect(abs(array_sum(ledgerOf($allocator)) - $afterSeeding))->toBeLessThan(1.0e-6);
    }
});

test('no balance moves by more than one worker in a cycle', function (): void {
    // A cycle can only leave the ROUNDING unpaid, so a balance moves by less
    // than a worker — or by exactly one, when a workload owed enough is handed
    // a whole worker its own fraction had not earned. Either way it is bounded
    // by one, and that bound is what makes runaway drift unwritable: a
    // disagreement between two parallel formulas has no such limit, and
    // integrates.
    $worst = 0.0;

    for ($seed = 1; $seed <= 300; $seed++) {
        [$demands, $configs, $capacity] = randomContendedConfig($seed);

        $allocator = new FairShareAllocator;
        $previous = [];

        for ($cycle = 0; $cycle < 200; $cycle++) {
            $allocator->allocate($demands, $configs, $capacity);
            $ledger = ledgerOf($allocator);

            foreach ($ledger as $key => $balance) {
                if (array_key_exists($key, $previous)) {
                    $worst = max($worst, abs($balance - $previous[$key]));
                }
            }

            $previous = $ledger;
        }
    }

    expect($worst)->toBeLessThanOrEqual(1.0 + 1.0e-9);
});

test('a balance stays within a bounded band however long the cluster runs', function (): void {
    // The consequence of the two properties above: balances cannot run away,
    // so a contest is decided by recent history rather than by uptime.
    [$demands, $configs, $capacity] = randomContendedConfig(7);

    $allocator = new FairShareAllocator;
    $current = array_fill_keys(array_keys($demands), 0);
    $worst = 0.0;

    for ($cycle = 0; $cycle < 20000; $cycle++) {
        $current = $allocator->allocate($demands, $configs, $capacity, $current);

        foreach (ledgerOf($allocator) as $balance) {
            $worst = max($worst, abs($balance));
        }
    }

    // Comfortably above the hysteresis margin, far below a runaway.
    expect($worst)->toBeLessThan(500.0);
});
