# Changelog

All notable changes to `laravel-queue-autoscale` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

**Behaviour change: the anti-flapping cooldown is one-sided.**
`scaling.cooldown_seconds` now holds only a scale-DOWN. A scale-up is never
held, so the SLA-breach exception that used to release one is gone as a
mechanism. A scale-down is still held while the window opened by a recent
scale-up is running, unchanged. The direction remembered is now the one that
actually happened rather than the one the engine proposed, so a scaling policy
that flips a decision can no longer leave the guard damping the wrong way.
A scale-down forced by the failure fuse now bypasses the damper entirely:
failing jobs look like load, so the fleet has usually just scaled up when the
fuse trips, and the withdrawal was being held as an ordinary reversal — leaving
a full-size fleet against a dead dependency for the rest of the window. Damping both directions made the manager
the source of the oscillation it exists to absorb: on demand whose period is a
small multiple of the cooldown window every change is a reversal, so each rise
was deferred until the backlog breached, the breach then released a target the
delay itself had inflated, and the fall off that spike was deferred in turn.
A rise arriving mid-drain was answered by *cutting* the fleet, because a hold
republishes the last allowed target clamped to what is running. Measured
against the real engine on a 120-second sine at `workers.max` 20: symmetric
damping pinned the fleet to the 20-worker ceiling for a load needing about 5,
averaged 9.2 workers and spent 109 of 3600 ticks breaching. One-sided peaks at
8, averages 6.5 and never breaches; at a 90-second period symmetric holds the
SLA but still sits at the ceiling. Noise around a constant mean, a sustained step and a
periodic burst — the shapes the guard was written for — came out the same or
better on every measure, and the result holds across cooldown windows from 30
to 300 seconds. Nothing about scale-down damping changed; if you
raised `cooldown_seconds` to suppress oscillation, it still does that, and it
no longer delays the response to load.

**Fixed: two ways a queue could be starved indefinitely.**
When cluster capacity cannot satisfy every workload, the shortfall is shared
proportionally and the leftover workers were handed out by largest fractional
remainder. That is a fair way to round one allocation and an unfair way to
repeat one. Identical floors give identical remainders, so a tie-break decided
it — and being deterministic, it decided the same way every cycle forever;
unequal floors are worse still, because the smallest share also has the
smallest remainder and simply loses. Measured over 720 cycles, six queues into
capacity for four left two of them at zero throughout while holding real
backlog, and across randomised mixed floors better than a quarter of
configurations had a workload that was never served at all. The same rule, and
the same outcome, applied to the water-filling path.

Entitlement a workload was owed and did not receive is now banked and carried
forward, so the leftover goes to whoever is furthest behind. Proportionality
holds over time instead of per cycle, which is what the rounding was
approximating: a queue entitled to nine percent of the workers now receives
nine percent of them rather than none. Every contending workload's time at zero
is bounded, and a workload already holding a leftover keeps it until a
challenger has banked meaningfully more — without that margin the guarantee cost 2820
worker moves per 720 cycles, with it, 156.

**Fixed: a queue that cannot use its floor is no longer paid one.**
`workers.min` is a claim on capacity, and a workload asking for less than its
floor is telling us it cannot use that claim — the failure fuse does exactly
that, returning a demand below the floor, down to zero, when a queue's jobs are
failing. On a cluster whose floors together exceed capacity, the fused queue was
still allocated its scaled share: workers every host would then refuse to spawn,
taken from queues that would have run them. Measured on a capacity of eight
against three floors of five with one queue fused, the fused queue took three
workers it could not use while the two healthy queues dropped from four each to
three and two. The trigger is a downstream dependency failing at the same moment
the cluster is over-subscribed, which is the worst time to hold capacity idle.

This also settles a disagreement between the two allocation paths: one clamped
floors to demand and the other did not, so a one-worker change in capacity could
move four workers between queues as the cluster crossed the boundary between
them. Both clamp now, and the boundary is continuous.

**Fixed: a cluster leader no longer accumulates per-queue state forever.**
Per-queue bookkeeping is swept once a workload has gone quiet, but the sweep
was driven by the last scaling ACTION — and a leader records breach state for
every workload it discovers while never scaling any of them itself, because the
scaling happens on the followers. Its map was therefore never visited: an
application minting a queue name per tenant grew one permanent entry per tenant
in a process that runs for weeks. The sweep now runs on when a workload was
last SEEN, which bounds a leader and a follower alike.

It also stops a second, quieter problem. A queue evaluated on every cycle but
not scaled for an hour used to have its breach state swept out from under it,
resetting the edge that records whether its breach had already been reported —
so a queue breaching all along could announce itself twice. State now survives
for as long as the workload does.

**Fixed: a new cluster leader no longer restarts fairness from nothing.**
The fair-share ledger is per-manager, so a manager taking the lease opened every
balance at zero and the ordering fell back to the workload key — where the
alphabetically-first workloads win. A single failover costs little, because the
balances diverge again within a hysteresis window; leadership that keeps moving
never gets that far. Measured, leadership changing every eleven cycles put two of
six contending queues back to never being served at all.

The leader now opens an unknown balance from what the cluster can be seen to be
doing. Every host's per-workload worker count already reaches it through the
heartbeats it reads to size the next decision, and the gap between what a
workload holds and what it is entitled to is the outcome of the history this
manager missed. What is not observable is how long it has been that way, which
is the unit the hysteresis margin is measured in, so the observed gap is scaled
into that unit — letting what a new leader can see outrank the incumbency it
cannot, exactly once, after which normal accounting resumes. Measured across
leadership changing every 5, 8, 11 and 20 cycles, no workload is left
permanently unserved in any of them; with a stable leader nothing changes.

The allocator is now one calculation with two projections: a single set of
exact fractional shares, from which the integer targets are rounded and against
which the ledger is settled. Both sides of the ledger therefore come from the
same figure and cannot disagree.

That structure is the fix, not a tidy-up. Entitlement used to be derived by a
second set of formulas running parallel to the allocation, kept in agreement by
hand across three payment rules and two branch predicates — and seven separate
defects came out of that arrangement, each one a place where the two sides
disagreed about a path, an input range or a boundary. Because the ledger is
cumulative, a disagreement of any size integrates: balances reached 200,000
while a workload received a fifth of what it was owed, permanently.

The rule for sharing capacity is deliberately unchanged, and the specs that
pinned it pass untouched. What changed is where the ledger attaches. Two
properties now hold by construction and are asserted directly: the balances sum
to zero, because capacity handed to one workload is capacity taken from another;
and no balance moves by more than one worker in a cycle, because the rounding is
all a cycle can leave unpaid. Either one, asserted earlier, would have caught all
seven defects.

Entitlement is measured in the currency the allocation actually pays in: the
floor first, then what is left over, matching how capacity is handed out. Measured as a plain proportional share instead, a workload whose
floor exceeds that share is paid its floor every cycle while its balance says it
was owed less, and the difference banks forever as a debt nothing can settle —
balances drifted 3.6 a cycle without limit, 180,000 apart after 50,000 cycles,
and a tenant joining a cluster saturated for a day then waited 41 hours for its
first worker because the incumbents' history outweighed anything it could bank.
It waits an ordinary hysteresis window now, whatever the cluster's uptime.

A balance already being kept is never overwritten by a snapshot, and the
observed count and the entitlement are both clamped before use — a corrupt
heartbeat or a scaling policy that raises a target above `workers.max` would
otherwise let a balance grow without limit, and a ledger that has drifted far
enough stops serving anyone who joins later.

The rate at which the cluster hands slots over no longer grows with the number
of workloads sharing it. The margin a holder carries scales with the number of
contenders, so a saturated two-hundred-queue fleet moves workers about as often
as a six-queue one rather than thirty times as often: measured at constant
demand, 154 moves an hour at six workloads and 5068 at two hundred and
fifty-six before, against 100 to 212 across that whole range now. Each workload
waits proportionally longer for its turn, which is the right way round — one
sharing capacity with 255 others is entitled to less of it. A cluster of six is
unchanged.

Two smaller repairs in the same area: the ledger is pruned on every contested
path rather than only the ones that bank it, so a cluster statically pinned at
exactly the sum of its worker floors no longer keeps one entry per queue name
ever seen; and elapsed time in both cooldown windows is now absolute, so a clock
stepping backwards — an NTP correction, a virtual machine resuming from a
snapshot — cannot extend a hold. A thirty-minute backward step held a scale-down
for thirty-one minutes instead of sixty seconds.

`FairShareAllocator` is therefore stateful across calls, which it was not
before: the same inputs no longer produce the same output on consecutive
`allocate()` calls, because the ledger between them has moved. That is the
point — it is what stops the same workload losing every round — but anyone
calling the allocator directly rather than through the manager should know it.

**Fixed: a stalled leader could overwrite the real leader's recommendations.**
The fencing token was read when the recommendations were published, at the end
of the cycle, rather than beside the check that said this manager held the
lease. An evaluation takes as long as it takes, and a manager that stalls past
its lease — a long pause, a slow metrics read, a virtual machine frozen and
resumed — comes back into a cluster somebody else now leads. Reading the token
then handed it the NEW leader's token, which satisfies the fence and let the
stale manager publish its own out-of-date assignments under its own id. The
token is now captured where leadership is confirmed, so the fence rejects the
stale publish instead, which is what a fence is for.

**Fixed: one nonsense heartbeat could stop the leader scaling itself.**
`is_numeric()` accepts INF and NAN, and JSON carries them in without complaint:
`{"cpu_percent": 1e999}` decodes to INF. That value travelled into the cluster
summary and reached `json_encode(..., JSON_THROW_ON_ERROR)`, which refuses it —
so one host writing a nonsense heartbeat threw inside the leader's cycle after
recommendations had been published but before the leader applied its own,
leaving the leader unscaled every cycle until that host aged out of the
registry. Non-finite values are rejected at the heartbeat boundary now, and the
summary is published inside its own guard: it reports on the scaling, and a
reporting failure must not become a scaling outage.

