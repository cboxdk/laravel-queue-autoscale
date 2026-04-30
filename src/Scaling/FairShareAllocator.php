<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

final class FairShareAllocator
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

        if ($remainingCapacity <= 0) {
            return $targets;
        }

        // Phase 2: distribute remaining proportionally with water-filling
        $this->waterFill($targets, $demands, $configs, $clusterCapacity);

        return $targets;
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
                    $eligible[$key] = $ceiling - $targets[$key];
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
