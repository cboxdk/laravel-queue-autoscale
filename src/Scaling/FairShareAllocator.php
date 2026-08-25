<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

/**
 * Distributes cluster worker capacity across workloads, fairly and over time.
 *
 * The allocation is one calculation with two projections. exactShares() answers
 * "what does each workload deserve" as a real number; the integer targets are
 * that answer rounded, and the ledger records exactly what the rounding did not
 * pay. Both sides of the ledger therefore come from the same figure, which is
 * the property this whole class rests on.
 *
 * That is not decoration. Entitlement used to be derived by a second set of
 * formulas running parallel to the allocation, kept in agreement by hand across
 * three payment rules and two branch predicates. Seven separate defects came out
 * of that arrangement, each one a place where the two sides disagreed about a
 * path, an input range or a boundary — and because the ledger is cumulative, a
 * disagreement of any size integrates into unbounded drift. Balances reached
 * 200,000 while a workload received a fifth of what it was owed, permanently.
 *
 * Computing the answer once makes that class of defect unwritable rather than
 * repeatedly repaired. FairShareLedgerInvariantTest pins the two properties
 * that follow from it, and both of them failed before this shape existed.
 */
class FairShareAllocator
{
    /**
     * How much unmet entitlement a workload must bank before it takes a
     * contested worker from whoever currently holds it.
     *
     * Without hysteresis the balances alone would move the worker every couple
     * of cycles: measured at 719 moves an hour on six queues sharing four
     * workers, which trades a starved queue for a fleet that spends its life
     * being rebuilt. A margin brings that to around 150 an hour, at the cost of
     * leaving a queue at zero for longer before the hand-over.
     */
    private const CREDIT_HYSTERESIS = 12.0;

    /**
     * How much of that margin each contesting workload adds.
     *
     * The margin sets how often ONE workload hands its worker over, so with a
     * fixed margin the number of hand-overs across the CLUSTER grows with the
     * number of workloads sharing it. Measured on a saturated cluster at
     * constant demand: 154 worker moves an hour at six workloads, 1368 at
     * sixty-four, 5068 at two hundred and fifty-six — better than one worker
     * restart a second, at a load that never changed.
     *
     * Scaling the margin with the number of contenders holds the cluster-wide
     * rate flat instead, 100 to 212 an hour across that whole range, and
     * lengthens each workload's wait in proportion — which is the right way
     * round, because a workload sharing capacity with 255 others is entitled to
     * less of it and needs its turn less often. Two per workload is the value
     * that leaves a six-workload cluster exactly as it was.
     */
    private const CREDIT_HYSTERESIS_PER_WORKLOAD = 2.0;

    /**
     * Below this, a share counts as having reached its ceiling. Water-filling
     * approaches a ceiling asymptotically in floating point, and a workload
     * that never quite arrives would keep the fill iterating.
     */
    private const EPSILON = 1.0e-9;

    /**
     * Entitlement each workload was owed and did not receive, carried forward.
     *
     * Rounding one allocation by largest remainder is fair; repeating it is
     * not. Identical shares give identical remainders and a tie-break decides
     * forever; unequal shares give the smallest one the smallest remainder, so
     * it simply loses. Either way the same workloads lose every cycle —
     * measured, six queues into capacity for four left two of them at zero for
     * 720 consecutive cycles holding real backlog, and across randomised mixed
     * floors better than a quarter of configurations had a workload that was
     * never served at all.
     *
     * Banking the shortfall replaces per-cycle proportionality with proportion
     * over TIME, which is what the rounding was approximating in the first
     * place. A workload owed a third of a worker per cycle now receives one
     * every third cycle instead of never.
     *
     * Per-manager memory, and deliberately NOT cleared when leadership changes:
     * noteLeadership() discards the placement cache and the damping window
     * because both describe a fleet the new leader has not observed, while a
     * ledger of who is owed what stays true regardless of who holds the lease.
     * A host that loses and regains it resumes where it left off. The ledger
     * does not travel between hosts, though — a manager taking the lease
     * elsewhere opens its balances from what it can observe instead.
     *
     * @var array<string, float>
     */
    private array $credits = [];

    /**
     * Workloads that received a rounded-up worker in the previous allocation.
     *
     * Hysteresis has to be measured against whoever currently holds the worker,
     * not against a fixed threshold. Bucketing the balance looked equivalent
     * and is not: a workload sitting at a bucket boundary crosses it, wins the
     * worker, drops back below on the very next cycle and loses it again —
     * measured at 2820 worker moves over 720 cycles, worse than having no
     * hysteresis at all. Requiring a challenger to beat the incumbent by a
     * margin has no such boundary to oscillate around.
     *
     * @var array<string, true>
     */
    private array $incumbents = [];

