<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Diagnostics;

use Cbox\LaravelQueueAutoscale\Configuration\QueueConfigResolver;
use Cbox\LaravelQueueMetrics\Facades\QueueMetrics;

/**
 * The queue names a diagnostic should be checked against.
 *
 * Separate from the doctor itself so the two questions stay apart: what
 * queues exist, and what is wrong with how they are configured. It is also
 * the only seam in the diagnostic path that touches live infrastructure,
 * which is what makes the rest of it testable without any.
 */
class QueueDiscovery
{
    /**
     * Every queue the autoscaler knows about, by name.
     *
     * Discovered queues come from the metrics package, which sees a queue once
     * it has received a job. Configured names are added so a freshly deployed
     * configuration can be checked before traffic has proved it — but glob
     * keys are not, because a rule is not a queue. Counting one as its own
     * subject would have every pattern report itself as governed and never as
     * matching nothing, which is the opposite of the check's purpose.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->fromMetrics() as $key => $entry) {
            // Entries are keyed "connection:queue" and carry the name in the
            // payload too. The payload wins because a queue name may itself
            // contain a colon, which makes splitting the key ambiguous.
            $queue = $entry['queue'] ?? null;

            $name = is_string($queue) && $queue !== ''
                ? $queue
                : (str_contains($key, ':') ? substr($key, strpos($key, ':') + 1) : $key);

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        foreach ($this->configuredNames() as $name) {
            $names[$name] = true;
        }

        return array_keys($names);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function fromMetrics(): array
    {
        return QueueMetrics::getAllQueuesWithMetrics();
    }

    /**
     * @return list<string>
     */
    private function configuredNames(): array
    {
        $configured = config('queue-autoscale.queues', []);

        if (! is_array($configured)) {
            return [];
        }

        $names = [];

        foreach (array_keys($configured) as $name) {
            if (is_string($name) && ! QueueConfigResolver::isPattern($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
