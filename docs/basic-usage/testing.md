---
title: "Testing Your Configuration"
description: "Assert what your queues will actually do, without Redis and without waiting for real load"
weight: 26
---

# Testing Your Configuration

Autoscaling configuration is code that only runs in production, under conditions you cannot reproduce
by hand. A per-tenant connection limit either holds or does not, and finding out from the third party
is expensive.

The package ships the fakes and assertions to settle it in a normal test.

```php
use Cbox\LaravelQueueAutoscale\Testing\InteractsWithAutoscaling;
use Cbox\LaravelQueueAutoscale\Testing\QueueMetricsFactory;

uses(InteractsWithAutoscaling::class);
```

Everything below drives the real scaling engine with your real configuration. Only the two stores
that need Redis are substituted.

## Asserting what a queue will ask for

```php
test('the exports queue scales up when it falls behind', function () {
    $this->assertWorkersDemanded(0, QueueMetricsFactory::idle('exports'));

    $this->assertWorkersDemanded(
        6,
        QueueMetricsFactory::behind(pending: 500, oldestJobAge: 120, queue: 'exports'),
    );
});
```

`QueueMetricsFactory` distinguishes three states, and the difference decides the answer:

| | Meaning |
|---|---|
| `idle()` | Nothing waiting. |
| `backlogged($pending)` | Work waiting, none of it late yet. |
| `behind($pending, $oldestJobAge)` | Work waiting and the SLA budget being spent. |

`backlogged` and `behind` are not interchangeable. Backlog drain contributes nothing until the SLA
budget is half spent, so a deep queue and a late queue produce very different targets — testing only
the first will convince you scaling does not work.

## Asserting a cap rather than a number

For a queue whose parallelism is dictated by something downstream, the useful assertion is not that a
given backlog produces a given number. It is that **no** backlog produces more than the limit:

```php
test('no tenant ever gets a sixth connection', function () {
    $this->assertWorkersCappedAt(5, 'scrape-tenant-42');
});
```

This drives the queue with backlogs from ten to a hundred thousand jobs, all of them long overdue, and
fails if any of them asks for more than the cap. See
[Respect a Per-Tenant Connection Limit](../cookbook/per-tenant-connection-limits.md).

## Testing the failure fuse

Waiting for real failures is not an option, so seed them:

```php
test('a failing payment provider stops the queue scaling up', function () {
    $behind = QueueMetricsFactory::behind(1000, oldestJobAge: 300, queue: 'payments');

    $this->fakeFailureWindows();
    expect($this->workersDemandedFor($behind))->toBeGreaterThan(0);

    $this->tripFuseFor('payments');

    expect($this->workersDemandedFor($behind))->toBe(0);
});
```

`fakeFailureWindows()` returns the store, so a spec can also seed a backdated state change and expire
a cooldown without sleeping.

## Testing cluster behaviour

```php
test('the leader distributes work across hosts', function () {
    $cluster = $this->fakeCluster()
        ->withManager($stateForWebOne)
        ->withManager($stateForWebTwo)
        ->withLeader('web-01');

    // …run your listener or command…

    expect($cluster->publishedRecommendations())->toHaveCount(2);
});
```

`FakeClusterStore` keeps everything in memory. It hands leadership to whoever asks first and holds it
there, which is enough to test what a manager *does* with cluster state — and deliberately not enough
to test whether election itself is sound. The real store needs Redis for that: leader election
depends on atomic compare-and-set and a reliable TTL, and a fake that pretends otherwise would hand
two managers the same lease and pass.

## What is not faked

**Host CPU and memory limits.** `workersDemandedFor()` returns cluster-wide demand — the strategy's
answer bounded by the queue's own minimum and maximum and by the fuse. Host resource ceilings are
left out on purpose, because they depend on the machine running the test, and a configuration that
asserted differently on a laptop and in CI would be worse than no assertion. To test the ceiling
itself, use `CapacityCalculator` directly.

**Your queue driver.** These helpers reason about metrics, not about queues. To check that jobs
actually reach a queue and that depth reads back, write an integration test — see
[Requirements](../requirements.md) for the SQS setup the package uses for its own.
