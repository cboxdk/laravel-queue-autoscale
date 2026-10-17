<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Diagnostics;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfigResolver;
use Cbox\LaravelQueueAutoscale\Support\QueueDriver;
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
            // Jobs run synchronously still fire the queue events, so the
            // metrics layer reports queues on connections no worker can pop
            // from. The manager never supervises those; neither does this.
            $connection = $this->connectionOf($key, $entry);

            if ($connection !== null && ! AutoscaleConfiguration::connectionCanBeWorked($connection)) {
                continue;
            }

            $name = $this->nameOf($key, $entry);

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
     * The queues on connections that use the given queue driver, by name.
     *
     * A bare name from names() does not say which connection it was seen on,
     * so a check that only applies to one driver would have to guess from the
     * default connection. Each discovered entry carries its own connection,
     * and each configured queue names one or takes the default, so this reads
     * the driver from those instead.
     *
     * @return list<string>
     */
    public function namesOnDriver(string $driver): array
    {
        $names = [];

        foreach ($this->fromMetrics() as $key => $entry) {
            $connection = $this->connectionOf($key, $entry);

            if ($connection === null || $this->driverOf($connection) !== $driver) {
                continue;
            }

            $name = $this->nameOf($key, $entry);

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        foreach (AutoscaleConfiguration::configuredQueues() as $queue) {
            if ($this->driverOf($queue['connection']) === $driver) {
                $names[$queue['queue']] = true;
            }
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
     * @param  array<string, mixed>  $entry
     */
    private function connectionOf(string $key, array $entry): ?string
    {
        $connection = $entry['connection'] ?? (str_contains($key, ':') ? strstr($key, ':', true) : null);

        return is_string($connection) ? $connection : null;
    }

    /**
     * Entries are keyed "connection:queue" and carry the name in the payload
     * too. The payload wins because a queue name may itself contain a colon,
     * which makes splitting the key ambiguous.
     *
     * @param  array<string, mixed>  $entry
     */
    private function nameOf(string $key, array $entry): string
    {
        $queue = $entry['queue'] ?? null;

        if (is_string($queue) && $queue !== '') {
            return $queue;
        }

        return str_contains($key, ':') ? substr($key, strpos($key, ':') + 1) : $key;
    }

    private function driverOf(string $connection): ?string
    {
        return QueueDriver::of($connection);
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
