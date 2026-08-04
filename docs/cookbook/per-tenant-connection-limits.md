---
title: "Respect a Per-Tenant Connection Limit"
description: "Give every tenant exactly N concurrent callers without middleware, retries or lock contention"
weight: 44
---

# Respect a Per-Tenant Connection Limit

A third party allows five concurrent connections per customer. You have two hundred thousand records
to fetch across every customer you have. You need all of them processed, none of them dropped, and no
customer ever handed a sixth connection.

The instinct is to reach for job middleware. It is the wrong tool here, and the reason is worth
understanding before the configuration makes sense.

## Why middleware makes this worse

`RateLimited` and `WithoutOverlapping` do not hold a job while it waits. They **release it back onto
the queue**. Three things follow:

- **Depth stops measuring work.** The backlog becomes a count of jobs waiting for a lock, not work
  waiting to be done. A scaler reading depth sees demand and adds workers, which take more locks,
  which release more jobs. The signal and the response feed each other.
- **Attempts are spent on nothing.** Each release consumes an attempt. A long enough queue exhausts
  its retry budget on contention alone and permanently fails work that was never attempted once.
- **The work is done many times over.** Every release is a full round trip through the queue —
  fetch, deserialize, check, re-encode, push — for a job that did no work.

## Make the worker count the limit

Five workers on a queue are five concurrent callers. There is nothing to lock, so nothing releases,
so no attempt is spent waiting. Give each tenant its own queue and cap the workers on it:

```php
// config/queue-autoscale.php
'queues' => [
    'scrape-tenant-*' => [
        'profile' => ConnectionLimitedProfile::class,
        'workers' => ['max' => 5],
    ],
],
```

Dispatch to the tenant's queue and nothing else changes:

```php
ScrapeStudent::dispatch($student)->onQueue("scrape-tenant-{$tenant->id}");
```

### Naming the queues

Two constraints, and the second matters more than it looks.

**SQS rejects a queue name containing a dot** — `Can only include alphanumeric characters, hyphens, or
underscores`, with the `.fifo` suffix as the sole exception. Redis and database queues accept dots
happily. On SQS the scheme has to be `tenant-42` or `tenant_42`. The failure is a remote API error at
dispatch time, not something this package can warn about, so settle it before the first tenant
exists.

**A glob claims everything after its prefix.** `tenant-*` reads as "the tenant queues", but it matches
`tenant-admin-notifications` just as happily as `tenant-42`. For a connection-limited rule that means
capping a queue that has no downstream limit at all — throttling your own operational work to five
workers because it shares a prefix with something that needed throttling.

The separator is not the fix — swapping a dot for a dash changes nothing about what the pattern
claims. **Name the queue for the work it does**, as `scrape-tenant-*` does above, so the glob covers a
workload instead of a prefix. `tenant-admin-notifications` is then in a different namespace entirely
and cannot be reached however the tenant identifiers are shaped. That property holds as the system
grows; a carefully narrow pattern only holds until someone adds a queue you did not anticipate.

If the names are already fixed and the identifiers are numeric, a character class is the next best
thing — `tenant-[0-9]*` matches `tenant-42` and leaves `tenant-admin-notifications` alone. And
[`excluded`](../basic-usage/configuration.md) is the hard backstop either way: it is checked before
any workload is built, so a queue listed there is untouched however broad the pattern covering its
neighbours is.

## Three properties that make this hold at scale

**The cap is a fleet cap, not a per-host one.** In cluster mode the leader solves one target for the
workload, applies `workers.max` to it, and only then distributes the result across hosts. Five stays
five whether you run one machine or thirty. *This requires
[cluster mode](../basic-usage/cluster-scaling.md).* Without it each manager applies the cap
independently and three hosts mean fifteen concurrent callers — the exact outcome you are trying to
prevent.

**Idle tenants cost nothing.** `ConnectionLimitedProfile` sets `workers.min` to zero, so a customer
who is not currently being scraped runs no workers at all. With a queue per tenant and most of them
quiet, this is the difference between a handful of workers and thousands.

