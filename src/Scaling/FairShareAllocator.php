<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

class FairShareAllocator
{
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
            return $demands;
        }

        return $this->allocateWithFairShare($demands, $configs, $clusterCapacity);
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

        return $targets;
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

        // Hand the rounding leftovers to the largest fractional parts, and
        // break ties by key so two identical inputs never disagree.
        //
        // uksort, not arsort: arsort is stable on INSERTION order, and these
        // arrive in metrics-discovery order. With equal remainders — which is
        // what identical floors produce — the workload that happened to be
        // discovered first won, so the same cluster starved a different queue
        // depending on the order Redis returned its keys. Measured: with the
        // order shuffled each cycle, every queue starved about 28% of the time
        // and the zero-slots migrated, taking cross-host churn with them.
        uksort($remainders, static function (string $a, string $b) use ($remainders): int {
            return ($remainders[$b] <=> $remainders[$a]) ?: strcmp($a, $b);
        });
        $leftover = $clusterCapacity - array_sum($scaled);

        foreach (array_keys($remainders) as $key) {
            if ($leftover <= 0) {
                break;
            }

            $scaled[$key]++;
            $leftover--;
        }

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
        // Sort by fractional descending, tie-break by key ascending (deterministic)
        uksort($fractionals, function (string $a, string $b) use ($fractionals): int {
            $cmp = $fractionals[$b] <=> $fractionals[$a];

            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });

        foreach ($fractionals as $key => $frac) {
            if ($leftover <= 0) {
                break;
            }

            $ceiling = min($demands[$key], $configs[$key]['max']);

            if ($targets[$key] < $ceiling) {
                $targets[$key]++;
                $leftover--;
            }
        }
    }
}