    /**
     * Distribute cluster capacity fairly across workloads.
     *
     * When everything fits, every workload gets what it can use and there is
     * nothing to keep a ledger about. When it does not, capacity is shared by
     * exactShares() and the rounding is settled through the ledger, so a
     * workload that loses the rounding this cycle is owed it the next.
     *
     * @param  array<string, int>  $demands  workloadKey => raw demand from evaluateDemand()
     * @param  array<string, array{min: int, max: int}>  $configs  workloadKey => worker bounds
     * @param  int  $clusterCapacity  total capacity available for scalable workloads
     * @param  array<string, int>  $currentWorkers  workloadKey => workers running cluster-wide
     * @return array<string, int> workloadKey => adjusted target
     */
    public function allocate(array $demands, array $configs, int $clusterCapacity, array $currentWorkers = []): array
    {
        if ($demands === []) {
            return [];
        }

        // Never negative. The manager clamps before it gets here, but this is a
        // public method on a class consumers are meant to extend and call, and
        // a negative capacity otherwise produces negative targets: scaling a
        // floor by a negative total is arithmetically fine and operationally
        // nonsense.
        $clusterCapacity = max(0, $clusterCapacity);

        $bounds = $this->boundsFor($demands, $configs);
        $usable = 0;

        foreach ($bounds as $bound) {
            $usable += $bound['ceiling'];
        }

        // Capped, not raw. A workload asking for a hundred workers behind a
        // workers.max of five contests nothing when the cluster holds ten, and
        // deciding otherwise opened a ledger against capacity it could never
        // use — a balance that grew without bound at five a cycle forever.
        if ($usable <= $clusterCapacity) {
            // Nobody holds a contested worker, so incumbency lapses. The
            // BALANCES stay: they are the record of contests already fought,
            // and a cycle where everything fits settles nothing.
            //
            // Discarding them looked harmless — if everyone gets what they can
            // use, nobody is owed anything this cycle — and it is not. A hand
            // -over needs about a hysteresis window of banked credit to take a
            // worker from whoever holds it, so a cluster that crosses the
            // contention boundary more often than that never accumulates
            // enough, and the alphabetical tie-break decides every contested
            // cycle forever. Measured on six identical queues sharing four
            // workers with an uncontested blip every five cycles: four queues
            // took a worker every cycle and two took none at all, where
            // retaining the balances serves all six evenly.
            //
            // Stale entries are not a reason to wipe: they are pruned to the
            // live set here, and again by settle() on the next contested cycle.
            $this->credits = array_intersect_key($this->credits, $bounds);
            $this->incumbents = [];

            return array_map(static fn (array $bound): int => $bound['ceiling'], $bounds);
        }

        $shares = $this->exactShares($bounds, $clusterCapacity);

        $this->openLedgerFor($shares, $currentWorkers);

        [$targets, $awarded] = $this->roundShares($shares, $bounds);

        $this->settle($shares, $targets, $awarded);

        return $targets;
    }

    /**
     * What each workload may hold, at least and at most.
     *
     * The ceiling is what it could actually use; the floor is its configured
     * minimum, but never more than that ceiling. A workload asking for less
     * than its floor is telling us it cannot use that claim — the failure fuse
     * does exactly that, returning a demand below workers.min, down to zero,
     * when a queue's jobs are failing. Paying the raw floor anyway hands
     * workers to a queue every host will then refuse to spawn, and takes them
     * from queues that would have run them.
     *
     * @param  array<string, int>  $demands
     * @param  array<string, array{min: int, max: int}>  $configs
     * @return array<string, array{floor: int, ceiling: int, demand: int}>
     */
    private function boundsFor(array $demands, array $configs): array
    {
        $bounds = [];

        foreach ($demands as $key => $demand) {
            // A missing max is not a ceiling of zero. Both keys are required by
            // the shape above, but the two absences do not mean the same thing
            // if one arrives anyway: no minimum is a workload making no claim,
            // while no maximum is a workload with no configured ceiling — so
            // reading it as zero silently refuses a workload that asked for
            // work, which is what the implementation this replaced did not do.
            $ceiling = max(0, min($demand, $configs[$key]['max'] ?? $demand));

            $bounds[$key] = [
                'floor' => max(0, min($configs[$key]['min'] ?? 0, $ceiling)),
                'ceiling' => $ceiling,
                'demand' => max(0, $demand),
            ];
        }

        return $bounds;
    }

