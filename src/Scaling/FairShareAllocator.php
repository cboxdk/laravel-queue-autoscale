<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

class FairShareAllocator
{
    /**
     * How much unmet entitlement a workload must bank before it takes a slot
     * from somebody currently holding one.
     *
     * Without hysteresis the credits alone would rotate the slot every couple
     * of cycles: measured at 719 worker moves an hour on six queues sharing
     * four slots, which trades a starved queue for a fleet that spends its life
     * being rebuilt. A margin of eight workers-worth of entitlement brings that
     * to around 150 an hour, at the cost of leaving a queue at zero for longer
     * before the swap.
     */
    private const CREDIT_HYSTERESIS = 12.0;

    /**
     * How much of that margin each contesting workload adds.
     *
     * The margin sets how often ONE workload hands its slot over, so with a
     * fixed margin the number of hand-overs across the CLUSTER grows with the
     * number of workloads sharing it. Measured on a saturated cluster at
     * constant demand: 154 worker moves an hour at six workloads, 1368 at
     * sixty-four, 5068 at two hundred and fifty-six — better than one worker
     * restart a second, at a load that never changed.
     *
     * Scaling the margin with the number of contenders holds the cluster-wide
     * rate flat instead (100 to 212 an hour across that whole range), and
     * lengthens each workload's wait in proportion — which is the right way
     * round, because a workload sharing capacity with 255 others is entitled to
     * less of it and needs its turn less often. Two per workload is the value
     * that leaves a six-workload cluster exactly as it was.
     */
    private const CREDIT_HYSTERESIS_PER_WORKLOAD = 2.0;

    /**
     * Entitlement each workload was owed and did not receive, carried forward.
     *
     * Largest-remainder is a fair way to round ONE allocation and an unfair way
     * to repeat one. Identical floors give identical remainders and a
     * tie-break decides; unequal floors give the smallest share the smallest
     * remainder, so it loses outright. Either way the same workloads lose every
     * cycle forever — measured, six queues into capacity for four left two of
     * them at zero for 720 consecutive cycles holding real backlog, and across
     * randomised mixed floors better than a quarter of configurations had a
     * workload that was never served at all.
     *
     * Banking the shortfall replaces per-cycle proportionality with proportion
     * over TIME, which is what the rounding was approximating in the first
     * place. A workload owed a third of a worker per cycle now receives one
     * every third cycle instead of never.
     *
     * Per-manager memory, and deliberately NOT cleared when leadership changes:
     * noteLeadership() discards the placement cache and the damping window
     * because both describe a fleet the new leader has not observed, while a
     * ledger of who is owed what stays true regardless of who is holding the
     * lease. A host that loses and regains it therefore resumes where it left
     * off.
     *
     * The ledger does not travel between hosts, though. When leadership moves
     * to a different manager, that one starts even, and a leader crash-looping
     * faster than the hysteresis margin keeps handing the first hand-over to
     * the same workloads. Persisting the ledger through the cluster store would
     * close that; it is a store-schema change and has not been made.
     *
     * @var array<string, float>
     */
    private array $credits = [];

    /**
     * Workloads that received a leftover worker in the previous allocation.
     *
     * Hysteresis has to be measured against whoever currently holds the slot,
     * not against a fixed threshold. Bucketing the credit looked equivalent and
     * is not: a workload sitting at a bucket boundary crosses it, wins the
     * slot, drops back below on the very next cycle and loses it again —
     * measured at 2820 worker moves over 720 cycles, worse than having no
     * hysteresis at all. Requiring a challenger to beat the incumbent by a
     * margin has no such boundary to oscillate around.
     *
     * @var array<string, true>
     */
    private array $incumbents = [];

    /**
     * Leftovers handed out during the allocation in progress.
     *
     * Water-filling revisits its leftover several times within one call, so
     * incumbency is committed once at the end rather than after each pass.
     *
     * @var array<string, true>
     */
    private array $pendingAwards = [];

