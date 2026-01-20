---
title: "Contributing"
description: "Guidelines for contributing to Laravel Queue Autoscale development"
weight: 34
---

# Contributing

First off, thank you for considering contributing to Laravel Queue Autoscale! It's people like you that make this package better for everyone.

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code. Please report unacceptable behavior to security@cbox.dk.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When creating a bug report, include as many details as possible:

**Bug Report Template:**
```markdown
**Describe the bug**
A clear description of what the bug is.

**To Reproduce**
Steps to reproduce the behavior:
1. Configure with '...'
2. Run command '...'
3. Observe error '...'

**Expected behavior**
What you expected to happen.

**Environment:**
- PHP version: [e.g., 8.2.10]
- Laravel version: [e.g., 11.0]
- Package version: [e.g., 0.1.0]
- Queue driver: [e.g., redis, database]

**Configuration:**
```php
// Your config/queue-autoscale.php relevant sections
```

**Logs:**
```
Relevant log excerpts
```

**Additional context**
Any other information about the problem.
```

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

- **Clear title** describing the enhancement
- **Use case** explaining why this enhancement would be useful
- **Proposed solution** if you have one in mind
- **Alternatives considered**
- **Code examples** if applicable

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

- PHP 8.2 or higher
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

```bash
# Run all tests
composer test

# Run tests with coverage
composer test:coverage

# Run specific test file
./vendor/bin/pest tests/Unit/ScalingEngineTest.php

# Run tests in watch mode (if you have installed watch tools)
./vendor/bin/pest --watch
```

### Code Quality

We maintain high code quality standards:

```bash
# PHPStan (static analysis)
composer analyse

# Laravel Pint (code formatting)
composer format

# Check formatting without fixing
./vendor/bin/pint --test
```

## Coding Standards

### PHP Standards

- Follow **PSR-12** coding style
- Use **strict types** (`declare(strict_types=1);`)
- Type hint all parameters and return types
- Use **readonly properties** where appropriate
- Prefer **dependency injection** over facades in core logic

### Laravel Conventions

- Follow **Laravel** naming conventions
- Use **Eloquent** best practices
- Leverage **Service Container** for bindings
- Follow **Spatie** package conventions

### Testing Standards

- Write tests using **Pest** framework
- Aim for **high test coverage** of critical paths
- Use **descriptive test names**: `it('returns zero workers for empty queue')`
- Test **edge cases** and error conditions
- Keep tests **fast** and **independent**

### Documentation Standards

- Update **README.md** for user-facing changes
- Update **[Architecture](../algorithms/architecture.md)** for algorithm changes
- Add **[Troubleshooting](../basic-usage/troubleshooting.md)** entries for common issues
- Include **PHPDoc blocks** for classes and public methods
- Provide **code examples** in documentation

## Project Structure

```
src/
├── Commands/          # Artisan commands
├── Configuration/     # Configuration classes
├── Contracts/         # Interfaces
├── Events/           # Event classes
├── Manager/          # Core autoscale manager
├── Policies/         # Policy executor
├── Scaling/          # Scaling engine and strategies
│   ├── Calculators/  # Algorithm components
│   └── Strategies/   # Scaling strategies
└── Workers/          # Worker management

tests/
├── Unit/             # Unit tests
└── Feature/          # Feature tests (if needed)

examples/             # Example implementations
├── Strategies/       # Custom strategy examples
└── Policies/         # Custom policy examples
```

## Commit Message Guidelines

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```
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

```bash
feat(scaling): add exponential backoff strategy

Add new ExponentialBackoffStrategy that scales more conservatively
by using exponential backoff when scaling up workers.

Closes #123

fix(workers): prevent worker spawn race condition

Workers could spawn multiple times if evaluation cycles overlapped.
Added mutex lock to prevent concurrent spawns.

Fixes #456

docs(readme): add troubleshooting section for Horizon conflicts

test(engine): add tests for capacity constraint edge cases
```

## Release Process

Maintainers follow this process for releases:

1. Update `CHANGELOG.md` with release notes
2. Update version in `composer.json`
3. Create git tag: `git tag v1.0.0`
4. Push tag: `git push origin v1.0.0`
5. GitHub Actions automatically publishes to Packagist

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
class MyStrategy implements ScalingStrategyContract
{
    private string $lastReason = 'No calculation performed yet';
    private ?float $lastPrediction = null;

    public function calculateTargetWorkers(object $metrics, QueueConfiguration $config): int
    {
        // Your logic here
        $this->lastReason = 'Clear explanation of decision';
        $this->lastPrediction = $backlog > 0 ? $backlog / $workers : 0.0;

        return max(0, (int) ceil($targetWorkers));
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

### Scaling Policy Development

When creating new scaling policies:

1. **Implement ScalingPolicy interface**
2. **Be idempotent**: Handle multiple calls safely
3. **Fail silently**: Don't disrupt autoscaling on errors
4. **Log errors**: Track failures for debugging
5. **Keep it fast**: Avoid blocking operations
6. **Clean up resources**: Prevent memory leaks

Example:
```php
class MyPolicy implements ScalingPolicy
{
    public function before(ScalingDecision $decision): void
    {
        try {
            // Preparation logic
        } catch (\Exception $e) {
            logger()->warning('Policy before failed', ['error' => $e->getMessage()]);
        }
    }

    public function after(ScalingDecision $decision): void
    {
        try {
            // Cleanup/notification logic
        } catch (\Exception $e) {
            logger()->warning('Policy after failed', ['error' => $e->getMessage()]);
        }
    }
}
```

## Questions?

Feel free to:
- Open an issue for discussion
- Ask in pull request comments
- Email: info@cbox.dk

## Recognition

Contributors will be recognized in:
- README.md credits section
- GitHub contributors page
- Release notes for significant contributions

Thank you for contributing! 🎉