**Fixed: `FairShareAllocator` no longer reads a missing `workers.max` as zero.**
Both bounds are required by the method's shape, but the two absences do not mean
the same thing if one arrives anyway: no minimum is a workload making no claim,
while no maximum is a workload with no configured ceiling. Reading the second as
zero silently refused a workload that had asked for work. Unreachable through
the manager, which always supplies both, but the class is a documented extension
point.

**Fixed: a manager whose every cycle fails no longer looks healthy.**
A cycle that throws is caught so one bad workload cannot take the daemon down,
and the failure went to the configured log channel alone. Console reporting is
gated on `-v`, which is right for narration and wrong for a failure — so a
manager that could not reach its cache, or whose metrics package could not read
its database, printed its start-up banner and then nothing at all while doing
nothing at all. That is also the likeliest moment for it to happen, because it
is what a fresh misconfiguration looks like. The failure is now written to the
console at any verbosity, naming the exception, and throttled to once a minute
so a daemon failing on a five-second interval does not bury everything else.
The throttle is kept in the process rather than through the cache-backed alert
limiter, because an unreachable cache is one of the things it has to report.

**Added: the cluster says when its leadership is unstable.**
Worker placement, the anti-flapping window and the fair-share ledger are all
leader working memory, discarded when the lease moves because each describes a
cluster the new leader has not observed. A single failover costs a cycle;
leadership that keeps moving means none of them ever completes, and the
workload starved longest keeps losing its claim to be served next — measured,
leadership changing every eleven cycles put two of six contending queues back
to never being served at all. Until now a change was a debug line and an event
nobody is obliged to listen to. The manager now warns when it observes three
leadership changes inside one anti-flapping window, and `queue:autoscale:doctor`
warns when `cluster.leader_lease_seconds` leaves no headroom over
`manager.evaluation_interval_seconds`, which is the usual reason a leader keeps
missing its renewal. The shipped defaults — a 15-second lease against a
5-second interval — pass the check.

Separately, a damped scale-down could refuse to release capacity that fair
share had already promised to another workload, so a scale-up the damper never
touched was starved by a neighbour's hold. A hold now surrenders its surplus
when the cluster total no longer fits. This makes anti-flapping conditional on
spare capacity: on a cluster running at its ceiling, two workloads whose
demands alternate will each surrender their hold to the other and move workers
at the demand's own period. The alternative was publishing a total the hosts
could not place, which did not prevent the move — it only stopped the manager
predicting it.

**Fixed: a queue at zero workers now wakes on the first cycle, not halfway
through its SLA.**
Both of the engine's calculations are rate calculations, and both answer zero
for a small backlog on an idle queue: Little's Law sees no arrival rate, and the
backlog drain deliberately waits until the oldest job has spent
`scaling.breach_threshold` of its SLA before acting. That patience is right when
workers are already running and wrong when none are — nothing is absorbing the
backlog, and the only thing happening is the clock running down. Measured: one
job arriving at a queue sitting at zero waited 15 seconds against a 30-second
SLA, 60 against 120, and longer still at 300 — always half the target, whatever
the evaluation interval; dropping the interval to one second still cost
fourteen. Three jobs got a worker on the next cycle. The difference was the
threshold, not the work.

A queue holding work with nothing draining it now asks for one worker straight
away, so the wait is the evaluation interval and nothing more. It is stated as a
need rather than a floor, so everything downstream still applies: a host with no
spare capacity, a queue capped at `workers.max` of zero, and a queue whose
failure fuse has tripped each still resolve to no workers, and an idle queue with
no backlog still gets nothing. That last one is what keeps this from quietly
restoring the floor withdrawn below — it is a response to work, not a standing
promise.

This matters more BECAUSE of that withdrawal: scaling to zero is only safe if
coming back is quick.

**Behaviour change: a worker floor now applies only to a queue you named.**
Queues are discovered from metrics rather than registered, so an application
minting a queue name per tenant was getting one permanently-running
`queue:work` per name ever seen — the floor is applied after the CPU/memory
clamp, and `limits.max_total_workers` ships unset. If you relied on the
implicit floor, name the queues, or restore it wholesale with
`'queues' => ['*' => ['workers' => ['min' => 1]]]` and set a ceiling.

### Changed

- **Requires `ext-mbstring`.** Worker output is truncated on a character
  boundary rather than a byte boundary, so a multibyte character straddling
  the cap cannot put invalid UTF-8 into the log channel. The extension is
  present in every mainstream PHP distribution and Laravel itself requires it;
  the package simply had not declared what it uses.

- **A queue matching no entry in `queues` gets `workers.min = 0`**, whatever
  `sla_defaults` says. It still receives every other default — SLA target,
  `workers.max`, forecast, spawn compensation, fuse — and scales from zero on
  demand. Named and glob-matched queues are unaffected, as are groups. A
  non-scalable default profile is exempt, because `workers.scalable = false`
  requires `min === max` by construction.
  One consequence: the engine clamps to measured CPU/memory before any floor
  applies, so on a host already at capacity a discovered queue with a backlog
  now gets zero workers where it previously got one.

### Fixed

- **The manager id changes on upgrade.** It has carried an application scope
  since v4.1.0 — that is what stops two apps on one host reaping each other's
  workers — but the scope was derived from a hash of `base_path()`, which
  differs between two checkouts of the same application and between a symlinked
  release directory and its target. It is now derived from `app.name` and
  `app.env`, length-prefixed so two different pairs cannot collide.
  The consequence for an existing deployment: workers orphaned by the
  pre-upgrade manager are no longer recognised and will exit on their own
  `--max-time` rather than being reaped once, and during a rolling upgrade a
  host briefly appears twice in the cluster registry until the stale entry is
  pruned. Both are transient and neither loses work.

- **A follower clamps the leader's recommendation to its own `workers.max`.**
  Redundant while the leader is correct, and the difference between a blast
  radius of `workers.max` and whatever integer arrived over the wire when it is
  not — a bug, a version mismatch mid-rolling-deploy, or anything with write
  access to the coordination key. Covers the pinned path too, where a queue's
  max is its pinned count.
- **The anti-flapping cooldown no longer lowers its own memory.** A held target
  was written back clamped, so one transient dip in reported workers ratcheted
  the hold down for the rest of the window and never recovered. Only what is
  published is clamped now.
- **Worker output truncates on a character boundary.** The 64 KB cap on an
  unterminated line cut bytes, so a multibyte character straddling it put
  invalid UTF-8 into the log channel.

## v4.1.0 - 2026-08-24

**Read this before deploying to a cluster.** The Redis coordination keys change
format in this release, so during a rolling deploy the managers still on the old
version and the ones on the new version read *different* leader keys. For that
window you have two leaders, each scaling its own half of the fleet toward
`workers.max` independently — a fleet sized for N can briefly run at up to 2N.
It resolves itself the moment every manager is on the new version, and nothing is
stranded because each recommendation is `setex`'d. Deploy all managers together, or
accept the overlap knowingly. Single-host installations are unaffected.

### Added

- **Redis Cluster support for cluster coordination.** The coordination and
  spawn-latency paths assumed single-node phpredis: the leader-readiness check
  called `RedisCluster::ping()` with no argument (a cluster has no keyless
  PING), and the spawn-latency and recommendation-fencing scripts spanned keys
  in different slots (`CROSSSLOT`). The `ping` is now key-routed on a
  `\RedisCluster` client and the multi-key scripts share a hash tag, so cluster
  mode works against a `\RedisCluster` connection. The single-node `\Redis`
  path is unchanged. Covered by a cluster CI job that reruns the coordination
  specs against a real three-master cluster.
- Cluster-scoped scaling policies. A policy implementing the new opt-in `ClusterScopedPolicy` marker interface is additionally consulted by the cluster leader against a `ScalingDecision` carrying the workload's cluster-wide counts (`scope = ScalingScope::Cluster`), before the target is distributed across hosts. This is where a global budget belongs: a cap applied only per host multiplies the intended ceiling by the host count. `ScalingDecision` gained a `scope` field (default `Host`, so every existing decision reads exactly as before) and a `withTargetWorkers()` helper that preserves all other fields. Policies that do not opt in are never consulted by the leader, so existing behavior is unchanged.

- `CapacityCalculationResult::cpuBreakdown()` and `memoryBreakdown()`, returning the
  nested `cpu_details` / `memory_details` numbers as typed readonly objects
  (`CpuBreakdown`, `MemoryBreakdown`). `$details` is unchanged and still documented —
  its keys are public API — so this is additive; the package now uses the accessors
  internally rather than indexing into nested `mixed`.

### Changed

