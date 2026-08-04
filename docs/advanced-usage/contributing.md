---
title: "Contributing"
description: "Development setup, coding standards and the quality gate for contributing to Queue Autoscale for Laravel"
weight: 34
---

# Contributing

First off, thank you for considering contributing to Queue Autoscale for Laravel! It's people like you that make this package better for everyone.

Security issues are the one exception to "open an issue" — report those privately, as described in [Security](security.md).

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When creating a bug report, include as many details as possible:

The repository has a **Bug Report** issue form that prompts for most of this. Whatever the form
asks, a good autoscaler bug report also includes:

- **What happened** and what you expected instead
- **Reproduction steps** — the config, the command you ran, and what you observed
- **Environment** — PHP version, Laravel version, package version, queue driver, metrics storage
  (`redis` or `database`), and whether cluster mode is enabled
- **The relevant part of `config/queue-autoscale.php`** — especially `sla_defaults`, the `queues`
  entry involved, `strategy`, `policies` and `limits`
- **Log excerpts** from the channel named by `queue-autoscale.manager.log_channel`
- **`php artisan queue:autoscale:debug --queue=<queue>` output**, which shows what the autoscaler
  actually sees for that queue
- **`php artisan queue:autoscale -vvv` output** for a few cycles when the problem is a scaling
  decision — the `-vvv` capacity breakdown explains which constraint bound the target

### Suggesting Enhancements

Feature ideas go to GitHub Discussions (the *Ideas* category) — the issue form links there. Include:

- A **clear title** describing the enhancement
- The **use case** — what workload shape makes this necessary
- A **proposed solution** if you have one
- **Alternatives considered**, including whether a custom strategy or policy already solves it

### Pull Requests

1. **Fork the repository** and create your branch from `main`
2. **Install dependencies**: `composer install`
3. **Make your changes** following our coding standards
4. **Add tests** for any new functionality
5. **Ensure tests pass**: `composer test`
6. **Run code quality checks**:
   ```bash
   ./vendor/bin/phpstan analyse
   ./vendor/bin/pint
   ```
