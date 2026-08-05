---
title: "Check Your Configuration"
description: "Catch configurations that are valid and still govern the wrong queues"
weight: 25
---

# Check Your Configuration

```bash
php artisan queue:autoscale:doctor
```

Most autoscaling mistakes are not invalid configuration. They are configuration that is entirely legal
and does something other than what its author meant: a pattern that governs nothing because of a typo,
a glob that quietly claims a queue it was never aimed at, a cap that means something different once a
second host appears. Nothing errors and nothing logs, so the only way to catch them is to look at the
configuration next to the queues that actually exist.

That is what this command does. `queue:autoscale:debug` answers *what is this one queue doing right
now*; the doctor answers *is this configuration governing the queues I think it is*.

## What it looks for

**A rule that matches nothing.** `scrape-tenat-*` is a typo, and a typo'd pattern does not fail — it
simply never applies, and every queue it was meant to govern runs on defaults instead. For a
connection limit that means no cap at all.

**A glob and everything it caught.** A pattern claims everything after its prefix, so `tenant-*`
covers `tenant-admin-notifications` as readily as `tenant-42`. Intent cannot be inferred, so the
doctor does not guess whether a match was wanted — it lists what each rule actually governs, which is
what makes the stray queue visible. The list respects precedence: an exact name beats a glob, and
`excluded` beats both, so the output agrees with what the autoscaler really does.

**Queue names SQS will reject.** SQS accepts only alphanumerics, hyphens and underscores, with `.fifo`
as the sole exception. A dotted name works on Redis and fails at dispatch on SQS, a long way from the
config file that chose it. Only reported when an SQS connection is actually configured.

**Caps that are per-host.** `workers.max` bounds the cluster-wide target before it is distributed —
but only when cluster mode is on. Without it each manager applies the cap alone, so a cap of five
across three hosts permits fifteen. Invisible on one host, and it appears the day someone adds
another.

**Pattern matching with no total ceiling.** A pattern implies queue names that are generated rather
than listed, so nothing in config bounds how many there will be. `limits.max_total_workers` is what
stops a tenant-per-queue application raising thousands of queues to their minimums.

**Configuration left over from v3.** A top-level `queue-autoscale.workers` block, or a
`workers.timeout_seconds` set without `max_time_seconds`. Both parse fine and both silently mean
something other than they used to — see [Upgrading to v4](../advanced-usage/upgrade-guide-v4.md).

**FIFO queues allowing parallelism.** A FIFO queue delivers one message per message group at a time,
so five workers only do five things at once if the backlog spans five groups. Correct configuration
often; silently wasteful when it is not. See [Scaling FIFO Queues](../cookbook/fifo-queues.md).

## Reading the output

```text
WARN   Pattern 'scrape-tenat-*' matches no queue
       No queue that has received a job matches this pattern, so it currently governs nothing.
       → Check the pattern for a typo, and check it uses the separator the queue names actually use.

NOTE   Pattern 'tenant-*' governs 3 queues
       tenant-42, tenant-99, tenant-admin-notifications
       → Check every queue listed belongs under this rule. …
```

Three severities, and the middle one carries most of the value:

| | Meaning |
|---|---|
| `ERROR` | Cannot work as written. |
| `WARN` | Valid, and very likely not what was intended. |
| `NOTE` | Working as designed, and worth knowing about. |

Notices are not noise to be silenced. `workers.max is a per-host limit while cluster mode is off` is
correct behaviour and may be exactly what you configured — it is reported because the consequence
only shows up later, on a machine that does not exist yet.

## In a pipeline

The command exits zero on warnings by default, so it is safe to run for information during a deploy.
`--strict` makes warnings fail too:

```bash
php artisan queue:autoscale:doctor --strict
```

Notices never fail the command, at either setting.

## Before any queue exists

Queue names come from the metrics package, which sees a queue once it has received a job. On a fresh
install there is nothing to check configuration against, and the command says so rather than
reporting a clean bill of health it has not earned. Queues named exactly in configuration are checked
straight away; patterns need real queues before they can be told apart from typos.