- **Requires `cboxdk/laravel-queue-metrics` `^3.3`** (was `^3.0`). v3.3.0 fixes
  several defects in exactly the readings the autoscaler makes its decisions
  from, so the older floor let the manager scale on numbers that were wrong:
  a recorded snapshot zeroed `pending`, `depth`, `oldest_job_age`, `scheduled`
  and `reserved` in `getQueueMetrics()`, and the Redis fallback depth path
  always reported zero on Laravel versions without the native queue size
  methods. It also eliminates the phantom `default` queue that discovery used
  to surface, and lets `getQueueDepth()` tolerate a queue the driver cannot
  read yet instead of throwing every cycle. v3.3.1 fixes a double-prefixing
  bug in `scanKeys()` that silently no-oped metric cleanup and baseline reads
  whenever a Redis connection prefix was configured (Laravel's default), and
  v3.3.2 makes worker heartbeats Redis Cluster-safe, closing the last gap in
  end-to-end cluster support alongside this release's own coordination fixes.

- **Coordination key format.** Cluster coordination keys move from
  `queue-autoscale:cluster:<appid>:*` to `queue-autoscale:cluster:{<appid>}:*`
  so every key shares one slot. During a rolling deploy the old and new
  managers read different leader keys, so you can briefly have two leaders,
  each scaling its half of the fleet up to `workers.max`. It resolves itself
  once every manager is on the new version, and each recommendation is
  `setex`'d so nothing is stranded — but expect the window before you deploy.
- **Spawn-latency key format.** Spawn-latency keys move from `autoscale:spawn:*`
  to `{autoscale-spawn}:*` so the atomic EMA update stays in one slot. Existing
  latency history becomes unreachable on upgrade, so each tracker returns
  `fallbackSeconds` until it has collected `minSamples` again — short-lived and
  self-healing, not lost data.

- **The leader's per-workload bag is a typed object.** `$workloadMeta` carried nine
  string keys through all three phases of a cluster cycle; it is now
  `Cluster\EvaluatedWorkload`, which also owns the `isBreaching()`, `isScalable()`,
  `breachKey()` and `type()` derivations that were computed inline at each use.
  `AutoscaleManager` no longer contains a single `array<string, mixed>`.
- **`AutoscaleManager` decomposed into collaborators.** It was 3090 lines across
  67 methods with 20 mutable state fields, and six of the seven fixes in this
  release added to it. Nine classes now own what used to be inlined there:
  `Cluster\WorkerDistributor` (placement), `Cluster\ClusterCooldown` (damping),
  `Cluster\ClusterSummaryBuilder` (reporting), `Workers\WorkerScaler` (every
  change to the pool), `Scaling\QueueMetricsAdapter` (the boundary with
  laravel-queue-metrics), `Scaling\MeasuredResourceCollector`,
  `Scaling\WorkloadDiscovery`, `Scaling\WorkloadStateTracker` (per-workload
  cooldown and breach memory) and `Output\ConsoleReporter`. The manager is now
  1810 lines, 41 methods and 12 state fields. Behavior, log lines,
  configuration and the public surface are unchanged; every collaborator is a
  constructor default, so nothing a consumer wires up has to change.
- **Cluster placement and anti-flapping damping moved out of `AutoscaleManager`**
  into `Cluster\WorkerDistributor` and `Cluster\ClusterCooldown`. Both were
  self-contained leader working memory living as four mutable arrays on a 3090-line
  manager; each is now a collaborator owning its own state behind `pruneTo()` and
  `reset()`. `ClusterCooldown::apply()` returns a `CooldownDecision` instead of
  logging from inside the damping logic, so the calculation is a pure function of
  its inputs and the caller decides what to announce. Behavior, log lines and
  configuration are unchanged; the classes are constructor defaults, so nothing a
  consumer wires up has to change.

### Fixed

- **A worker's dying words are no longer dropped.** Forwarding worker stderr to
  the log channel skipped any worker that had already exited — which is exactly
  the worker whose stderr matters, because a fatal or an OOM writes its stack
  trace on the way out. The operator got "Removed dead worker, pid N" and
  nothing explaining it. Symfony keeps the output buffered after exit, and the
  cycle already drains before it reaps, so the final output is now read.
- **The partial-line buffer is capped.** Holding an unterminated line with no
  limit reproduced, inside the manager, the same unbounded retention the stderr
  drain was written to remove — a worker emitting a large blob with no trailing
  newline grew it for the worker's whole lifetime. Flushed truncated past 64 KB.
- **Group scaling reports what it achieved.** The correction applied to the
  per-queue paths — report the workers that actually started or were actually
  terminated, not the number requested — was never applied to the group paths,
  so a group whose every spawn failed still logged and emitted "scaled 0 → 5".
- **The cluster leader's own evaluation is isolated per workload.** Failure
  isolation reached the apply path and the single-host loop but not the leader's
  demand collection, which has the widest blast radius of the three: one bad
  config entry or throwing policy left *every* host in the cluster without a
  recommendation for the cycle, each holding against a stale one.

- **The orphan reaper could terminate another application's workers.** The
  manager id is the reaper's ownership token, but it was derived from host
  identity alone — hostname, machine-id, container env, resolved IP. Two
  applications deployed to one VM, which is the Forge/Ploi/VPS topology the
  deployment docs describe, therefore derived the *same* id, and one app's
  manager restarting SIGTERMed the other app's entire worker fleet. Containers
  were safe only by accident, through separate PID namespaces. The identity now
  folds in the application scope, so the guarantee the reaper's docblock always
  claimed is finally true. **Manager ids change on upgrade**: workers orphaned
  by a pre-upgrade manager are no longer recognised and will exit on their own
  `--max-time` instead of being reaped once.
- **Cluster hysteresis could disable rebalancing entirely.** The threshold was
  one worker's utilization on the *smallest* host, but the gate weighs a
  single-worker move, so the threshold has to live on a single worker's scale.
  A host reporting `maxWorkers` of 0 or 1 — which the capacity calculator
  produces under memory pressure, since it floors at zero — yielded a threshold
  of 1.0, and since the utilization spread is itself bounded by 1.0 no
  improvement of any size could ever clear it. The cached placement was then
  replayed forever and an idle host stayed idle. Measured on the largest host
  now; behaviour on a homogeneous fleet is unchanged.
- **The cluster cooldown could answer a scale-down with a scale-up.** The held
  value was the last target *published*, which the fleet may never have reached
  — a host ceiling, `max_total_workers`, or a failed spawn all leave the real
  count below it. A reversal inside the window then republished the stale higher
  number and hosts converged up to it. The hold is now clamped to what is
  actually running. Single-host mode never had this hazard because it holds by
  declining to act; the cluster path publishes a number hosts move toward.

- A manager that dies abruptly (for example SIGKILLed by the kernel OOM killer) no longer causes its replacement to double-provision. On startup the manager now scans procfs for workers stamped with this package's environment markers and its own manager id, SIGTERMs them, and logs a summary before the first spawn. Previously those orphans were invisible to the replacement (the worker pool is process-local), so it spawned a full new set on top of them and each doubled generation made the next OOM kill more likely. The reap is scoped to the same manager id (so deliberately co-hosted managers never touch each other's workers), skips on hosts without procfs, and can be disabled with `queue-autoscale.manager.reap_orphans_on_start`.

- Worker stderr is now drained every cycle and forwarded to the manager's log channel tagged with the worker PID, so worker log lines (job exceptions, memory warnings, and for containerized apps typically the whole application log channel) reach the container's log stream instead of accumulating unread inside the manager. Both stream buffers are also cleared after each read, and draining no longer depends on a renderer being attached, so a long-lived worker's output history no longer lives on in the manager's memory.

- One invalid discovered queue can no longer abort the entire evaluation cycle. Unsafe workload names (for example an empty queue name recorded by the metrics layer) are now filtered from the cluster leader's evaluation and from applied cluster recommendations, matching the single-host loop, and each workload's reconciliation is isolated so an exception for one queue is logged and the remaining queues still scale. Previously a single phantom queue wedged scale-up and worker respawn for every queue on the manager until the bad metric expired.

