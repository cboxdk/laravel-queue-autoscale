<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Diagnostics;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfigResolver;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;

/**
 * Checks a configuration against the ways it can be valid and still wrong.
 *
 * The failures this looks for share a shape: nothing errors, nothing logs, and
 * the autoscaler does something other than what the config's author meant. A
 * glob claims a queue it was never aimed at. A pattern with a typo governs
 * nothing and its queues quietly run on defaults. A per-tenant cap means
 * something different on three hosts than it does on one. None of these can be
 * caught by validation, because each is a legal configuration — they can only
 * be caught by looking at the configuration next to the queues that actually
 * exist.
 *
 * Which is why this reads discovered queues rather than only config. A rule
 * and the queues it governs are the same fact seen from two sides, and only
 * one of those sides is written down.
 */
class ConfigurationDoctor
{
    /**
     * @param  list<string>  $discoveredQueues  Queue names the metrics package has seen.
     * @return list<Finding>
     */
    public function diagnose(array $discoveredQueues): array
    {
        $findings = [];

        foreach (
            [
                $this->rulesThatGovernNothing($discoveredQueues),
                $this->globsClaimingMixedQueues($discoveredQueues),
                $this->illegalQueueNamesForTheirDriver($discoveredQueues),
                $this->capsWithoutClusterMode(),
                $this->discoveryWithoutACeiling($discoveredQueues),
                $this->fifoQueuesAllowingParallelism($discoveredQueues),
            ] as $group
        ) {
            foreach ($group as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Configuration entries that match none of the queues that exist.
     *
     * A misspelled pattern is invisible: it does not error, it simply never
     * applies, and every queue it was meant to govern runs on defaults. For a
     * connection limit that means no cap at all.
     *
     * @param  list<string>  $discoveredQueues
     * @return list<Finding>
     */
    private function rulesThatGovernNothing(array $discoveredQueues): array
    {
        $findings = [];

        foreach ($this->configuredRules() as $rule => $_) {
            if ($this->queuesGovernedBy($rule, $discoveredQueues) !== []) {
                continue;
            }

            $isGlob = QueueConfigResolver::isPattern($rule);

            $findings[] = Finding::warning(
                $isGlob ? "Pattern '{$rule}' matches no queue" : "Queue '{$rule}' has never been seen",
                $isGlob
                    ? 'No queue that has received a job matches this pattern, so it currently governs nothing.'
                    : 'This queue is configured but has never received a job, so its settings have never applied.',
                $isGlob
                    ? 'Check the pattern for a typo, and check it uses the separator the queue names actually use.'
                    : 'Harmless if the queue is simply not in use yet. Otherwise check the name for a typo.',
            );
        }

        return $findings;
    }

    /**
     * Globs governing queues that do not look like they belong together.
     *
     * A pattern claims everything after its prefix, so 'tenant-*' covers
     * 'tenant-admin-notifications' as readily as 'tenant-42'. Intent cannot be
     * inferred, so this does not guess: it reports what each glob actually
     * caught, and lets the reader see a queue that has no business being
     * there.
     *
     * @param  list<string>  $discoveredQueues
     * @return list<Finding>
     */
    private function globsClaimingMixedQueues(array $discoveredQueues): array
    {
        $findings = [];

        foreach ($this->configuredRules() as $rule => $_) {
            if (! QueueConfigResolver::isPattern($rule)) {
                continue;
            }

            $governed = $this->queuesGovernedBy($rule, $discoveredQueues);

            if (count($governed) < 2) {
                continue;
            }

            $findings[] = Finding::notice(
                "Pattern '{$rule}' governs ".count($governed).' queues',
                implode(', ', $governed),
                'Check every queue listed belongs under this rule. A pattern claims everything after '
                .'its prefix, so an unrelated queue sharing that prefix is caught too. Naming queues '
                .'after the work they do keeps them in separate namespaces; excluded is the backstop.',
            );
        }

        return $findings;
    }

    /**
     * Queue names their own driver will not accept.
     *
     * SQS permits only alphanumerics, hyphens and underscores, with '.fifo' as
     * the sole exception. A dotted name is rejected by AWS when a job is
     * dispatched, which is far from the config file that chose it.
     *
     * @param  list<string>  $discoveredQueues
     * @return list<Finding>
     */
    private function illegalQueueNamesForTheirDriver(array $discoveredQueues): array
    {
        if (! $this->hasSqsConnection()) {
            return [];
        }

        $offenders = [];

        foreach (array_merge(array_keys($this->configuredRules()), $discoveredQueues) as $name) {
            if (QueueConfigResolver::isPattern($name)) {
                continue;
            }

            $withoutFifoSuffix = str_ends_with($name, '.fifo')
                ? substr($name, 0, -5)
                : $name;

            if (str_contains($withoutFifoSuffix, '.')) {
                $offenders[$name] = true;
            }
        }

        if ($offenders === []) {
            return [];
        }

        return [Finding::warning(
            'Queue names contain dots, which SQS rejects',
            implode(', ', array_keys($offenders)),
            'SQS accepts only alphanumerics, hyphens and underscores, plus a .fifo suffix. These names '
            .'work on Redis and database queues but fail at dispatch on SQS. Use a hyphen or underscore '
            .'if any of these queues may run on SQS.',
        )];
    }

    /**
     * Worker caps configured while cluster mode is off.
     *
     * `workers.max` bounds the cluster-wide target before it is distributed
     * across hosts — but only when there is a cluster. Without it each manager
     * applies the cap on its own, so a cap of five across three hosts permits
     * fifteen. That is invisible on a single host and appears the day a second
     * one is added.
     *
     * @return list<Finding>
     */
    private function capsWithoutClusterMode(): array
    {
        if (AutoscaleConfiguration::clusterEnabled()) {
            return [];
        }

        $capped = [];

        foreach ($this->configuredRules() as $rule => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $workers = $entry['workers'] ?? null;

            if (is_array($workers) && isset($workers['max'])) {
                $capped[] = $rule;
            }
        }

        if ($capped === []) {
            return [];
        }

        return [Finding::notice(
            'workers.max is a per-host limit while cluster mode is off',
            'Capped: '.implode(', ', $capped),
            'On a single host this is exactly what you configured. If these caps exist because something '
            .'downstream limits concurrency — an API connection limit, a database pool — enable cluster '
            .'mode before running a second host, or the limit is multiplied by the host count.',
        )];
    }

    /**
     * Pattern-based configuration without a total worker ceiling.
     *
     * Queues are discovered, not enumerated, so an application that creates a
     * queue per tenant can present thousands. Each is then raised to its
     * configured minimum, and nothing bounds the sum.
     *
     * @param  list<string>  $discoveredQueues
     * @return list<Finding>
     */
    private function discoveryWithoutACeiling(array $discoveredQueues): array
    {
        if (AutoscaleConfiguration::maxTotalWorkers() !== null) {
            return [];
        }

        $globs = array_filter(
            array_keys($this->configuredRules()),
            static fn (string $rule): bool => QueueConfigResolver::isPattern($rule),
        );

        if ($globs === []) {
            return [];
        }

        return [Finding::warning(
            'limits.max_total_workers is unset while queues are matched by pattern',
            'Patterns in use: '.implode(', ', $globs).'. Queues discovered so far: '.count($discoveredQueues).'.',
            'A pattern implies queue names that are generated rather than listed, so their number is not '
            .'bounded by anything in config. Set limits.max_total_workers to the most workers this host '
            .'should ever run.',
        )];
    }

    /**
     * FIFO queues configured for more parallelism than they may be able to use.
     *
     * A FIFO queue delivers one message per message group at a time, so
     * parallelism is bounded by the number of distinct groups in the backlog
     * rather than by the worker count. Workers beyond the group count poll and
     * never receive anything.
     *
     * @param  list<string>  $discoveredQueues
     * @return list<Finding>
     */
    private function fifoQueuesAllowingParallelism(array $discoveredQueues): array
    {
        $findings = [];

        foreach ($discoveredQueues as $queue) {
            if (! str_ends_with($queue, '.fifo')) {
                continue;
            }

            $max = QueueConfiguration::fromConfig($this->defaultConnection(), $queue)->workers->max;

            if ($max <= 1) {
                continue;
            }

            $findings[] = Finding::notice(
                "'{$queue}' is a FIFO queue allowing {$max} workers",
                'FIFO delivers one message per message group at a time, so parallelism is capped by the '
                .'number of distinct groups in the backlog, not by the worker count.',
                "Correct if this queue's jobs span at least {$max} message groups. If they all share one "
                .'group, the extra workers poll and never receive a job — shard the group id to get the '
                .'parallelism back.',
            );
        }

        return $findings;
    }

    /**
     * Queues discovered or configured that a rule applies to.
     *
     * @param  list<string>  $discoveredQueues
     * @return list<string>
     */
    private function queuesGovernedBy(string $rule, array $discoveredQueues): array
    {
        $governed = [];

        foreach ($discoveredQueues as $queue) {
            if (AutoscaleConfiguration::isExcluded($queue)) {
                continue;
            }

            // Ask the resolver rather than matching here, so this reports the
            // rule that actually wins — an exact name beats a glob, and the
            // first glob listed beats later ones.
            if ($this->winningRuleFor($queue) === $rule) {
                $governed[] = $queue;
            }
        }

        return $governed;
    }

    private function winningRuleFor(string $queue): ?string
    {
        $rules = $this->configuredRules();

        if (array_key_exists($queue, $rules)) {
            return $queue;
        }

        foreach ($rules as $rule => $_) {
            if (QueueConfigResolver::isPattern($rule) && fnmatch($rule, $queue)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function configuredRules(): array
    {
        $queues = config('queue-autoscale.queues', []);

        if (! is_array($queues)) {
            return [];
        }

        $rules = [];

        foreach ($queues as $key => $entry) {
            if (is_string($key)) {
                $rules[$key] = $entry;
            }
        }

        return $rules;
    }

    private function hasSqsConnection(): bool
    {
        $connections = config('queue.connections', []);

        if (! is_array($connections)) {
            return false;
        }

        foreach ($connections as $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'sqs') {
                return true;
            }
        }

        return false;
    }

    private function defaultConnection(): string
    {
        $default = config('queue.default');

        return is_string($default) ? $default : 'database';
    }
}
