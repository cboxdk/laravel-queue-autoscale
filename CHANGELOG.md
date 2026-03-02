# Changelog

All notable changes to `laravel-queue-autoscale` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

### Laravel Queue Autoscale v1.0.0

First stable release of Laravel Queue Autoscale with intelligent, predictive autoscaling for Laravel queues.

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

First stable release of Laravel Queue Autoscale with intelligent, predictive autoscaling for Laravel queues.

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

- Initial release of Laravel Queue Autoscale
  
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
