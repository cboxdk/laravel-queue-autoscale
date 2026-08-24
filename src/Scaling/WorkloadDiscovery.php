<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Scaling;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;
use Illuminate\Support\Facades\Log;

/**
 * Finds everything worth scaling at the start of an evaluation cycle.
 *
 * Both the single-host loop and the cluster leader need the same answer, and
 * they used to derive it with two copies of the same forty lines — which is how
 * a guard added to one path silently missed the other. There is one copy now.
 */
class WorkloadDiscovery
{
    /**
     * Whether the group configuration passed validation, memoized for the life
     * of the process: a bad config must not spam the log every cycle, and a
     * good one must not re-run the O(groups x members) conflict check forever.
     */
    private ?bool $groupsValid = null;

    public function __construct(
        private readonly QueueMetricsAdapter $adapter,
    ) {}

    public function discover(): DiscoveredWorkloads
    {
        // Recalculate first so throughput reflects the current sliding window.
        app(CalculateQueueMetricsAction::class)->executeForAllQueues();

        $allQueues = QueueMetrics::getAllQueuesWithMetrics();

        // Configured queues may have no history yet. Fetching them directly is
        // what lets a newly declared queue be supervised from the first cycle
        // instead of once the metrics layer happens to see traffic on it.
        foreach (AutoscaleConfiguration::configuredQueues() as $queueKey => $queueInfo) {
            if (! isset($allQueues[$queueKey])) {
                $allQueues[$queueKey] = $this->adapter->forQueue($queueInfo['connection'], $queueInfo['queue']);
            }
        }

        $groups = GroupConfiguration::allFromConfig();

        // Same reasoning for a group's member queues: without this a brand-new
        // group whose members have never run stays invisible, delaying its
        // first scale-up.
        foreach ($groups as $group) {
            foreach ($group->queues as $memberQueue) {
                $queueKey = "{$group->connection}:{$memberQueue}";

                if (! isset($allQueues[$queueKey])) {
                    $allQueues[$queueKey] = $this->adapter->forQueue($group->connection, $memberQueue);
                }
            }
        }

        if (! $this->groupsAreUsable($groups)) {
            $groups = [];
        }

        return new DiscoveredWorkloads($allQueues, $groups);
    }

    /**
     * @param  array<string, GroupConfiguration>  $groups
     */
    private function groupsAreUsable(array $groups): bool
    {
        if ($groups === []) {
            return true;
        }

        if ($this->groupsValid === null) {
            try {
                GroupConfiguration::assertNoQueueConflicts($groups);
                $this->groupsValid = true;
            } catch (\Throwable $e) {
                $this->groupsValid = false;
                Log::channel(AutoscaleConfiguration::logChannel())->critical(
                    'Group configuration is invalid — groups disabled until manager restart',
                    ['error' => $e->getMessage()]
                );
            }
        }

        return $this->groupsValid;
    }
}