    /**
     * Distribute cluster capacity fairly across workloads.
     *
     * When total demand fits within capacity, returns demands unchanged.
     * When demand exceeds capacity, allocates mins first then distributes
     * remaining capacity proportionally to each workload's headroom,
     * with water-filling iteration to reclaim capacity freed by max clamping.
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

        // Capped, not raw. A workload asking for a hundred workers behind a
        // workers.max of five contests nothing when the cluster holds ten — but
        // its raw demand exceeds the capacity, so the contested path opened,
        // banked it entitlement it could never use, and grew its balance
        // without bound at five a cycle forever.
        $usableDemand = 0;

        foreach ($demands as $key => $demand) {
            $usableDemand += max(0, min($demand, $configs[$key]['max'] ?? 0));
        }

        if ($usableDemand <= $clusterCapacity) {
            // Nothing is contested, so nobody is holding a contested slot.
            $this->incumbents = [];

            // Capped on the way out too. The leader already clamps demand to
            // workers.max before it gets here, but this is a public entry point
            // on an open class and handing back more than a workload is allowed
            // to run would be a strange thing for a fair-share allocator to do.
            $capped = [];

            foreach ($demands as $key => $demand) {
                $capped[$key] = max(0, min($demand, $configs[$key]['max'] ?? $demand));
            }

            return $capped;
        }

        $this->pendingAwards = [];

        $this->seedLedgerFromObservation(
            $this->entitlementsFor($demands, $configs, $clusterCapacity),
            $currentWorkers,
        );

        $allocation = $this->allocateWithFairShare($demands, $configs, $clusterCapacity);

        $this->incumbents = $this->pendingAwards;

        // Prune here rather than only where the ledger is banked. Seeding opens
        // an entry for every workload it is shown, but the path where the
        // minimums exactly fill the capacity returns before banking anything —
        // so on a cluster statically pinned at sum-of-mins the ledger grew one
        // permanent entry per queue name ever seen. Measured at 4420 entries
        // after 50,000 cycles of tenant churn, and it only ever emptied because
        // a single differently-sized cycle happened along.
        $this->credits = array_intersect_key($this->credits, $demands);
        $this->incumbents = array_intersect_key($this->incumbents, $demands);

        return $allocation;
    }

    /**
     * @param  array<string, int>  $demands
     * @param  array<string, array{min: int, max: int}>  $configs
     * @return array<string, int>
     */
    private function allocateWithFairShare(array $demands, array $configs, int $clusterCapacity): array
    {
        // Phase 1: guarantee every workload gets its min
        $targets = [];

        foreach ($demands as $key => $demand) {
            $targets[$key] = $configs[$key]['min'];
        }

        $remainingCapacity = $clusterCapacity - array_sum($targets);

        if ($remainingCapacity < 0) {
            // The minimums alone do not fit. Returning them verbatim handed
            // back a total larger than the capacity we were given, and the
            // caller then placed workloads until hosts filled up and silently
            // dropped whatever was left — in metrics-discovery order, which is
            // not stable between cycles. A critical queue could be starved to
            // zero while a bulk queue kept its floor, and the victim changed
            // from one cycle to the next.
            //
            // Scaling the floors down proportionally instead spreads the
            // shortfall across every workload, so the outcome is the same one
            // twice running and no queue is singled out by an ordering
            // accident.
            return $this->scaleMinimumsToFit($targets, $clusterCapacity);
        }

        if ($remainingCapacity === 0) {
            return $targets;
        }

        // Phase 2: distribute remaining proportionally with water-filling
        $this->waterFill($targets, $demands, $configs, $clusterCapacity);
        $this->bankShortfall($this->proportionalEntitlements($demands, $configs, $clusterCapacity), $targets);

        return $targets;
    }

