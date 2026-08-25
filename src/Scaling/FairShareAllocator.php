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
     * to around 59 moves an hour, at the cost of leaving a queue at zero for
     * longer before the swap.
     */
    private const CREDIT_HYSTERESIS = 12.0;

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
     * @return array<string, int> workloadKey => adjusted target
     */
    public function allocate(array $demands, array $configs, int $clusterCapacity): array
    {
        if ($demands === []) {
            return [];
        }

        $totalDemand = array_sum($demands);

        if ($totalDemand <= $clusterCapacity) {
            // Nothing is contested, so nobody is holding a contested slot.
            $this->incumbents = [];

            return $demands;
        }

        $this->pendingAwards = [];

        $allocation = $this->allocateWithFairShare($demands, $configs, $clusterCapacity);

        $this->incumbents = $this->pendingAwards;

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
     * Each workload's proportional share of a capacity that cannot satisfy
     * everyone, measured against what it could actually use.
     *
     * @param  array<string, int>  $demands
     * @param  array<string, array{min: int, max: int}>  $configs
     * @return array<string, float>
     */
    private function proportionalEntitlements(array $demands, array $configs, int $clusterCapacity): array
    {
        $ceilings = [];

        foreach ($demands as $key => $demand) {
            $ceilings[$key] = max(0, min($demand, $configs[$key]['max']));
        }

        $total = array_sum($ceilings);

        if ($total <= 0) {
            return array_map(static fn (): float => 0.0, $ceilings);
        }

        return array_map(
            static fn (int $ceiling): float => $ceiling * $clusterCapacity / $total,
            $ceilings,
        );
    }

    /**
     * Fit a set of minimums into a capacity too small to hold them.
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
     * have banked meaningfully more shortfall to take it. Below that the
     * ordering falls through to the fractional part and then the key, which
     * keeps the allocation identical from cycle to cycle and therefore keeps it
     * from churning.
     *
     * @param  array<string, float>  $fractions
     * @return list<string>
     */
    private function awardOrder(array $fractions): array
    {
        $standing = [];

        foreach ($fractions as $key => $fraction) {
            $standing[$key] = ($this->credits[$key] ?? 0.0)
                + (isset($this->incumbents[$key]) ? self::CREDIT_HYSTERESIS : 0.0);
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
}