    /**
     * What each workload deserves, as a real number, summing to the capacity
     * being shared.
     *
     * This is the single definition of fairness in the class. The integer
     * targets round it and the ledger records what the rounding withheld, so
     * neither can disagree with it.
     *
     * Floors are paid first, because workers.min is the only explicit claim in
     * the configuration model. When the floors together exceed what exists they
     * cannot all be honoured and are scaled down in proportion rather than paid
     * in full to whoever is asked first — a queue with no floor made no claim
     * and receives nothing until that clears, which is sharp, and is what a
     * floor means.
     *
     * The sharing rule itself is unchanged from every previous release, and
     * deliberately so: this class was restructured to remove a defect in how
     * the ledger attached to the allocation, not to re-decide what a fair
     * allocation is. Which criterion to use — the present one, or max-min
     * water-filling to a common level, which would hand a queue asking for
     * three all three and make the queue asking for fifteen absorb the
     * shortfall — is a real question, and one to settle on its own terms rather
     * than inside a refactor.
     *
     * @param  array<string, array{floor: int, ceiling: int, demand: int}>  $bounds
     * @return array<string, float>
     */
    private function exactShares(array $bounds, int $clusterCapacity): array
    {
        $floorTotal = 0;
        $headroom = 0;

        foreach ($bounds as $bound) {
            $floorTotal += $bound['floor'];
            $headroom += $bound['ceiling'] - $bound['floor'];
        }

        if ($floorTotal >= $clusterCapacity) {
            if ($floorTotal <= 0) {
                return array_map(static fn (): float => 0.0, $bounds);
            }

            return array_map(
                static fn (array $bound): float => $bound['floor'] * $clusterCapacity / $floorTotal,
                $bounds,
            );
        }

        if ($headroom <= 0) {
            return array_map(static fn (array $bound): float => (float) $bound['floor'], $bounds);
        }

        // Above the floors the spare is shared in proportion to how much more
        // each workload asked for, capped at what it can use, and whatever the
        // cap frees is shared again. This is the rule the package has always
        // applied; what changed is only that it is now computed ONCE, in real
        // numbers, so the ledger can be settled against the same figure the
        // targets are rounded from.
        //
        // Bounded by the number of workloads: every pass either exhausts the
        // spare or takes at least one workload to its ceiling, and a workload
        // at its ceiling never rejoins.
        $shares = array_map(static fn (array $bound): float => (float) $bound['floor'], $bounds);
        $spare = (float) ($clusterCapacity - $floorTotal);

        for ($pass = 0; $pass <= count($bounds) && $spare > self::EPSILON; $pass++) {
            $weights = [];
            $weightTotal = 0.0;

            foreach ($bounds as $key => $bound) {
                if ($bound['ceiling'] - $shares[$key] <= self::EPSILON) {
                    continue;
                }

                $weight = max(0.0, $bound['demand'] - $shares[$key]);
                $weights[$key] = $weight;
                $weightTotal += $weight;
            }

            if ($weightTotal <= self::EPSILON) {
                break;
            }

            foreach ($weights as $key => $weight) {
                $shares[$key] = min(
                    (float) $bounds[$key]['ceiling'],
                    $shares[$key] + $weight * $spare / $weightTotal,
                );
            }

            $spare = $clusterCapacity - array_sum($shares);
        }

        return $shares;
    }

    /**
     * Turn the exact shares into whole workers, giving the remainder to
     * whoever is owed most.
     *
     * @param  array<string, float>  $shares
     * @param  array<string, array{floor: int, ceiling: int, demand: int}>  $bounds
     * @return array{0: array<string, int>, 1: array<string, true>}
     */
    private function roundShares(array $shares, array $bounds): array
    {
        $targets = [];
        $fractions = [];
        $total = 0.0;

        foreach ($shares as $key => $share) {
            $targets[$key] = (int) floor($share);
            $fractions[$key] = $share - $targets[$key];
            $total += $share;
        }

        $leftover = (int) round($total) - array_sum($targets);
        $awarded = [];

        foreach ($this->awardOrder($fractions) as $key) {
            if ($leftover <= 0) {
                break;
            }

            // A share never exceeds its ceiling, so a workload already at that
            // ceiling has no fraction left and sorts last anyway. The guard is
            // written where the invariant is relied upon rather than assumed.
            if ($targets[$key] >= $bounds[$key]['ceiling']) {
                continue;
            }

            $targets[$key]++;
            $leftover--;
            $awarded[$key] = true;
        }

        return [$targets, $awarded];
    }

