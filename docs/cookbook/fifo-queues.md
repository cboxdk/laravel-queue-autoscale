---
title: "Scaling FIFO Queues"
description: "Why message groups, not worker count, decide how much parallelism a FIFO queue can use"
weight: 43
---

# Scaling FIFO Queues

FIFO queues work with this package, and depth, pickup time and scaling all behave normally. But they
change what a worker count *means*, in a way that will quietly waste money if it goes unnoticed.

A standard queue hands a message to whoever asks, so ten workers give ten jobs running at once. A
FIFO queue delivers **at most one message per message group at a time**. The next message in a group
is not released until the previous one is deleted or its visibility timeout expires. Parallelism is
therefore capped by the number of distinct message groups in the backlog — not by the number of
workers polling.

Point ten workers at a FIFO queue whose jobs all share one message group and you get one worker
doing work and nine polling an empty response, indefinitely.

## What decides the group

Laravel derives `MessageGroupId` from the job:

```php
class ScrapeStudent implements ShouldQueue
{
    public function __construct(
        public int $tenantId,
        public int $studentId,
    ) {}

    public function messageGroup(): string
    {
        return "tenant-{$this->tenantId}";
    }
}
```

A job dispatched to a `.fifo` queue **without** `messageGroup()` is rejected by AWS at dispatch time,
not at processing time — the exception surfaces where the job is queued.

## Choosing a group is choosing your concurrency

The group is the ordering boundary and the concurrency boundary at once, so the decision is the same
decision:

| `messageGroup()` returns | Ordering guaranteed across | Max concurrent jobs |
|---|---|---|
| A constant | Everything on the queue | 1 |
| The tenant | That tenant's jobs | One per tenant |
| Tenant plus a shard | Jobs within a shard | Shards per tenant |
| The individual record | Nothing meaningful | Effectively unbounded |

For the common case of a per-tenant limit on some downstream system — an API that accepts five
concurrent callers — the tenant alone is *too coarse*. It gives ordering you probably do not need and
a concurrency of one. Sharding restores the parallelism while keeping the cap exact:

```php
public function messageGroup(): string
{
    return "tenant-{$this->tenantId}-".($this->studentId % 5);
}
```

Five groups per tenant, so at most five of that tenant's jobs run at once — enforced by the queue
itself, independently of how many workers exist or how many hosts they run on.

## Setting the worker count

With the group count fixed, the worker count should match it rather than exceed it. Workers beyond
the number of groups cost memory and polling and can never receive a job.

```php
'queues' => [
    'scrape-*.fifo' => [
        'profile' => ConnectionLimitedProfile::class,
        'workers' => ['max' => 5],
    ],
],
```

Glob keys matter more than usual here: every FIFO queue name ends in `.fifo` by AWS requirement, so
one pattern can cover a whole family of them.

[`ConnectionLimitedProfile`](../basic-usage/workload-profiles.md) suits this shape — `workers.min` of
zero so an idle queue costs nothing, and a hard `workers.max` treated as a fleet-wide cap in cluster
mode rather than a per-host one.

## Things that bite

**Deduplication can silently drop work.** FIFO queues reject a message whose `MessageDeduplicationId`
matches one seen in the last five minutes. With content-based deduplication enabled, two genuinely
distinct jobs with identical payloads become one. Give jobs an explicit `deduplicationId()` when
their payloads may repeat.

**Delays are not available.** `DelaySeconds` is rejected on FIFO queues, so `->delay()` does not work.
Laravel omits it rather than failing, which means a delayed dispatch runs immediately.

**A stuck job blocks its whole group.** Because ordering is guaranteed, a message that keeps timing
out holds up every message behind it in that group until it exhausts its attempts. On a standard
queue that job would be one slow item among many; here it is a stopped line. Keep
`workers.timeout_seconds` — the per-job limit — tight enough that a hung job fails rather than lingering, and watch the
[failure fuse](../basic-usage/failure-fuse.md) — a FIFO backlog that stops draining looks exactly
like the downstream outage the fuse exists to catch.

**Throughput has a ceiling.** A FIFO queue handles far fewer messages per second than a standard one
unless high-throughput mode is enabled, and that mode distributes by message group — another reason
a single group is the wrong choice at volume.

## Verifying

```bash
php artisan queue:autoscale:doctor
```

Flags any `.fifo` queue configured for more than one worker, with the reminder that the parallelism
is only real if the backlog spans that many message groups. See
[Check Your Configuration](../basic-usage/configuration-check.md).

The package's own FIFO behaviour is covered by integration specs that run against
[ElasticMQ](https://github.com/softwaremill/elasticmq). See [Requirements](../requirements.md) for
how to start it.