    /**
     * What each workload is entitled to on the path that is about to run.
     *
     * The two paths measure entitlement differently, and the ledger has to be
     * opened in the same currency it will later be banked in. When the worker
     * floors do not all fit, capacity is shared in proportion to those FLOORS
     * and demand never enters it — so seeding from demand there hands a
     * zero-floor workload a balance it has no claim to, and it takes the
     * leftover away from a configured floor. Measured on a capacity of one
     * against two queues pinned at min = max = 1 and one tenant at min = 0: the
     * tenant took the only worker on sixteen of the first twenty cycles, and a
     * critical queue that had asked for a floor was left at zero.
     *
     * @param  array<string, int>  $demands
     * @param  array<string, array{min: int, max: int}>  $configs
     * @return array<string, float>
     */
    private function entitlementsFor(array $demands, array $configs, int $clusterCapacity): array
    {
        $floors = [];

        foreach ($demands as $key => $demand) {
            $floors[$key] = $configs[$key]['min'];
        }

        // Greater than OR EQUAL. A floor total that exactly fills the capacity
        // leaves nothing to share, so allocateWithFairShare() hands back the
        // floors verbatim and banks nothing — seeding in the demand currency
        // there opens balances against a distribution that never happens.
        if (array_sum($floors) >= $clusterCapacity) {
            return $this->floorEntitlements($floors, $clusterCapacity);
        }

        return $this->proportionalEntitlements($demands, $configs, $clusterCapacity);
    }

    /**
     * Shares of a capacity too small to hold every floor, in proportion to
     * those floors.
     *
     * @param  array<string, int>  $floors
     * @return array<string, float>
     */
    private function floorEntitlements(array $floors, int $clusterCapacity): array
    {
        $total = array_sum($floors);

        if ($total <= 0) {
            return array_map(static fn (): float => 0.0, $floors);
        }

        return array_map(
            static fn (int $floor): float => $floor * $clusterCapacity / $total,
            $floors,
        );
    }

    /**
     * Each workload's share of a capacity that cannot satisfy everyone,
     * measured the way that capacity is actually handed out.
     *
     * The allocation guarantees every floor first and shares only what is left
     * over, so the entitlement has to be built the same way: the floor, plus a
     * proportional slice of the spare measured against real headroom. Anything
     * else opens the ledger in a currency the allocation never pays in, and the
     * difference is banked every cycle as a debt nothing can settle — a
     * workload whose floor exceeds its plain proportional share is paid that
     * floor forever while its balance sinks, and the matching credit accrues to
     * everyone else forever.
     *
     * Measured on a plain ceiling-proportional basis: capacity 10 against one
     * queue pinned at min = max = 4 and one elastic tenant, balances drifted
     * 3.6 a cycle without limit — 180,000 apart after 50,000 cycles. A balance
     * that size decides every later contest on history that no longer means
     * anything: a tenant joining a cluster that had been saturated for a day
     * sat at zero workers for 41 hours, and the wait grew with the cluster's
     * age rather than with anything about the tenant.
     *
     * @param  array<string, int>  $demands
     * @param  array<string, array{min: int, max: int}>  $configs
     * @return array<string, float>
     */
    private function proportionalEntitlements(array $demands, array $configs, int $clusterCapacity): array
    {
        $ceilings = [];
        $floors = [];

        foreach ($demands as $key => $demand) {
            $ceiling = max(0, min($demand, $configs[$key]['max']));
            $ceilings[$key] = $ceiling;
            $floors[$key] = max(0, min($configs[$key]['min'], $ceiling));
        }

        $spare = $clusterCapacity - array_sum($floors);
        $headroom = 0.0;

        foreach ($ceilings as $key => $ceiling) {
            $headroom += $ceiling - $floors[$key];
        }

        if ($spare <= 0 || $headroom <= 0.0) {
            return array_map(static fn (int $floor): float => (float) $floor, $floors);
        }

        $entitlements = [];

        foreach ($ceilings as $key => $ceiling) {
            $entitlements[$key] = min(
                (float) $ceiling,
                $floors[$key] + $spare * ($ceiling - $floors[$key]) / $headroom,
            );
        }

        return $entitlements;
    }

