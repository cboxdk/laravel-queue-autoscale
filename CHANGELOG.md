# Changelog

All notable changes to `laravel-queue-autoscale` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- Config file shape rewritten — run `php artisan queue-autoscale:migrate-config`
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
- `queue-autoscale:install` — interactive installer
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