- With two or more managers, the leader no longer reshuffles workers between hosts on nearly every cycle. The distribution cache's balance check discarded the cached placement whenever moving any single worker would improve the utilization spread by more than 0.000001, but the spread's inputs (each host's live-CPU/memory-derived maxWorkers) jitter every heartbeat, so an idle cluster still tore down and re-booted workers continuously. The check now requires an improvement worth at least one worker's utilization on the smallest host before rebalancing, and workloads are distributed in a stable sorted order so the check is comparable across cycles. Placement may shift once on upgrade as the new ordering takes effect, then holds steady; a genuinely skewed placement (one worker's worth or more) still rebalances as before.

- The anti-flapping cooldown now applies in cluster mode. The single-host paths have always damped scaling direction reversals through `scaling.cooldown_seconds`, but the cluster leader recomputed and republished every workload's target each cycle with no equivalent guard, so a demand signal that oscillates cycle-to-cycle was executed as real spawns on one evaluation and kills on the next, cluster-wide, on the evaluation cadence. The leader now damps direction reversals before distribution using the same semantics as the single-host guard (only reversals are held, the last direction goes stale after the window, and a scale-up during an SLA breach always passes), holding the previously published target for the workload. The damping state is leader working memory and resets on leadership change, so a failover costs one undamped cycle.

### Removed

- The `test-autoscale/` directory, an abandoned `laravel new` skeleton (54 files)
  that was committed to the repository and, because it was never listed in
  `.gitattributes` as `export-ignore`, shipped inside every dist archive. It did
  not require this package, declared no `queue-autoscale` config, and contained no
  reference to the package at all, so it exercised nothing — the real integration
  coverage lives in `tests/Integration`, `tests/Simulation` and the Redis Cluster
  CI job. Also dropped two dangling skeleton leftovers: the `Workbench\App\`
  autoload-dev entry pointing at a `workbench/app/` directory that does not exist,
  and a `Factory::guessFactoryNamesUsing()` call in the base `TestCase` naming a
  `Database\Factories` namespace this package does not have.

### Documentation

- Added a root `SECURITY.md` so GitHub surfaces the private reporting channel. It
  points at `docs/advanced-usage/security.md` as the canonical version rather than
  duplicating it, and claims only controls that exist: the queue-name guard, the host
  worker ceiling, and the CI gates that actually run.
- The API reference was a single 607-line `_index.md`. It is now six topic pages behind
  a section landing, with `WorkerScaler` documented alongside the pool and spawner.
- README is titled **Cbox Queue Autoscale**, matching the branding the sibling packages
  use; "Queue Autoscale for Laravel" remains the descriptor.

## v4.0.1 - 2026-08-14

**Upgrade promptly if you use queue groups: v4.0.0 could not start a group
worker at all.** Everything below is a fix; there are no new features and no
breaking configuration changes.

### Queue groups were completely broken

The queue-name guard v4.0.0 added against argument injection refuses commas,
because a comma makes `queue:work` read the value as a priority list. A group
worker polls several queues and the package joins them with commas on purpose,
so the guard rejected the package's own argument and every group worker threw
on spawn:

```
Refusing to spawn a worker for 'redis:email,sms': a comma makes queue:work
treat it as a list of queues
```

For a group the comma is now the separator and each member is validated on its
own. An injected option inside a member is still caught.

### Policies were never applied in cluster mode

The policy chain ran only on the single-host evaluation paths. In cluster mode
the leader's recommendation is applied by different code, and none of it
consulted the executor — so a policy went silently inert the moment cluster
mode was enabled, with no error and no log line saying it had been skipped. A
policy written to cap workers simply did not.

Every path that acts on a scaling decision now goes through one method, which
is what stops the two from drifting apart again. Pinned queues are included:
`min == max` leaves a policy little to move, but one that reports or alerts
should not fall silent because a queue happens to be pinned. Policies also see
a hold now, as they always did on a single host — that is what lets a policy
raise a target the strategy left alone.

### Fixed

- **Pickup sampling was per process, not per queue.** A group worker polls
  several queues at once, so a queue running at five thousand jobs a second set
  the sampling probability for the queue beside it running at two — the quiet
  queue never reached `sla.min_samples`, its p95 never existed, and the
  SLA-driven strategy went blind on it for as long as its noisy neighbour
  stayed hot.
- **Scale-down reported what it asked for, not what it did.** Some workers may
  already be draining, so the log and the `WorkersScaled` event described a
  pool state that was never reached.
- **Telemetry queue labels were unbounded.** Queues are discovered rather than
  listed, so per-tenant naming meant one time series per tenant per metric.
  Capped by `telemetry.max_queue_labels` (100; `null` disables), with further
  queues sharing an `__other__` bucket.
- **The manager id could be identical across containers.** It is derived from
  container-runtime variables to keep two managers on one host apart, but they
  were read through the config layer — so an image built with `config:cache`
  baked in the build host's values and every container from it derived the same
  id.
- **Pinned queues skipped the policy chain**, and in cluster mode a policy
  never saw a hold — so a policy could not raise a target the strategy had left
  alone, as it can on a single host.
- **The fair-share allocator returned more than the capacity it was given**
  when the minimums did not fit, after which the overflow was dropped in
  metrics-discovery order — so the starved queue changed from cycle to cycle.
- **The fencing token did not fence.** It was checked by the reader and never
  by the writer, so a deposed leader still overwrote the live leader's
  recommendation keys.
- **A group's fuse could never trip.** Workers recorded outcomes with the
  member queue's window while the manager read them back with the group's.
  The bucket is part of the cache key, so the two halves wrote and read
  disjoint keys and the fuse stayed closed through a total outage.
- **The manager could leave every worker running.** An exception escaping the
  evaluation loop skipped shutdown entirely, so a one-second cache blip
  orphaned the whole pool until `--max-time` — an hour by default — while the
  supervisor restarted the manager on top of it.
- **The manager lock was inherited by spawned workers.** Without `O_CLOEXEC`
  the workers held the `flock` after the manager exited, so the replacement
  crash-looped until they aged out. Thanks to @Orrison for the diagnosis and
  the fix.
- **A draining worker was invisible to the worker counts.** For a queue pinned
  to exactly one worker — where two running at once is the thing the
  configuration exists to prevent — a replacement could be spawned alongside
  the one still finishing its job.
- **Cache keys could collide across tenants.** Keys interpolated connection
  and queue around a colon, and queue names may contain colons, so `redis` +
  `a:b` equalled `redis:a` + `b`. Reachable by anyone who chooses a queue
  name: collide with a victim's fuse counters, fail your own jobs, and their
  queue is pinned to `workers.min` for the cooldown.
- **`--replace` trusted the PID in the lock file**, whose directory was created
  world-writable. Anything able to write to `storage/` could have an operator
  SIGTERM an arbitrary process. The directory is now `0755`, and the PID is
  checked for same-owner and a `queue:autoscale` command line before
  signalling.
- **Spawn-latency records collided across hosts**, being keyed by bare PID in
  shared Redis for five minutes, so spawn compensation was computed from
  another queue's numbers.
- **A manager regaining cluster leadership replayed a stale layout**, churning
  every host to match a picture from before it lost the lease, for no change
  in demand.
- **The fair-share allocator returned more than the capacity it was given**
  when the minimums did not fit, after which the overflow was dropped in
  metrics-discovery order — so the starved queue changed from cycle to cycle.
  Floors are now scaled down proportionally and deterministically.
- **The fencing token did not fence.** It was checked by the reader and never
  by the writer, so a deposed leader still overwrote the live leader's
  recommendation keys. The check and the write are now one operation.
- **Per-queue bookkeeping grew without bound** in a process meant to run for
  weeks — one entry per tenant that ever dispatched a job.
- **`pickup_time.max_samples_per_queue = 0`** kept every sample forever rather
  than none, because the trim bound became `-1`.

### Changed

- **Cache key format.** Pickup samples and fuse counters written by v4.0.0
  become unreachable on upgrade. Both are short-lived caches with TTLs, so the
  cost is one evaluation cycle of cold-start behaviour — the fuse rebuilds its
  window within `window_seconds`, pickup samples within the SLA window — not
  lost data.
- **`ScalingDecisionMade` in cluster mode.** The leader used to emit it for the
  whole fleet with the pre-policy target, which stopped describing what any
  host did once policies could change that target per host. It now comes from
  each host as it acts, so listeners receive one event per host per workload
  rather than one from the leader. The cluster's own view remains available as
  `ClusterSummaryPublished` and `ClusterScalingSignalUpdated`.
- `composer analyse` clears the package-discovery manifest and PHPStan's result
  cache first, and passes the memory limit the command always needed. A stale
  manifest made larastan narrow configurable container bindings to whatever was
  bound by default, so the same source analysed differently on a developer's
  machine than in CI — and without the memory limit the run crashed a parallel
  worker and reported that crash as a finding.

### Documentation

Claims audited against the source rather than read for sense:
`ProcessHealthCheck` described as a live class in four pages though it was
deleted in v4; "the CPU sample blocks for a second" in six, including
interval-tuning advice built on a cost that no longer exists; and seven
profiles reported as six, with `ConnectionLimitedProfile` missing from every
list and both comparison tables — including the page the FIFO cookbook links
to when it recommends that profile.

## v4.0.0 - 2026-08-05

The first release to carry the failure fuse, which merged without a tag. Everything below the
breaking changes shipped in that work; everything in them is new to this release.

See the [upgrade guide](docs/advanced-usage/upgrade-guide-v4.md) for the full migration, and run
`php artisan queue:autoscale:doctor` after upgrading — it reads your configuration against the queues
you actually have.

### Breaking

- **PHP 8.4 is the minimum, and Laravel 11 is dropped.** Pest stays on 4.x rather than 5, because
  `pest-plugin-laravel` v5 requires Laravel 13 and a library has to install on the current and
  previous major.
- **`workers.timeout_seconds` is now two settings.** It was passed as `--max-time`, the worker
  *process's* lifetime, despite reading like a job timeout — so an operator setting 3600 believed
  they had allowed hour-long jobs while jobs still died at Laravel's default. `max_time_seconds`
  is now the process lifetime and `timeout_seconds` the per-job limit, and configuration refuses a
  job timeout that is not shorter than the process it runs in.
- **The global `queue-autoscale.workers` block is gone.** It was the only one that reached a spawned
  worker, so the same keys set on a profile were validated and ignored. The profile is now the only
  surface. `workers.shutdown_timeout_seconds` becomes `manager.shutdown_grace_seconds`, and is a
  fallback rather than a floor — a pool of fast queues no longer waits for the slowest global value.
- **Cluster metric names now agree across both surfaces.** `clusterMetrics()` and the telemetry
  gauges disagreed about five names, so a query written from one page returned nothing against the
  other endpoint — indistinguishable from the metric reading zero. `hosts_recommended` →
  `recommended_hosts`, `workers_current` → `workers`, `workers_required` → `required_workers`,
  `cluster.capacity` → `cluster.worker_capacity`, and every `manager_*` → `cluster_host_*`. A test
  now pins the agreement rather than the names.
- Deleted `Workers\ProcessHealthCheck` and `Output\DataTransferObjects\JobActivity`, both unused.

### Added

- **Queue matching by glob.** `queue-autoscale.queues` accepts pattern keys, so `scrape-tenant-*` can
  govern every tenant queue at once — necessary when queue names are generated at runtime and cannot
  be enumerated. Exact names win over patterns, and overlapping patterns resolve in declaration
  order. A queue entry may also name a `profile` alongside its own overrides, matching what groups
  already allowed.
- **`ConnectionLimitedProfile`** for queues whose parallelism is dictated by something downstream —
  an API that accepts five concurrent callers, a fixed database connection budget. The worker count
  becomes the limit, which avoids the `RateLimited`/`WithoutOverlapping` trap of releasing jobs back
  onto the queue and spending retry budget on lock contention. Verified against real backlogs on both
  Redis and SQS, including that the cap is a fleet total rather than a per-host allowance.
- **`queue:autoscale:doctor`** reports configurations that are valid and still govern the wrong
  queues: patterns matching nothing, what each glob actually caught, queue names SQS rejects, caps
  that are per-host because cluster mode is off, pattern matching with no `limits.max_total_workers`,
  and FIFO queues allowing more parallelism than their message groups may support.
- **`src/Testing`** — `InMemoryFailureWindowStore`, `FakeClusterStore`, `QueueMetricsFactory` and an
  `InteractsWithAutoscaling` trait, so applications can assert what their own configuration will do
  without Redis and without waiting for load.
- **`ClusterStoreContract`.** The cluster store was the last capability not behind a contract, which
  left everything reading cluster state untestable without live Redis.
- SQS and FIFO queues are covered by integration specs running against ElasticMQ.

### Changed

- **Pickup samples cost one round trip and are sampled above a configurable rate.** Recording a
  pickup issued LPUSH then LTRIM per job — two round trips on the hot path of every job the
  application runs, against the same Redis the queue uses. They now travel as one Lua call. Above
  100 pickups per second per worker process, a uniformly random subset is stored: since only
  `max_samples_per_queue` entries ever survived, the discarded writes were already being trimmed
  away, and the survivors described the last instant of the window rather than the window itself.
  Sampling covers the window better and writes less.
- **Queue names containing dots reach their own configuration.** The lookup used dot notation, so a
  queue named `tenant.42` — or any `.fifo` queue — was unreachable and silently ran on defaults. If
  you have such a queue, its configuration starts applying on upgrade.

### Failure fuse (circuit breaker for scaling decisions)

A downstream outage is indistinguishable from load to an autoscaler: jobs fail, get released, the backlog grows and the oldest job ages — so the naive response is to add workers, which only increases pressure on the failing dependency and burns each job's retry budget faster.

The fuse watches the recent job failure rate per queue and interrupts that loop:

- **Trips** when the failure rate crosses the threshold with enough samples behind it, and holds the queue at `workers.min` instead of scaling up
- **Probes** with a single worker after a cooldown, then either closes (normal scaling resumes) or re-trips
- **Events:** `FuseTripped`, `FuseProbing`, `FuseRecovered` for alerting; scaling decisions report `fuse` as their limiting factor and explain themselves in the decision reason
- Per-queue tuning via the profile `fuse` block (`failure_threshold_percent`, `min_samples`, `window_seconds`, `cooldown_seconds`); each built-in profile ships tuning matched to its traffic shape, and `ExclusiveProfile` opts out since a pinned queue has no scale-up to hold back
- Applies to every strategy — including custom ones — because it lives in `ScalingEngine`, and constrains cluster-wide demand as well as per-host decisions
- Failures are counted from `JobExceptionOccurred` rather than `JobFailed`, so detection does not wait for retries to be exhausted
- Outcome counters go through Laravel's cache (any driver), so the fuse works in single-host mode without Redis
- Disable globally with `QUEUE_AUTOSCALE_FUSE_ENABLED=false`, or per queue with `'fuse' => ['enabled' => false]`

**Telemetry:** with `cboxdk/laravel-telemetry` installed, the fuse publishes `queue_autoscale.fuse.state` (0 closed, 1 half-open, 2 open), a `queue_autoscale.fuse.trips` counter, and `queue_autoscale.fuse.tripped` / `.probing` / `.recovered` OTLP events.

**Failure classification:** `ignored_exceptions` lists exception classes that carry no signal about capacity — a job that threw a validation error on its own payload never reached the dependency. Matched by `instanceof`; ignored exceptions are dropped entirely rather than counted as successes. For decisions a class list cannot express, implement `FailureClassifierContract` and point `fuse.classifier` at it. Rate limits and auth errors are counted by default, deliberately unlike a job-level circuit breaker: more workers never fix either.

**Detection latency:** the fuse sums the current and previous window bucket so it never loses its evidence at a bucket boundary. The cost is that pre-outage traffic dilutes the failure rate until it ages out, so worst-case time to trip is `2 x window_seconds`. Shorten the window for faster detection.

**Docs:** [Failure Fuse](docs/basic-usage/failure-fuse.md) covers the state machine, tuning, detection latency and troubleshooting; [Alert on a Fuse Trip](docs/cookbook/alert-on-fuse-trip.md) is a paste-and-go listener recipe.

### Added
- The manager logs `Autoscaling held back by failure fuse` at warning level for as long as a queue is held, rate-limited by `alerting.cooldown_seconds`. Scaling actions are only logged when they happen, and a held queue scales once — down to `workers.min` on the trip — then holds, so the log previously fell silent for the rest of the outage.
- `queue:autoscale:debug` now reports failure-fuse state, the observed failure rate against the configured thresholds, and warns when the fuse is inert because outcome tracking is disabled or the `array` cache driver is in use. This answers "why is this queue stuck at `workers.min`?" without reading manager logs.

### Changed
- **Package classes are no longer `final`.** Sealing blocks consumers from extending, decorating or subclassing what the package ships. The arch test now asserts immutability instead, and a new rule keeps the classes open.
- **PHPStan runs at level max with no baseline.** Three of the baseline's four entries were suppressing "unreachable code" findings in the SIGTERM-wait-SIGKILL escalation, which is live code that analysis could not model; `WorkerProcess::isRunning()`/`isDead()` are now marked `@phpstan-impure`. The remaining errors were genuine typing holes, fixed at the cause.
- Dropped `spatie/laravel-package-tools`, a runtime dependency referenced nowhere in `src/`.
- Added `limits.max_total_workers`, an optional hard ceiling on total workers per host.
- Per-queue `workers.tries` / `sleep_seconds` / `shutdown_timeout_seconds` now reach the spawned worker. They were parsed and then ignored. (The two timeout keys were split at the same time — see Breaking.)
- Declared `ext-pcntl` and `ext-posix`, which the manager calls unguarded and which are commonly absent from the official PHP Docker images.

### Fixed
- **The manager no longer blocks for a full second every evaluation cycle.** CPU usage was sampled by sleeping between two counter reads (measured 1004.91 ms); it is now derived by diffing against the previous tick's snapshot. On the default 5-second interval a fifth of the manager's wall clock was spent asleep and every decision was computed from metrics a second stale. The test suite drops from ~70s to ~10s as a side effect.
- **Shutdown no longer orphans workers.** Workers were terminated serially, each blocking up to `shutdown_timeout_seconds`, so a supervisor's stop deadline killed the manager before it reached the end of the pool. Termination now runs under one shared deadline.
- **`SIGQUIT` and `SIGHUP` reach graceful shutdown.** They were unhandled, so PHP terminated the manager outright and its workers were never signalled.
- **A leader that publishes no group workloads no longer drains them cluster-wide.** An absent workload key was read as a target of zero; it now means "the leader does not know about this workload" and followers leave it alone.
- **Scaling events report what actually started.** Failed spawns were dropped by the spawner but still reported as successes to telemetry, logs and the cluster heartbeat.
- **Per-queue forecast configuration applies to every queue.** The shared estimator was configured once per process, so the first queue evaluated set the forecaster and policy for all of them.
- The failure fuse fails open when its store is unreachable, rather than aborting the whole evaluation cycle and freezing autoscaling for every queue on the host.
- The failure fuse works for queue groups. It read the group name while workers record under the real queue, so a grouped queue could never trip it.
- The failure fuse's half-open probe has a deadline. A queue whose jobs were slower than `2 x window_seconds / min_samples` could never gather enough samples to close it, and stayed pinned at the probe ceiling after the dependency recovered.
- `limitingFactor` is an enum. As a string it had drifted from three documented values to six live ones, and the `-vvv` output silently printed nothing while the fuse was holding a queue down.
- The manager's per-PID output buffers are pruned when a worker is removed, and `managerId()` is memoised — it performed a DNS lookup inside per-manager loops.
- `queue:autoscale:install` published metrics migrations under a tag that does not exist. `vendor:publish` exits 0 on an unknown tag, so a database-preset install reported success while writing no migration and failing later on missing tables.
- `ClusterStore::recentDecisions()` no longer returns malformed decision records that decoded from JSON as positional arrays rather than objects.
- The test suite pins `CACHE_STORE` and `QUEUE_CONNECTION` in `phpunit.xml.dist`. It previously inherited them from the Testbench skeleton `.env`, which is created the first time anyone runs `vendor/bin/testbench` and points both at `database` — silently breaking ~70 cache- and queue-dependent specs against a test database that has neither table.

## v3.11.1 - 2026-07-15

### Fixed
- Corrected a dead link in the requirements page that pointed at `deployment-guides/docker.md`; it now links to the existing `deployment/docker.md` guide.

### Changed
- Documentation normalized for the docs site.
- Bumped the `cboxdk/laravel-telemetry` dev requirement.

## v3.11.0 - 2026-07-06

### OpenTelemetry integration via cboxdk/laravel-telemetry

When [`cboxdk/laravel-telemetry`](https://github.com/cboxdk/laravel-telemetry) (>= v0.2.0, requires Laravel 12+) is installed, the autoscaler automatically publishes its scaling signals — no configuration needed, no-op when absent:

- **Gauges/counters:** `queue_autoscale.workers.target`, `queue_autoscale.sla.breach` (live breach state), `queue_autoscale.sla.predicted_pickup`, `queue_autoscale.capacity.max_workers`, `queue_autoscale.scaling.actions` and more — plus 8 observable cluster gauges in cluster mode
- **Structured OTLP events:** scaling actions (with full reason context), SLA breaches/recoveries, manager lifecycle, leader changes
- Deliberately no overlap with `queue_metrics.*` or laravel-telemetry's own queue instrumentation — see the README metric catalog
- Disable with `QUEUE_AUTOSCALE_TELEMETRY_ENABLED=false`; check status via `php artisan queue:autoscale:debug`
- Remember to schedule the telemetry package's `telemetry:flush` so stored metrics ship to your OTLP endpoint

### What's Changed

* chore(deps): bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/cboxdk/laravel-queue-autoscale/pull/35
* Optional laravel-telemetry (OpenTelemetry) integration by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/37

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.10.0...v3.11.0

## v3.10.0 - 2026-07-06

### ⚠️ Behavior change: the manager now honors `php artisan queue:restart`

The autoscale manager exits gracefully for a supervised restart when Laravel's native `queue:restart` signal is issued — your standard deploy pipeline now restarts the manager with no package-specific step.

- **Forge users:** the default deploy script's "Restart Queue Workers" step now restarts the autoscale manager on every deploy (this is what you want — fresh release code — but it is new behavior).
- **Opt out** if `queue:restart` must only affect separately-supervised workers: set `QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART=false`.
- `php artisan queue:autoscale:restart` is unchanged and still restarts only the manager.
- Note for multi-app setups sharing one cache backend: `illuminate:queue:restart` is an unscoped key, so another app's `queue:restart` also restarts this manager — see the config reference for isolation via `restart_scope` + the opt-out.

### What's Changed

* Honor Laravel queue:restart signal in the autoscale manager by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/36

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.9.1...v3.10.0

## v3.9.1 - 2026-06-19

### What's Changed

* Weight cluster distribution by host capacity by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/34

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.9.0...v3.9.1

## v3.9.0 - 2026-06-19

### What's Changed

* Harden cluster leader leases by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/30
* Make worker scale-down non-blocking by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/31
* Refresh capacity each evaluation cycle by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/32
* Make spawn latency EMA updates atomic by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/33

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.8.0...v3.9.0

## v3.8.0 - 2026-06-18

### What's Changed

* chore(deps): bump codecov/codecov-action from 6 to 7 by @dependabot[bot] in https://github.com/cboxdk/laravel-queue-autoscale/pull/28
* [codex] Stabilize autoscaling worker targets by @sylvesterdamgaard in https://github.com/cboxdk/laravel-queue-autoscale/pull/29

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.7.0...v3.8.0

## v3.7.0 — Deployment-safe autoscale restarts - 2026-06-04

### Added

- **Deployment-safe `queue:autoscale:restart` signal** — The restart command now writes a global, deployment-stable cache signal so deploy hooks can ask the running autoscale manager to drain spawned workers and exit. The process supervisor then restarts `queue:autoscale` from the current release. (#27)
- **`manager.restart_scope` config key** (env: `QUEUE_AUTOSCALE_RESTART_SCOPE`) — Optional cache-scope override for installs where multiple apps share the same cache backend and need isolated autoscale restart signals.

### Changed

- **Restart signal compatibility preserved** — Managers now check both the new deployment-stable restart key and the legacy manager-scoped key, so existing restart signals remain compatible during upgrades.
- **Deployment documentation now prefers Artisan restarts** — Forge, Ploi, self-hosted, installation, README, and troubleshooting docs now recommend `php artisan queue:autoscale:restart` for deploy hooks, with direct `supervisorctl` / `systemctl` restarts documented as operational fallbacks only.

### Testing

- Added coverage for restart signal scoping, legacy signal compatibility, command signal writes, and manager shutdown/drain behavior after a restart request.

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.6.3...v3.7.0

## v3.6.3 — Cluster scale-up signal & cumulative decision history - 2026-05-18

### Fixed

- **Cluster `scale_up` signal now fires when demand exceeds capacity** — `clusterScaleSignal()` derived `required_workers` from `target_workers`, which is already clamped by fair-share allocation and per-host capacity (so `target_workers` can never exceed `total_worker_capacity`). The `required_workers > total_worker_capacity` branch was therefore unreachable, leaving the cluster signal stuck on `hold` even when host count was the binding constraint. The signal now uses each workload's unclamped `demand` (the strategy's recommendation before capacity clamping) so over-capacity demand correctly surfaces as `scale_up` with a `recommended_hosts` count. (#25, #26)
- **`scaling_decisions` in cluster summary now reflects rolling history** — Decisions were rebuilt from an ephemeral per-cycle array on the leader, so the cluster summary always contained at most one cycle's worth. Downstream dashboards (queue-monitor's Total Decisions / Scale Ups / Scale Downs cards) only saw what happened in the last evaluation. Decisions are now persisted to a Redis sorted set with atomic add-and-trim, and the cluster summary rebuilds `scaling_decisions` from the rolling window each tick. (#24)

### Added

- **`demand` field on cluster workload entries** — Each entry in `cluster.workloads[]` now exposes both `demand` (unclamped strategy recommendation) and `target_workers` (post-fair-share, post-capacity). Custom dashboards can compare the two to surface "wants N, got M" backpressure visualizations.
- **`cluster.decision_history_seconds` config key** (default: `3600`, env: `QUEUE_AUTOSCALE_DECISION_HISTORY`) — Retention window for the scaling-decision rolling history.
- **`cluster.decision_history_max` config key** (default: `10000`, env: `QUEUE_AUTOSCALE_DECISION_HISTORY_MAX`) — Hard cap on entries in the rolling history sorted set; older entries are trimmed by rank when the cap is reached.
- **`ClusterStore::recordDecision()` and `ClusterStore::recentDecisions()`** — Public methods on the cluster store. The record path uses a single atomic Lua script (`ZADD` + `ZREMRANGEBYSCORE` + `ZREMRANGEBYRANK` + `EXPIRE`) per decision.

### Testing

- 565 tests, 1668 assertions
- New `ClusterDecisionHistoryTest` suite (11 tests covering record/read/trim/TTL behavior)
- 4 new tests in `ClusterScalingDecisionsTest` (3 for the unclamped-demand path, 1 for end-to-end decision history)

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.6.2...v3.6.3

## v3.6.2 — Fix worker pool thrashing in cluster distribution layer - 2026-04-30

### Fixed

- **Stable target now produces stable worker assignments** — `distributeClusterTarget()` sorted managers by live heartbeat worker counts, which fluctuate tick-to-tick as workers spawn and die. Different sort orders produced different per-host assignments each cycle, causing workers to be killed on one host and respawned on another every 2–3 seconds despite an unchanged cluster-wide target. The distribution layer now caches previous assignments and reuses them when the target, manager set, and per-host capacity are all unchanged. Fresh computation only triggers when the target actually changes, a manager joins or leaves, or capacity constraints shift. (#21)

### Testing

- 550 tests, 1595 assertions
- 6 new tests in `ClusterDistributeTargetTest`, including a 30-cycle stability invariant

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.6.1...v3.6.2

## v3.6.1 — Restore scaling decision logging in cluster mode - 2026-04-30

### Fixed

- **Scaling decisions now recorded in cluster mode** — The cluster evaluation path (`evaluateAndPublishClusterRecommendations`) was missing decision logging entirely. The `scaling_decisions` array in the cluster summary was always empty, causing downstream dashboard cards (Total Decisions, Scale Ups, Scale Downs, SLA Breaches, SLA Recoveries, Predictions) to show 0. Scaling decisions are now recorded in Phase C of the cluster evaluation loop for every workload where target differs from current workers. (#20)
- **`ScalingDecisionMade` events now fired in cluster mode** — The event was only dispatched in the non-cluster path (`evaluateQueue`/`evaluateGroup`). It is now fired for every workload evaluated by the cluster leader.
- **SLA breach/recovery events now fired in cluster mode** — `SlaBreached`, `SlaRecovered`, and `SlaBreachPredicted` events were missing from the cluster evaluation path. They are now emitted with the same semantics as the non-cluster path.

### Testing

- 545 tests, 1549 assertions
- New test suite: `ClusterScalingDecisionsTest` (4 tests)

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.6.0...v3.6.1

## v3.6.0 — Fair-share allocation between queues - 2026-04-30

### Added

- **Fair-share allocation layer** — When total per-queue demand exceeds cluster capacity, workers are now distributed proportionally instead of first-queue-wins. The new `FairShareAllocator` sits between demand evaluation and cross-host distribution, using min-first then proportional allocation with water-filling iteration. (#16)
- **Water-filling redistribution** — When max-clamping reduces a queue's allocation, freed capacity is automatically redistributed to other eligible queues. Converges in 2–3 iterations.
- **Pinned queue capacity reservation** — Non-scalable (pinned) workloads are subtracted from cluster capacity before fair-share runs, ensuring they don't compete with scalable queues.

### Changed

- **`evaluateAndPublishClusterRecommendations()` refactored** — The single evaluate-and-distribute loop is now three phases: collect all demands (Phase A), fair-share allocate (Phase B), distribute adjusted targets across hosts (Phase C). Workload summaries reflect post-fairness targets.
- **`workers.max` semantics clarified** — Max is now purely a safety bound (rate-limits, connection pools), not a fairness mechanism. Operators no longer need to set max low to prevent queue starvation.

### Testing

- 541 tests, 1541 assertions
- New test suites: `FairShareAllocatorTest` (14 tests), `ClusterFairShareIntegrationTest` (2 tests)

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.5.0...v3.6.0

## v3.5.0 — Per-queue resource awareness - 2026-04-30

### Added

- **Per-queue resource estimates with three-source resolution** — Capacity calculations now use per-queue CPU and memory estimates instead of a single global average. The `ResourceEstimateResolver` resolves estimates through a precedence chain: measured runtime data → per-queue config override → global default. Each dimension (CPU, memory) resolves independently. (#14)
- **Adaptive memory measurement** — `AutoscaleManager` now tracks per-queue memory usage from `queue-metrics` job data alongside the existing CPU tracking. Memory capacity was previously static config only (`worker_memory_mb_estimate`); it is now measured per queue and adapts automatically.
- **Per-queue `resources` config** — Operators can declare `cpu_cores` and `memory_mb` per queue as cold-start fallbacks before measured data is available:
  ```php
  'queues' => [
      'slow' => [
          'resources' => [
              'cpu_cores' => 0.5,
              'memory_mb' => 2048,
          ],
      ],
  ],
  
  
  
  
  
  ```
- **`ResourceEstimate` value object** — Carries CPU/memory estimates with per-dimension source metadata (`measured`, `config`, `default`) and sample counts, enabling downstream consumers to inspect provenance.
- **`EstimateSource` enum** — `Measured`, `Config`, `Default` — tracks where each dimension of a resource estimate originated.

### Fixed

- **Target oscillation under steady-state load** — Added `TargetSmoother` that suppresses jitter when the target changes by less than 1 full worker (e.g. oscillating between 4 and 5 under stable load). Uses EMA smoothing with a dead-band to prevent unnecessary scale-up/scale-down cycles. (#15)

### Changed

- **`CapacityCalculator::calculateMaxWorkers()` signature** — Now requires a `ResourceEstimate` parameter (was optional internal state). All callers pass either a resolved per-queue estimate or `ResourceEstimate::globalDefault()`.
- **`ScalingEngine` constructor** — Now accepts a `ResourceEstimateResolver` as third parameter.
- **`AutoscaleManager` constructor** — Now accepts a `ResourceEstimateResolver` as last parameter.
- **`updateMeasuredWorkerCpuEstimate()` replaced by `updateMeasuredResourceEstimates()`** — The old method computed a single global weighted-average CPU estimate across all job classes. The new method groups by `connection:queue` and tracks both CPU and memory per queue, feeding results into the `ResourceEstimateResolver`.
- **Memory capacity details** — `memory_details` in capacity breakdown now includes `memory_estimate_source` field.

### Breaking Changes

- `CapacityCalculator::calculateMaxWorkers()` requires a second `ResourceEstimate` parameter — code calling this method directly must pass `ResourceEstimate::globalDefault()` or a resolved estimate.
- `CapacityCalculator::setMeasuredWorkerCpuCoreEstimate()` removed — CPU estimate state moved to `ResourceEstimateResolver`.
- `ScalingEngine` constructor requires a third `ResourceEstimateResolver` parameter.

### Testing

- 525 tests, 1473 assertions
- New test suites: `ResourceEstimateTest`, `ResourceEstimateResolverTest`, `ResourceAwareCapacityTest`, `TargetSmootherTest`, `HybridStrategyBehaviourTest`

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.4.0...v3.5.0

## v3.4.0 — Cluster scaling fixes - 2026-04-30

### Fixed

- **CPU capacity defaults corrected for containerized workloads** — `reserve_cpu_cores` changed from `1` to `0.2` (type widened from `int` to `float`), `worker_cpu_core_estimate` changed from `1.0` to `0.2`. A 0.5-core container now correctly supports workers instead of reporting 0 capacity.
- **Usable cores guard relaxed for fractional cores** — Changed from `max(cores - reserve, 1)` to `max(cores - reserve, 0)`. The previous floor of 1 inflated capacity on sub-1-core containers.
- **Cluster leader no longer applies local-host capacity to cluster-wide targets** — `clusterTargetWorkers()` now uses `ScalingEngine::evaluateDemand()` (strategy + config bounds only), so `required_workers` reflects actual demand. Per-host capacity enforcement happens during distribution.
- **`distributeClusterTarget()` now respects per-host `maxWorkers`** — Phase 2 skips hosts at capacity instead of over-allocating. Remaining workers that exceed total cluster capacity are not assigned.
- **`clusterScaleSignal()` no longer recommends scale-down under pressure** — Blocks scale-down when utilization ≥ 80% or any workload has pending jobs.

### Added

- **`ScalingEngine::evaluateDemand()`** — Returns unconstrained strategy recommendation clamped only by config bounds (workers.min/max), for cluster-wide demand calculation.

### Breaking Changes

- `AutoscaleConfiguration::reserveCpuCores()` now returns `float` (was `int`).
- `ClusterManagerState::$cpuReservedCores` is now `float` (was `int`). Downstream consumers parsing this field as strict `int` may need updating.
- Default `reserve_cpu_cores` changed from `1` to `0.2` — existing deployments relying on the old default will see more workers spawned per host.
- Default `worker_cpu_core_estimate` changed from `1.0` to `0.2` — same effect; publish and review config if you had not overridden this value.

### Testing

- 480 tests, 1175 assertions
- New test suites: `ClusterScaleSignalTest`, `ClusterDistributeTargetTest`, `ScalingEngineEvaluateDemandTest`

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.3.0...v3.4.0

## v3.3.0 — system-metrics v3 / queue-metrics v3 chain - 2026-04-29

### Changed

- **`cboxdk/laravel-queue-metrics` constraint bumped to `^3.0`** — pulls in `cboxdk/system-metrics` v3 which returns fractional CPU core counts from cgroup limits (e.g. 0.5 cores in a Docker container with `--cpus=0.5`)
- **`ClusterManagerState::$cpuCores` widened from `int` to `float`** — heartbeats and cluster summaries now carry the fractional value reported by the system-metrics package
- **`ClusterManagerState::$cpuUsableCores` widened from `int` to `float`** — computed from `total_cores - reserve_cores`, preserves fractional precision from cgroup limits
- **`CapacityCalculator::$cachedAvailableCores` widened from `int` to `float`** — capacity math uses the fractional core count directly, producing more accurate worker limits in cgroup-constrained environments

### Breaking Changes

- `ClusterManagerState::$cpuCores` is now `float` (was `int`). Code that type-checks or strict-compares this field may need updating.
- `ClusterManagerState::$cpuUsableCores` is now `float` (was `int`). Same applies.
- `ClusterManagerState::$cpuReservedCores` remains `int` — this is a user-configured whole-core reservation, not a system-reported value.
- The cluster summary payload fields `cpu_cores` and `cpu_usable_cores` may now contain floats (e.g. `0.5`) where they previously always contained integers.

### Testing

- 464 tests, 1127 assertions
- Parametrized round-trip test for fractional CPU core values (0.2, 0.5, 1.5, 2.0, 4.0) covering both `cpuCores` and `cpuUsableCores`

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.2.0...v3.3.0

## v3.2.0 — CPU Core Fields in Heartbeat - 2026-04-29

### Added

- `cpu_cores`, `cpu_usable_cores`, and `cpu_reserved_cores` fields in `ClusterManagerState` heartbeat and cluster summary (#10, #11)
- Enables the queue-monitor dashboard to display CPU core count alongside CPU percentage in the Hosts panel

### Notes

- CPU cores are reported as integers matching the current `cboxdk/system-metrics` return type
- Fractional core support for containerized environments (e.g. Kubernetes millicores) is tracked in cboxdk/system-metrics#6

## v3.1.0 — Measured CPU Capacity - 2026-04-29

### Added

- **Measured per-worker CPU estimation from job metrics** — The capacity calculator now derives actual CPU core usage per worker from `queue-metrics` v2.8.0+ job processing data (`cpuTimeMs / durationMs`), replacing the previous implicit assumption that each worker consumes a full CPU core. Falls back gracefully to config-based estimate on older `queue-metrics` versions.
- **`worker_cpu_core_estimate` config option** — New config key under `limits` (default `0.2`) provides a baseline estimate for per-worker CPU core usage. Used as fallback when measured job data is unavailable.
- **`cpu_estimate_source` in capacity details** — Capacity breakdown now reports whether the CPU estimate is `measured` (from job metrics) or `config` (from fallback), visible in debug output and cluster topology.

### Changed

- **CPU capacity formula** — Updated from `floor(usableCores × availablePercent / 100)` (1 worker = 1 core) to `floor(usableCores × availablePercent / 100 / cpuCoreEstimate)`, allowing significantly more workers for I/O-bound workloads.

### Compatibility

- Requires `cboxdk/laravel-queue-metrics` v2.8.0+ for measured CPU data. Older versions fall back to the config estimate automatically — no crashes, no breaking changes.

### Testing

- 459 tests, 1114 assertions

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.0.2...v3.1.0

## v3.0.2 — Cluster Fixes - 2026-04-28

### Fixed

- **ExclusiveProfile spawned one worker per manager in cluster mode** — Non-scalable queues supervised via `superviseQueue()` ignored cluster recommendations, causing each manager to spawn its own worker instead of one globally. Now accepts an optional `clusterTarget` parameter so cluster recommendations are respected.
- **Process lock collisions on shared storage volumes** — `ManagerProcessLock` used an app-only fingerprint for the lock file name, causing containers sharing a storage volume to block each other via `flock()`. In cluster mode, the lock file now includes a host fingerprint so each container gets its own lock while Redis leader election handles cross-node coordination.

### Documentation

- Added scale-to-zero guide covering wakeup latency trade-offs and SLA implications for queues with `workers.min = 0`.

### Testing

- 454 tests, 1103 assertions

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.0.1...v3.0.2

## v3.0.1 — Bugfixes - 2026-04-28

### Fixed

- **Debug command hardcoded `database` connection** — `queue:autoscale:debug` ignored `config('queue.default')` and always fell back to the `database` driver. Now reads the app's configured default connection when `--connection` is omitted.
- **Cluster spawn loop ignored auto-discovered queues** — `applyClusterRecommendation()` only iterated explicitly configured queues, while the leader's calculation used auto-discovered queues. With `queues => []`, the leader computed correct worker targets but no manager spawned them. Now iterates the recommendation's own workloads.
- **`configuredQueues()` silently accepted list-of-dicts config** — Passing a numerically-indexed array instead of the expected `['queue_name' => [...]]` map caused a cryptic type error downstream. Now throws a clear `InvalidArgumentException` on the first numeric key.

### Testing

- 443 tests, 1082 assertions

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v3.0.0...v3.0.1

## v3.0.0 — Predictive Autoscaling, Worker Topology & Cluster Orchestration - 2026-04-26

### Breaking Changes

- `PredictiveStrategy` removed — replaced by `HybridStrategy`
- `ProfilePresets` static methods removed — replaced by `ProfileContract` implementations
- `QueueConfiguration` properties restructured (`sla->targetSeconds`, `workers->min/max`)
- Config file shape rewritten — run `php artisan queue:autoscale:migrate-config`
- `TrendScalingPolicy` enum replaced by `ForecastPolicyContract` classes

### Added

#### Predictive Scaling Core

- `HybridStrategy` combining Little's Law, backlog drain, and arrival-rate forecasting
- `LinearRegressionForecaster` (OLS + R² confidence blending)
- Spawn latency compensation via `EmaSpawnLatencyTracker` (Redis-backed EMA)
- p95 pickup time SLA signal via `RedisPickupTimeStore` + `SortBasedPercentileCalculator`
- Six workload profiles: Balanced, Critical, HighVolume, Bursty, Background, Exclusive

#### Worker Topology

- **Excluded queues** — fnmatch-style globs to prevent discovery/spawning
- **ExclusiveProfile** — pinned single-threaded queues with supervisor respawn
- **Groups** — multi-queue workers with priority polling and aggregated scaling

#### Multihost Cluster Orchestration

- Redis-backed leader election with lease renewal
- Per-host heartbeat tracking (CPU, memory, workers, capacity)
- Cluster-wide scaling decisions distributed across managers
- Five cluster lifecycle events

#### Operational Tooling

- `queue:autoscale:restart` — graceful restart
- `AlertRateLimiter` — rate-limited SLA breach alerts
- `queue:autoscale:install` — interactive installer
- Cookbook recipes (Slack, Email, Log) and deployment guides (Forge, Ploi, Docker)

### Fixed

- `avgDuration` double-division in three strategies (1000x worker overprovisioning)
- Cluster mode now routes non-scalable queues through `superviseQueue()`
- `age_status` reads from correct nested key path
- `BacklogDrainCalculator` PHPDoc matches actual quadratic formula

### Testing

- 435 tests, 1070 assertions
- PHP 8.3 / 8.4 / 8.5, Laravel 11 / 12 / 13
- PHPStan clean, Pint clean

### Migration

See `docs/upgrade-guide-v3.md` for step-by-step migration from v2.

**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v2.1.0...v3.0.0

## v2.1.0 - 2026-04-05

### What's Changed

#### Added

- Laravel 13 support
- CI test matrix for Laravel 13 (PHP 8.4+, testbench 11)

#### Dependencies

- `illuminate/contracts ^13.0`
- `symfony/process ^8.0`
- `orchestra/testbench ^11.0.0`

## v2.0.2 - 2026-03-02

### Fixes

- Fix simulation tests failing on machines with high CPU usage by isolating from real system capacity
- Fix integration test flaky assertion when system capacity is constrained
- Update all dependencies (Laravel 12, Pest 4.4, PHPStan 2.1, etc.)

## v2.0.1 - 2026-03-02

### Fixes & Improvements

- **Retry noise correction**: Dampened with sqrt factor, 5% threshold, and 30% cap to prevent stale lifetime failure rates from permanently underestimating arrival rate
- **Utilization saturation boost**: +1 worker when ≥90% utilized but algorithms recommend holding
- **Multi-queue capacity sharing**: Total pool workers now correctly shared across queues via CapacityCalculator
- **Anti-flapping reset**: Direction clears after cooldown expires, preventing stale direction from blocking scaling
- **Arrival rate estimation**: Rewritten with 5-snapshot sliding window and exponential weighting for better spike detection
- **AggressiveScaleDownPolicy**: Now functional (was previously a no-op)
- **CPU measurement caching**: 4-second TTL in CapacityCalculator to avoid blocking measurements
- **Dead code removal**: Removed unused TrendPredictor class
- **PHPStan fixes**: Configuration and type error corrections

## v2.0.0 - Rebranding & Performance - 2026-01-20

### Breaking Changes ⚠️

- **Rebranding**: Package renamed to `cboxdk/laravel-queue-autoscale`.
- **Namespace Change**: Root namespace changed from `PHPeek\LaravelQueueAutoscale` to `Cbox\LaravelQueueAutoscale`.
- **Config**: Default configuration published tag is now `queue-autoscale-config`.
- **TUI Removed**: The TUI mode (`--interactive` / `--tui`) and all `php-tui` dependencies have been removed to streamline the package and fix memory leaks.

### Improvements

- **Performance**: Optimized evaluation loop with drift-correction for precise timing.
- **Robustness**: Enhanced worker spawning with fail-fast checks to prevent zombie processes.
- **Scaling Logic**: Implemented "Retry Noise Reduction" in predictive strategy to prevent runaway scaling during retry storms.
- **Responsiveness**: `ConservativeScaleDownPolicy` now allows dynamic down-scaling (25% of pool) instead of just 1 worker, solving "stuck" worker counts.
- **Defaults**: Updated `Balanced` profile cooldown to 30s (from 60s) for better responsiveness.
- **Monitoring**: Injected `LARAVEL_AUTOSCALE_WORKER=true` env var into spawned workers for easier identification.

### Documentation

- Complete update of all documentation to reflect new branding and namespaces.
- Standardized documentation structure and links.

## v1.1.0 - TUI Mode & Critical Bug Fix - 2026-01-14

### What's Changed

#### Bug Fixes

- **Critical**: Fix avgDuration unit handling - removed incorrect `* 1000` multiplication that caused 1000x error in job duration calculations, leading to incorrect scaling decisions
- Add try-catch error handling to prevent one malformed queue from crashing the entire scaling loop
- Add null-safe operator for renderer to satisfy PHPStan

#### New Features

- **TUI Mode**: Add `--interactive` / `--tui` flags for k9s-style terminal UI
  
  - Split pane layout with queue overview, workers, and logs
  - Real-time metrics updates at 60 FPS
  - Keyboard navigation and filtering
  - Tab-based navigation (Overview, Queues, Workers, Jobs, Metrics, Logs)
  
- **Debug Command**: Add `queue:autoscale:debug` for queue state inspection
  
- **Test Command**: Add `queue:autoscale:test` for dispatching test jobs
  

#### Architecture Improvements

- Add OutputRendererContract with multiple implementations (Default, Verbose, Quiet, TUI)
- Add WorkerOutputBuffer for capturing worker process output
- Add configuredQueues() method to AutoscaleConfiguration

### Upgrade Notes

This release includes a **critical bug fix** for the avgDuration calculation. If you experienced incorrect scaling behavior, upgrading to v1.1.0 should resolve the issue.

The TUI mode requires `php-tui/php-tui` which is included as a suggested dependency. Install it if you want to use the interactive mode:

```bash
composer require php-tui/php-tui --dev











```
### Usage

```bash
# Run with TUI mode
php artisan queue:autoscale --tui

# Debug queue state
php artisan queue:autoscale:debug

# Dispatch test jobs
php artisan queue:autoscale:test --jobs=10 --queue=default











```
**Full Changelog**: https://github.com/cboxdk/laravel-queue-autoscale/compare/v1.0.0...v1.1.0

## v1.0.0 - Initial Stable Release - 2026-01-05

### Queue Autoscale for Laravel v1.0.0

First stable release of Queue Autoscale for Laravel with intelligent, predictive autoscaling for Laravel queues.

#### Features

- **Predictive Scaling**: Uses Little's Law and arrival rate estimation for proactive scaling
- **SLA/SLO-based Optimization**: Configure max pickup time targets per queue
- **Multiple Scaling Strategies**: Predictive, Conservative, Simple Rate, Backlog Only
- **Predefined Profiles**: Critical, Balanced, Background, High Volume, Bursty
- **System Resource Awareness**: CPU and memory-based capacity constraints
- **Configurable Policies**: Scale-down protection, breach notifications
- **E2E Simulation Suite**: 21 tests validating autoscaler behavior across 12 workload scenarios

#### Platform Support

- PHP 8.3, 8.4, 8.5
- Laravel 11.x, 12.x

#### Installation

```bash
composer require cboxdk/laravel-queue-autoscale












```
#### Testing

- 277 unit/integration tests
- 21 simulation tests
- 68% code coverage

#### Full Changelog

See [CHANGELOG.md](https://github.com/cboxdk/laravel-queue-autoscale/blob/main/CHANGELOG.md)

## v1.0.0 - 2026-01-05

### Initial Stable Release

First stable release of Queue Autoscale for Laravel with intelligent, predictive autoscaling for Laravel queues.

#### Features

- **Predictive Scaling**: Uses Little's Law and arrival rate estimation for proactive scaling
- **SLA/SLO-based Optimization**: Configure max pickup time targets per queue
- **Multiple Scaling Strategies**: Predictive, Conservative, Simple Rate, Backlog Only
- **Predefined Profiles**: Critical, Balanced, Background, High Volume, Bursty
- **System Resource Awareness**: CPU and memory-based capacity constraints
- **Configurable Policies**: Scale-down protection, breach notifications
- **E2E Simulation Suite**: 21 tests validating autoscaler behavior across 12 workload scenarios

#### Platform Support

- PHP 8.3, 8.4, 8.5
- Laravel 11.x, 12.x

#### Dependencies

- cboxdk/laravel-queue-metrics ^1.0
- cboxdk/system-metrics ^1.2

#### Testing

- 277 unit/integration tests
- 21 simulation tests (steady state, spikes, gradual growth, bursty traffic, etc.)
- 68% code coverage

## [Unreleased]

### Added

- Initial release of Queue Autoscale for Laravel
  
- Hybrid predictive autoscaling algorithm combining:
  
  - Little's Law (L = λW) for steady-state calculations
  - Trend-based predictive scaling with moving average forecasting
  - Backlog drain calculations for SLA breach prevention
  
- SLA/SLO-based optimization (define max pickup time instead of worker counts)
  
- Resource-aware scaling respecting CPU and memory limits
  
- Integration with `laravel-queue-metrics` for queue discovery and metrics collection
  
- Graceful worker lifecycle management (spawn, monitor, terminate)
  
- Event broadcasting (ScalingDecisionMade, WorkersScaled, SlaBreachPredicted)
  
- Extension points:
  
  - ScalingStrategyContract interface for custom strategies
  - ScalingPolicy interface for before/after hooks
  
- Configuration system with per-queue overrides
  
- Comprehensive test suite (76 tests, 146 assertions, 100% passing)
  
- Complete documentation:
  
  - README.md with quick start and usage guide
  - ARCHITECTURE.md with algorithm deep dive and queueing theory
  - TROUBLESHOOTING.md with common issues and debugging tips
  - CONTRIBUTING.md with development guidelines
  - SECURITY.md with security policy and best practices
  
- Production-ready examples:
  
  - TimeBasedStrategy for time-of-day scaling patterns
  - CostOptimizedStrategy for conservative cost-focused scaling
  - SlackNotificationPolicy for real-time Slack alerts
  - MetricsLoggingPolicy for detailed metrics logging
  
- Real-world configuration patterns (8 examples for different use cases)
  
- GitHub Actions CI/CD workflows (tests, code quality)
  
- Issue and PR templates for contributions
  

### Dependencies

- PHP 8.3+
- Laravel 11.0+
- cboxdk/laravel-queue-metrics ^1.0.0
- cboxdk/system-metrics ^1.2
- Symfony Process component

### Security

- Proper signal handling (SIGTERM, SIGINT)
- Graceful shutdown with timeout protection
- Resource limit enforcement via system metrics
- No arbitrary command execution (uses explicit command arrays)
- Worker process tracking to prevent leaks

## [0.1.0] - TBD

Initial development release.