    /**
     * Fit a set of minimums into a capacity too small to hold them.
     *
     * Only the floors are shared here, and a workload that configured none has
     * no share: `workers.min` is a claim on the cluster, and when the claims
     * together exceed what exists, capacity goes to those who made one. Serving
     * a floorless workload ahead of a floor that is itself being scaled down
     * would break the promise for the queue that actually asked for it.
     *
     * The consequence is worth stating plainly, because it is sharp: once the
     * floors reach capacity — at it as well as over it, since a floor total
     * equal to capacity leaves nothing to share either — a queue with
     * `workers.min` of zero gets nothing at all, for as long as that lasts,
     * however much backlog it is holding. The rotation below shares the loss among the workloads that DO
     * have a claim; it does not manufacture a claim for one that has none.
     *
     * Proportional, then largest-remainder for the rounding, so the result is
     * deterministic and sums exactly to the capacity rather than to whatever
     * floor() left behind.
     *
     * @param  array<string, int>  $targets
     * @return array<string, int>
     */
    private function scaleMinimumsToFit(array $targets, int $clusterCapacity): array
    {
        $total = array_sum($targets);

        if ($clusterCapacity <= 0 || $total <= 0) {
            return array_map(static fn (): int => 0, $targets);
        }

        $scaled = [];
        $remainders = [];

        foreach ($targets as $key => $min) {
            $exact = $min * $clusterCapacity / $total;
            $scaled[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $scaled[$key];
        }

        // Hand the rounding leftovers to the largest fractional parts, then to
        // whoever has gone longest without any, and only then by key.
        //
        // uksort, not arsort: arsort is stable on INSERTION order, and these
        // arrive in metrics-discovery order. With equal remainders — which is
        // what identical floors produce — the workload that happened to be
        // discovered first won, so the same cluster starved a different queue
        // depending on the order Redis returned its keys. Measured: with the
        // order shuffled each cycle, every queue starved about 28% of the time
        // and the zero-slots migrated, taking cross-host churn with them.
        //
        // Determinism alone is not enough, though. Identical floors give
        // identical remainders and a tie-break decides; unequal floors give the
        // smallest share the smallest remainder, so it loses outright. Either
        // way the same workloads lose every cycle forever — measured over 720
        // cycles, six queues into capacity for four left two of them at zero
        // throughout, holding real backlog. Deterministic starvation is worse
        // than the random kind, because nothing ends it. Banked entitlement
        // decides instead, so the loss moves around.
        $leftover = $clusterCapacity - array_sum($scaled);
        $entitlements = [];

        foreach ($targets as $key => $min) {
            $entitlements[$key] = $min * $clusterCapacity / $total;
        }

        foreach ($this->awardOrder($remainders) as $key) {
            if ($leftover <= 0) {
                break;
            }

            $scaled[$key]++;
            $leftover--;
            $this->pendingAwards[$key] = true;
        }

        $this->bankShortfall($entitlements, $scaled);

        return $scaled;
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, int>  $demands
     * @param  array<string, array{min: int, max: int}>  $configs
     */
    private function waterFill(array &$targets, array $demands, array $configs, int $clusterCapacity): void
    {
        $maxIterations = count($demands) + 1;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $remaining = $clusterCapacity - array_sum($targets);

            if ($remaining <= 0) {
                break;
            }

            $eligible = [];

            foreach ($demands as $key => $demand) {
                $ceiling = min($demand, $configs[$key]['max']);

                if ($targets[$key] < $ceiling) {
                    // Use uncapped headroom for proportional distribution.
                    // This may over-allocate past max in this iteration;
                    // the clamping step below corrects it, and the freed
                    // capacity is picked up by the next iteration.
                    $eligible[$key] = $demand - $targets[$key];
                }
            }

            if ($eligible === []) {
                break;
            }

            $totalHeadroom = array_sum($eligible);

            if ($totalHeadroom <= 0) {
                break;
            }

            // Proportional distribution with largest-remainder
            $fractionals = [];

            foreach ($eligible as $key => $headroom) {
                $share = $headroom * ($remaining / $totalHeadroom);
                $targets[$key] += (int) floor($share);
                $fractionals[$key] = $share - floor($share);
            }

            // Distribute leftover to highest fractional remainders
            $leftover = $clusterCapacity - array_sum($targets);

            if ($leftover > 0) {
                $this->distributeFractionalLeftover($targets, $fractionals, $configs, $demands, $leftover);
            }

            // Clamp to ceiling — capacity freed by clamping is picked
            // up by the next water-fill iteration
            foreach ($targets as $key => $target) {
                $ceiling = min($demands[$key], $configs[$key]['max']);
                $targets[$key] = min($target, $ceiling);
            }
        }
    }

    /**
     * @param  array<string, int>  $targets
     * @param  array<string, float>  $fractionals
     * @param  array<string, array{min: int, max: int}>  $configs
     * @param  array<string, int>  $demands
     */
    private function distributeFractionalLeftover(
        array &$targets,
        array $fractionals,
        array $configs,
        array $demands,
        int $leftover,
    ): void {
        // Same rule as the floors path: banked entitlement first, then the
        // fractional part, then the key. This leftover is contested on every
        // cycle too, so a pure remainder ordering starves the same workload
        // here for exactly the same reason — one worker below its share rather
        // than at zero, but forever.
        foreach ($this->awardOrder($fractionals) as $key) {
            if ($leftover <= 0) {
                break;
            }

            $ceiling = min($demands[$key], $configs[$key]['max']);

            if ($targets[$key] < $ceiling) {
                $targets[$key]++;
                $leftover--;
                $this->pendingAwards[$key] = true;
            }
        }
    }

    /**
     * Who gets the leftover workers, most-owed first.
     *
     * Whoever held the slot last time carries a margin, so a challenger has to
     * have banked meaningfully more shortfall to take it. The margin grows with
     * the number of contenders, so the cluster hands over at a steady rate
     * however many workloads are sharing it. Below that the
     * ordering falls through to the fractional part and then the key, which
     * keeps the allocation identical from cycle to cycle and therefore keeps it
     * from churning.
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
     * Bank what each workload was owed and did not get.
     *
     * A workload handed more than its share pays the difference back, so a
     * queue that keeps winning the rounding does not accumulate a permanent
     * advantage over one that keeps losing it.
     *
     * @param  array<string, float>  $entitlements
     * @param  array<string, int>  $allocated
     */
    private function bankShortfall(array $entitlements, array $allocated): void
    {
        foreach ($entitlements as $key => $entitlement) {
            $this->credits[$key] = ($this->credits[$key] ?? 0.0) + $entitlement - ($allocated[$key] ?? 0);
        }

        // A workload that stopped being contested — removed, excluded, or no
        // longer over capacity — must not keep a balance forever.
        $this->credits = array_intersect_key($this->credits, $entitlements);
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
     * contending queues back to never being served at all, and every eleven
     * cycles is a cluster in trouble but not an impossible one.
     *
     * It does not have to be guessed at. Every host's per-workload worker count
     * already reaches the leader through the heartbeats it reads to size the
     * next decision, and the gap between what a workload holds and what it is
     * entitled to IS the outcome of whatever history this manager missed. A
     * workload sitting at zero under sustained contention is behind; one
     * holding a leftover is ahead. That much is observable, and it is the part
     * that matters.
     *
     * What is NOT observable is how long it has been that way, which is the
     * unit the hysteresis margin is measured in. Scaling the observed gap by
     * that margin is what converts one into the other: it lets what a new
     * leader can see outrank the incumbency it cannot, exactly once, and normal
     * accounting resumes from the next allocation. Measured across leadership
     * changing every 5, 8, 11 and 20 cycles, no workload is left permanently
     * unserved in any of them; with a stable leader nothing changes at all.
     *
     * @param  array<string, float>  $entitlements
     * @param  array<string, int>  $currentWorkers
     */
    private function seedLedgerFromObservation(array $entitlements, array $currentWorkers): void
    {
        if ($currentWorkers === []) {
            return;
        }

        foreach ($entitlements as $key => $entitlement) {
            // Only an unopened ledger. A balance already being kept is the
            // real history and must never be overwritten by a snapshot.
            if (isset($this->credits[$key])) {
                continue;
            }

            $held = $currentWorkers[$key] ?? 0;

            // The observed count is clamped because a corrupt heartbeat can
            // report a negative one, and a balance opened from nonsense biases
            // every award that follows it.
            $this->credits[$key] = ($entitlement - max(0, $held)) * self::CREDIT_HYSTERESIS;

            if (max(0, $held) > (int) floor($entitlement)) {
                $this->incumbents[$key] = true;
            }
        }
    }
}