7. **Update documentation** if needed
8. **Commit with clear messages** following [Conventional Commits](https://www.conventionalcommits.org/)
9. **Submit a pull request**

## Development Setup

### Prerequisites

- PHP 8.3, 8.4 or 8.5 with the `pcntl` and `posix` extensions
- Composer
- Git

### Installation

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/laravel-queue-autoscale.git
cd laravel-queue-autoscale

# Install dependencies
composer install

# Run tests
composer test

# Run static analysis
composer analyse

# Fix code style
composer format
```

### Running Tests

The composer scripts are `test`, `test-coverage`, `analyse` and `format`:

```bash
# Run all tests
composer test

# Run tests with coverage
composer test-coverage

# Run a specific file or filter
./vendor/bin/pest tests/Unit/ScalingEngineTest.php
./vendor/bin/pest --filter="fuse"
```

The suite uses Pest 4 on Orchestra Testbench. `tests/Pest.php` provides helpers for building a
`QueueConfiguration` without hand-assembling every value object.

### Code Quality

Before opening a pull request:

```bash
# Static analysis
composer analyse

# Formatting (fixes in place)
composer format
```

`vendor/bin/pint --dirty` formats only your changed files, which is usually what you want mid-branch.

## Coding Standards

### PHP Standards

- Follow **PSR-12** coding style
- Use **strict types** (`declare(strict_types=1);`)
- Type hint all parameters and return types
- Use **readonly properties** where appropriate
- Prefer **dependency injection** over facades in core logic

### Laravel Conventions

- Follow **Laravel** naming conventions
- Leverage the **service container** for bindings; the service provider registers every collaborator
  as a singleton so behaviour can be swapped by binding a contract
- Read config through `AutoscaleConfiguration`, never `env()` outside `config/`
- The package ships no Eloquent models — state lives in the cache/Redis stores and in memory on the
  manager

### Testing Standards

- Write tests using **Pest** framework
- Aim for **high test coverage** of critical paths
- Use **descriptive test names**: `it('returns zero workers for empty queue')`
- Test **edge cases** and error conditions
- Keep tests **fast** and **independent**

### Documentation Standards

- Every page under `/docs` needs YAML frontmatter with `title`, `description` (60–160 characters)
  and `weight`; titles must be unique across the site
- Relative links between pages keep the `.md` extension
- Every code fence declares a language
- Update **[Architecture](../algorithms/architecture.md)** for algorithm changes
- Add **[Troubleshooting](../basic-usage/troubleshooting.md)** entries for common issues
- Include **PHPDoc blocks** for classes and public methods
- Every documented API must match the source. If a doc claims a class, method, flag, config key or
  environment variable, open the file and check it before writing the sentence

## Project Structure

```text
src/
├── Alerting/         # AlertRateLimiter
├── Cluster/          # Leader election, heartbeats, cluster store
├── Commands/         # Artisan commands
├── Configuration/    # Config value objects and profiles
├── Contracts/        # Interfaces
├── Events/           # Event classes
├── Facades/          # LaravelQueueAutoscale facade
├── Fuse/             # Failure fuse and failure-window stores
├── Manager/          # AutoscaleManager evaluation loop, signal handling
├── Output/           # Console renderers
├── Pickup/           # Pickup-time stores and percentile calculators
├── Policies/         # Shipped policies and PolicyExecutor
├── Scaling/          # Scaling engine, decision, strategies
│   ├── Calculators/  # Little's Law, backlog drain, capacity, forecaster
│   ├── DTOs/         # Capacity and resource-estimate DTOs
│   ├── Forecasting/  # Forecast policies
│   └── Strategies/   # Hybrid, BacklogOnly, Conservative, SimpleRate
├── Support/          # Process lock, restart signal
├── Telemetry/        # Optional laravel-telemetry integration
└── Workers/          # Spawner, terminator, pool, spawn-latency tracking

tests/
├── Feature/
├── Helpers/
├── Simulation/       # End-to-end scaling simulations
└── Unit/

examples/             # Reference implementations
├── Strategies/
└── Policies/
```

## Commit Message Guidelines

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```text
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `style`: Code style changes (formatting)
- `refactor`: Code refactoring
- `perf`: Performance improvements
- `test`: Adding or updating tests
- `chore`: Build process or auxiliary tool changes

### Examples

```text
feat(scaling): add exponential backoff strategy

Add ExponentialBackoffStrategy that ramps up more conservatively
than HybridStrategy when the backlog is growing.

Closes #123

fix(workers): prevent worker spawn race condition

Workers could spawn multiple times if evaluation cycles overlapped.
Added a lock to prevent concurrent spawns.

Fixes #456

docs(policies): document the 25% ConservativeScaleDownPolicy limit

test(engine): add tests for capacity constraint edge cases
```

## Release Process

Maintainers follow this process for releases:

1. Update `CHANGELOG.md` with release notes
2. Create the git tag on the v3 line: `git tag v3.x.y`
3. Push the tag: `git push origin v3.x.y`
4. Publish the GitHub release; Packagist picks it up from the repository

The package version is not stored in `composer.json` — it is derived from the git tag. CI covers
tests, PHPStan and Pint style fixes; there is no publishing workflow in `.github/workflows/`.

## Architecture Guidelines

### Scaling Strategy Development

When creating new scaling strategies:

1. **Implement ScalingStrategyContract**
2. **Handle edge cases**: zero rate, missing metrics, null values
3. **Provide clear reasoning**: Set `lastReason` explaining decisions
4. **Set predictions**: Calculate `lastPrediction` when backlog exists
5. **Test thoroughly**: Include unit tests with various scenarios
6. **Document use cases**: When to use this strategy

Example:
```php
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingStrategyContract;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;

class MyStrategy implements ScalingStrategyContract
{
    private string $lastReason = 'No calculation performed yet';

    private ?float $lastPrediction = null;

    public function calculateTargetWorkers(QueueMetricsData $metrics, QueueConfiguration $config): int
    {
        $targetWorkers = $this->calculate($metrics, $config);

        $this->lastReason = 'Clear explanation of the decision';
        $this->lastPrediction = $metrics->pending > 0 && $targetWorkers > 0
            ? ($metrics->pending / $targetWorkers) * $metrics->avgDuration
            : 0.0;

        return max($config->workers->min, min($config->workers->max, $targetWorkers));
    }

    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    public function getLastPrediction(): ?float
    {
        return $this->lastPrediction;
    }
}
```

`$metrics->avgDuration` is in **seconds** by the time a strategy sees it — `AutoscaleManager`
converts from the metrics package's milliseconds. See
[Custom Strategies](custom-strategies.md) for the full DTO shape.

### Scaling Policy Development

When creating new scaling policies:

1. **Implement the `ScalingPolicy` interface** — the hooks are `beforeScaling(ScalingDecision): ?ScalingDecision` and `afterScaling(ScalingDecision): void`
2. **Return `null` when you have no opinion** — returning a decision replaces it for every later policy
3. **Copy every field you are not deliberately changing** when rebuilding a decision, including `capacity` and `spawnCompensation`
4. **Be idempotent**: the hooks run on every evaluation cycle
5. **Keep it fast**: they run inline in the manager's tick
6. **Let `PolicyExecutor` handle failures**: it already catches and logs `Throwable` from both hooks

Example:
```php
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;

class MyPolicy implements ScalingPolicy
{
    public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
    {
        if (! $decision->shouldScaleDown()) {
            return null;
        }

        return new ScalingDecision(
            connection: $decision->connection,
            queue: $decision->queue,
            currentWorkers: $decision->currentWorkers,
            targetWorkers: max($decision->targetWorkers, 1),
            reason: 'MyPolicy kept one worker (original: '.$decision->reason.')',
            predictedPickupTime: $decision->predictedPickupTime,
            slaTarget: $decision->slaTarget,
            capacity: $decision->capacity,
            spawnCompensation: $decision->spawnCompensation,
        );
    }

    public function afterScaling(ScalingDecision $decision): void
    {
        // Side effects only — the decision is final at this point.
    }
}
```

See [Policy Execution Internals](scaling-policies.md) for chaining, error isolation and the shipped
policy implementations.

## Questions?

- Open a GitHub issue for discussion
- Ask in pull request comments
- Report security problems privately — see [Security](security.md)

## Recognition

Contributors are recognised on the GitHub contributors page and in the release notes for significant
contributions.

Thank you for contributing!