**Queues do not need to be registered.** Queue names are discovered from the metrics package, not
read from configuration, so a tenant created at runtime is picked up on the next evaluation cycle and
governed by the glob above. Nothing has to be added anywhere when a customer signs up.

## The cap is a ceiling, not a target

Worth being explicit about, because it surprises people: `workers.max` does not mean "run five
workers". The engine asks for the workers the SLA needs and the cap only stops it going further. A
queue that was filled a moment ago is not late on anything yet, so it will quite correctly be given
two or three workers rather than five.

That is why `ConnectionLimitedProfile` sets a *tight* `sla.target_seconds` — sixty seconds — even
though nobody is waiting on a scrape. The SLA is not a promise to a user here; it is the lever that
makes the queue reach for its whole allowance once it is behind. Loosen it and a large backlog stays
"on time" for a long while, so the queue trickles along at a fraction of the concurrency the third
party would happily give you.

If a workload should always saturate its allowance the moment work exists, tighten the target
further. If it should only use the connections it genuinely needs, loosen it.

## Giving one tenant a different limit

An exact queue name wins over a glob that also matches, so a negotiated exception does not disturb
the rule covering everyone else:

```php
'queues' => [
    'scrape-tenant-*' => ['profile' => ConnectionLimitedProfile::class, 'workers' => ['max' => 5]],
    'scrape-tenant-acme' => ['profile' => ConnectionLimitedProfile::class, 'workers' => ['max' => 20]],
],
```

Overlapping globs resolve in declaration order, first match winning, so regional rules can sit above
the general one:

```php
'scrape-tenant-eu-*' => ['profile' => ConnectionLimitedProfile::class, 'workers' => ['max' => 2]],
'scrape-tenant-*'    => ['profile' => ConnectionLimitedProfile::class, 'workers' => ['max' => 5]],
```

## When there are more tenants than the hosts can carry

A thousand tenants wanting five workers each is five thousand workers, which is not going to fit. The
cluster's fair-share allocator holds every workload's minimum first and then water-fills what is
left, so contention produces slower progress for everyone rather than full speed for whoever was
evaluated first and nothing for the rest. No tenant is ever handed more than its cap while this
happens — the cap is a limit imposed by a third party, not a preference the allocator may trade away.

Set [`limits.max_total_workers`](../basic-usage/configuration.md) as the backstop. With queue names
generated per tenant, discovery can present thousands of queues, and this is the number that bounds
what the host will ever run.

## The failure that looks like load

When the third party goes down, its jobs fail, get released, and the backlog grows. That is
indistinguishable from a traffic spike to anything reading depth, and the natural response — more
workers — hammers a service that is already struggling and burns each job's retry budget faster.

`ConnectionLimitedProfile` enables the [failure fuse](../basic-usage/failure-fuse.md) for exactly
this. When the recent failure rate crosses the threshold the queue is held at `workers.min`, and
after a cooldown a single worker probes for recovery. A queue that exists because a third party is
slow is the one that should stop calling when that third party starts failing.

## If the queue is FIFO

Everything above assumes a standard queue. On a FIFO queue, concurrency is capped by the number of
distinct message groups rather than by the worker count, so five workers on a single-group queue give
you one caller and four idle pollers. See [Scaling FIFO Queues](fifo-queues.md) — the short version
is that the tenant ID alone is too coarse a message group, and you want `tenant-42-{shard}`.

## Verifying

Check the configuration governs what you think it does:

```bash
php artisan queue:autoscale:doctor
```

It lists every queue each pattern actually caught, flags a pattern that matches nothing, and says so
when a cap is per-host because cluster mode is off. See
[Check Your Configuration](../basic-usage/configuration-check.md).

Then check a single queue's live state:

```bash
php artisan queue:autoscale:debug --queue=scrape-tenant-42
```

The output shows the target, the worker count, the limiting factor and the fuse state. `limiting
factor: config` is the healthy answer here — it means the cap is what is holding the queue back,
rather than CPU, memory or the strategy. If you see `cpu` or `memory` instead, the host is the
binding constraint and adding hosts will get you closer to the cap; if you see the fuse holding, the
third party is failing and the pause is deliberate.