    /**
     * Who gets the leftover workers, most-owed first.
     *
     * Whoever held one last time carries a margin, so a challenger has to have
     * banked meaningfully more shortfall to take it. Below that the ordering
     * falls through to the fractional part and then the key, which keeps the
     * allocation identical from cycle to cycle and therefore keeps it from
     * churning. The margin grows with the number of contenders, so the cluster
     * hands over at a steady rate however many workloads share it.
     *
     * @param  array<string, float>  $fractions
     * @return list<string>
     */
    private function awardOrder(array $fractions): array
    {
        $margin = max(
            self::CREDIT_HYSTERESIS,
            count($fractions) * self::CREDIT_HYSTERESIS_PER_WORKLOAD,
        );

        $standing = [];

        foreach ($fractions as $key => $fraction) {
            $standing[$key] = ($this->credits[$key] ?? 0.0)
                + (isset($this->incumbents[$key]) ? $margin : 0.0);
        }

        uksort($fractions, static function (string $a, string $b) use ($standing, $fractions): int {
            return ($standing[$b] <=> $standing[$a])
                ?: ($fractions[$b] <=> $fractions[$a])
                ?: strcmp($a, $b);
        });

        return array_keys($fractions);
    }

    /**
     * Record what the rounding did not pay.
     *
     * The only write to the ledger, on the only path that reaches it. Both
     * halves come from the same allocation, so the balances conserve — what one
     * workload is owed, another owes — and no balance can move by a whole
     * worker in a cycle, because the rounding is all that is ever left unpaid.
     *
     * Pruning belongs here for the same reason: one place, reached by every
     * contested allocation. Maintaining it at each write site instead left the
     * ledger growing one permanent entry per queue name ever seen, on whichever
     * path happened to return early.
     *
     * @param  array<string, float>  $shares
     * @param  array<string, int>  $targets
     * @param  array<string, true>  $awarded
     */
    private function settle(array $shares, array $targets, array $awarded): void
    {
        foreach ($shares as $key => $share) {
            $this->credits[$key] = ($this->credits[$key] ?? 0.0) + $share - $targets[$key];
        }

        $this->credits = array_intersect_key($this->credits, $shares);
        $this->incumbents = $awarded;
    }

    /**
     * Open a workload's ledger from what the cluster can be SEEN to be doing,
     * rather than from zero.
     *
     * A manager that has just taken the lease has no ledger, and starting every
     * balance at zero throws the ordering back to the fractional part and then
     * the key — which is where the alphabetically-first workloads win. One
     * failover costs little, because the balances diverge again within a
     * hysteresis window. Leadership that keeps moving never gets that far:
     * measured, leadership changing every eleven cycles put two of six
     * contending queues back to never being served at all.
     *
     * It does not have to be guessed at. Every host's per-workload worker count
     * already reaches the leader through the heartbeats it reads to size the
     * next decision, and the gap between what a workload holds and what it
     * deserves IS the outcome of whatever history this manager missed.
     *
     * What is NOT observable is how long it has been that way, which is the
     * unit the hysteresis margin is measured in. Scaling the observed gap by
     * that margin converts one into the other: it lets what a new leader can
     * see outrank the incumbency it cannot, exactly once, and normal accounting
     * resumes from the next allocation. Measured across leadership changing
     * every 5, 8, 11 and 20 cycles, no workload is left permanently unserved in
     * any of them; with a stable leader nothing changes at all.
     *
     * @param  array<string, float>  $shares
     * @param  array<string, int>  $currentWorkers
     */
    private function openLedgerFor(array $shares, array $currentWorkers): void
    {
        if ($currentWorkers === []) {
            return;
        }

        foreach ($shares as $key => $share) {
            // Only an unopened ledger. A balance already being kept is the real
            // history and must never be overwritten by a snapshot.
            if (isset($this->credits[$key])) {
                continue;
            }

            // The observed count is clamped because a corrupt heartbeat can
            // report a negative one, and a balance opened from nonsense biases
            // every award that follows it.
            $held = max(0, $currentWorkers[$key] ?? 0);

            $this->credits[$key] = ($share - $held) * self::CREDIT_HYSTERESIS;

            if ($held > (int) floor($share)) {
                $this->incumbents[$key] = true;
            }
        }
    }
}
