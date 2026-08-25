# Queue Autoscale for Laravel - Development Guidelines

## Foundational Context

This is a Laravel **package** — SLA-driven autoscaling for queue workers. It runs a
long-lived manager process that spawns and terminates `queue:work` children to hold a
pickup-time SLA, on a single host or across a cluster of managers coordinating through
Redis. It is a framework library, not an application: no UI, no models, no migrations.

You are an expert with all the packages and versions listed below. These are the real
constraints from `composer.json` — if you change a constraint, update this list in the
same commit.

### Runtime requirements

- php — `^8.4|^8.5`
- ext-pcntl, ext-posix (the manager forks and signals worker processes)
- ext-mbstring (character-boundary truncation of worker output)
- cboxdk/laravel-queue-metrics — `^3.3`
- illuminate/contracts — `^12.0||^13.0`
- symfony/process — `^7.0||^8.0`

### Development requirements

- orchestra/testbench — `^10.0.0||^11.0.0`
- pestphp/pest — `^4.4.1` (plus `pest-plugin-arch`, `pest-plugin-laravel`)
- larastan/larastan — `^3.0`
- laravel/pint — `^1.14`
- cboxdk/laravel-telemetry — `^1.2.0` (dev + `suggest` only, never a hard dependency)
- aws/aws-sdk-php — `^3.390` (dev only, for the SQS integration specs)

### Laravel version support

The package must install on the **current and previous** Laravel major, so
`illuminate/contracts` carries both and CI runs a matrix over both majors × PHP 8.4/8.5
× `prefer-lowest`/`prefer-stable`. Before touching any constraint, check the real
current major on Packagist — never assume, and never copy a sibling package's pin.
Companion tooling tracks its own parent, not Laravel: PHPUnit follows Pest, so read the
intended version's actual `require` before bumping it.

**Pest stays on 4.x, deliberately.** Pest 5 requires `phpunit ^13.3`, and
`orchestra/testbench-core` on the Laravel 12 line (`v10.x`) declares
`conflict: phpunit >=13.2.0`. Supporting the previous Laravel major and running Pest 5
are therefore mutually exclusive, and support wins. Re-check this when Laravel 14 ships
and 12 drops out of the matrix — until then a Pest 5 bump will resolve only by dropping
the L12 axis, which is not a trade this package makes.

## Deferred to the next major

Three things are known-wrong-but-deliberately-unchanged, because fixing any of them
breaks a consumer. They are listed here rather than as scattered comments, so the set
is auditable and nobody re-opens one in isolation.

1. **Namespace.** The standard says `Cbox\<Package>\`; this package ships
   `Cbox\LaravelQueueAutoscale\`. Renaming breaks every `use` statement in every
   consumer, so it waits for a major with an upgrade guide.
2. **`CapacityCalculationResult::$details` is an array.** Its exact keys are documented
   in `docs/api-reference/scaling-decision.md` and `docs/algorithms/resource-constraints.md`,
   so consumers index into it. `cpuBreakdown()` and `memoryBreakdown()` were added
   alongside it as typed accessors and the package uses those internally; the array
   stays until a major can drop it.
3. **Pest is pinned to 4.x.** Not a choice we can make freely — see the Laravel version
   support section above. It unblocks when Laravel 12 leaves the CI matrix, which is
   itself a major-version event.

When a major is cut, work this list. Until then, adding a fourth entry should feel like
a cost, not a free deferral.

## Conventions

- Follow the existing code conventions in this package. When creating or editing a file,
  check sibling files for structure, approach, and naming.
- Use descriptive names for variables and methods — `isRegisteredForDiscounts`, not
  `discount()`.
- Check for an existing component to reuse before writing a new one.

## Package Structure & Architecture

- Source in `src/`, tests in `tests/`, config in `config/`, docs in `docs/`.
- Stick to the existing directory structure — don't create new base folders without
  approval. The modules are: `Alerting`, `Cluster`, `Commands`, `Configuration`,
  `Contracts`, `Diagnostics`, `Events`, `Facades`, `Fuse`, `Manager`, `Output`,
  `Pickup`, `Policies`, `Scaling`, `Support`, `Telemetry`, `Testing`, `Workers`.
- Do not change dependencies without approval.
- The service provider is a plain `Illuminate\Support\ServiceProvider`. This package does
  **not** use `spatie/laravel-package-tools`, and new third-party runtime dependencies
  need a confirmed reason — default to plain Illuminate and native PHP.

### Contracts-first DI

Every capability is an interface under `Contracts\` bound in the service provider and
resolved from the container. Depend on the interface, never the concrete class — that is
what makes the shipped fakes and host overrides possible. There are twelve contracts;
`ScalingStrategyContract`, `ClusterStoreContract`, `ScalingPolicy` and
`SpawnLatencyTrackerContract` are the ones consumers most often implement.

### Classes stay open

Consumers must be able to extend, decorate, fake or mock what the package ships, so
package classes are **not** `final`. `tests/ArchTest.php` asserts this, with an ignore
list containing only enums (implicitly final in PHP). Configuration value objects are
`readonly` — immutability is enforced, sealing is not. Do not add `final` to a class
without changing that arch test deliberately.

### Typed domain models, not arrays

Model domain data as typed value objects and enums. Arrays are acceptable only at true
serialization boundaries — Redis payloads in `Cluster\ClusterStore`, config arrays in
`Commands\MigrateConfigCommand`, the metrics arrays `laravel-queue-metrics` returns.
Parse into typed objects on the way in and serialize back only at the edge. Do not add
new `array<string, mixed>` bags to the domain; several already exist and are debt, not
precedent.

### Telemetry stays optional

`cboxdk/laravel-telemetry` is dev-only and listed under `suggest`. Every telemetry code
path is guarded by `class_exists(TelemetryManager::class)`. A library must not force an
observability runtime on its host — keep it that way.

## Configuration

- Configuration is publishable via the service provider.
- Use `env()` **only** in `config/queue-autoscale.php`, never in `src/`.
- Read configuration as `config('queue-autoscale.key')`.
- One exception, and it is deliberate: container-identity lookups in `src/` call
  `getenv()` directly, because they must read the live process rather than a cached
  config value. `phpstan.neon.dist` disables larastan's `noEnvCallsOutsideOfConfig` for
  this reason alone.

## Verification & Testing

Tests are critical for package development. Always write tests for new features, covering
happy paths, failure paths, and edge cases. Do not create verification scripts when tests
cover the functionality. Do not remove tests or test files without approval.

### Dogfood the testing surface

`src/Testing/` ships `InteractsWithAutoscaling`, `FakeClusterStore`,
`InMemoryFailureWindowStore` and `QueueMetricsFactory` for consumers. The package's own
tests use them — if a fake is awkward to use in our own suite, fix the fake rather than
working around it. Fixtures that PHPStan must see live in `tests/Fixtures` and are
included in the analysis paths.

This package ships **no Eloquent models, no migrations and no model factories**. Tests
build state through the fakes and `Testing\QueueMetricsFactory` — which builds metric
payloads, not Eloquent records — so none of the usual model/factory/migration guidance
applies here.

### Running tests

- Full suite: `vendor/bin/pest` or `composer test`
- The `simulation` group is slow and excluded from normal runs:
  `vendor/bin/pest --exclude-group=simulation`
- A single file: `vendor/bin/pest tests/Feature/ExampleTest.php`
- By name: `vendor/bin/pest --filter=testName`
- Coverage: `composer test-coverage`
- Redis and SQS specs are env-gated and skip unless `REDIS_AVAILABLE` /
  `REDIS_CLUSTER_HOSTS_AND_PORTS` / the AWS credentials are set. A local run showing
  ~15 skipped tests is normal.

Run the minimal set of tests with filters while iterating; run the full suite before
finalizing. When tests relating to your feature pass, ask whether to run everything.

### Pest syntax

- `test('it can do something', function () { ... })` or `it('does something', ...)`
- `expect($value)->toBe(10)`
- `beforeEach()` / `afterEach()` for setup and teardown
- Use the Pest Laravel plugin's helpers where they fit

## The verification gate

A change is only finished when all of these are green. Never call it done on a partial
run — `composer qa` runs the first five in order.

```bash
vendor/bin/pint --test                 # composer lint
vendor/bin/phpstan analyse --memory-limit=1G   # composer analyse — level max, larastan
vendor/bin/pest                        # composer test
php bin/check-licenses.php             # composer license-check
composer audit --no-dev
php bin/generate-sbom.php              # composer sbom — commit the result when deps change
```

PHPStan needs the raised memory limit — `composer analyse` passes it, but the bare
`vendor/bin/phpstan analyse` crashes on PHP's default 128M partway through the parallel
workers, which reads as a broken analysis rather than a missing flag.

PHPStan runs at **level max** with no baseline and no `@phpstan-ignore`. Fix the
underlying cause rather than silencing it: no `assert()` or inline `@var` to override
inference, no casts to quiet a union, no widening a type just to pass.

Run `vendor/bin/pint --dirty` while iterating and `vendor/bin/pint` to fix formatting.
Do not run `vendor/bin/pint --test` as a fix step; it only reports.

`sbom.json` is committed and regenerated from `composer.lock`. CI validates that the
generator produces a well-formed CycloneDX document but deliberately does **not** assert
byte-for-byte drift, because the package does not commit a lock file and CI resolves
versions this machine never saw. That means a stale `sbom.json` will not fail the build —
regenerate and commit it yourself whenever a dependency changes.

## PHP

- Always use curly braces for control structures, even for a single line.

### Constructors

- Use PHP 8 constructor property promotion.
  - `public function __construct(public GitHub $github) { }`
- No empty `__construct()` with zero parameters.

### Type declarations

- Explicit return types on every method and function.
- Type hints on every parameter.

```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    // ...
}
```

## Comments

Prefer PHPDoc blocks over inline comments. Write a comment only when something genuinely
non-obvious is happening — and then explain *why*, not *what*. The cluster coordination
and distribution code in `Manager\AutoscaleManager` is the main place where that bar is
met: several guards there look removable until you know which failure mode they prevent.

## PHPDoc blocks

Add array shape definitions where an array survives at a boundary. Prefer replacing the
array with a value object over documenting its shape.

## Enums

Enum cases are TitleCase — `FavoritePerson`, `BestLake`, `Monthly`.

## Commit discipline

- Conventional-commit subjects (`feat(cluster): …`, `fix(manager): …`), with a body
  explaining the reasoning when the change is not self-evident.
- Verified, then committed. One coherent change per commit.
- Branch off `main`. Never commit or push unless asked.

## Documentation files

Only create documentation files when explicitly requested. When you do write docs, follow
the guide below — and note that the docs site scrapes **tagged releases**, so a docs
change pushed to `main` does not appear on cbox.dk until it is tagged.

# Cbox Documentation Guide

This guide explains how to structure documentation for Cbox packages to ensure optimal display and navigation on cbox.dk.

## Core Concepts

### Major Version Management
- Cbox displays ONE entry per major version (v1, v2, v3)
- System automatically tracks the latest release within each major version
- URLs use major version: `/docs/{package}/v1`, `/docs/{package}/v2`
- When you release v1.2.1 after v1.2.0, the website updates automatically

### Files NOT Used on Cbox.com

**README.md - GitHub Only**
- ⚠️ README.md is **NEVER** displayed on cbox.dk
- README.md is only for GitHub repository display
- All documentation must be in the `/docs` folder
- Do NOT reference README.md in your docs

**Files Used on cbox.dk**
- All `.md` files in the `/docs` folder
- All image/asset files within `/docs`
- `_index.md` files for directory landing pages (**required** — see the grading rule below)

## Directory Structure

### Grading rule

The docs site grades a package **`complete`** only when every folder has an `_index.md`
*and* every `.md` file carries `title` + `weight` + `description` frontmatter. A missing
`_index.md` or missing frontmatter downgrades it to `partial`. Root-level files are
exempt from the `_index.md` rule. Verify with the site importer:
`buildNavigationTree($docsPath)['metadata_quality']` must be `complete`.

### This package's structure

`laravel-queue-autoscale` currently grades `complete`. Keep it that way — every new
folder needs an `_index.md`, and every new file needs full frontmatter.

```
docs/
├── index.md                    # Overview, mental model, section TOC
├── quickstart.md               # Zero-to-working in one read
├── requirements.md             # PHP / Laravel / extension versions, from composer.json
├── basic-usage/                # Installation, configuration, day-to-day operation
│   ├── _index.md
│   └── …
├── advanced-usage/             # Custom strategies, policies, upgrades, security
│   ├── _index.md
│   └── …
├── algorithms/                 # How the scaling maths actually works
│   ├── _index.md
│   └── …
├── cookbook/                   # Task-oriented recipes
│   ├── _index.md
│   └── …
├── deployment/                 # Platform guides (Docker, Forge, Ploi, VPS)
│   ├── _index.md
│   └── …
└── api-reference/
    └── _index.md
```

### Directory Naming Rules
- ✅ Use lowercase with hyphens: `basic-usage/`, `advanced-features/`
- ✅ Keep names short: `api-reference/`, `platform-support/`
- ✅ Max 2-3 levels of nesting
- ❌ Don't use spaces or special characters
- ❌ Don't create deeply nested structures (>3 levels)

## Metadata (Frontmatter)

### Required Fields
Every `.md` file **MUST** have frontmatter with `title` and `description`:

```yaml
---
title: "Page Title"           # REQUIRED
description: "Brief summary"  # REQUIRED
weight: 99                    # OPTIONAL (default: 99)
hidden: false                 # OPTIONAL (default: false)
---
```

### How Metadata Is Used

**Title**
- Navigation sidebar link text
- Page header `<h1>` tag
- Browser tab title
- SEO meta tags
- Social media sharing

**Description**
- SEO meta description
- Search engine result snippets
- Social media preview text
- May influence click-through rate

**Weight**
- Controls navigation order (lower = first)
- Default is 99
- Same weight = alphabetical by title
- Only affects current directory

**Hidden**
- Set to `true` to hide from navigation
- Page still accessible via direct URL
- Useful for drafts or deprecated content

### Metadata Best Practices

**Title Guidelines**
```yaml
# ✅ Good titles
title: "CPU Metrics"
title: "Error Handling"
title: "API Reference"

# ❌ Avoid
title: "Page 1"                    # Generic
title: "System Metrics CPU Stuff"  # Too long, redundant
title: "failure-fuse"              # Not Title Case
```

**Description Guidelines**
```yaml
# ✅ Good descriptions (60-160 chars, action-oriented)
description: "Get raw CPU time counters and per-core metrics from the system"
description: "Master the Result<T> pattern for explicit error handling"
description: "Monitor resource usage for individual processes or process groups"

# ❌ Avoid
description: "This page describes CPU metrics"  # Too generic
description: "CPU stuff"                        # Too vague
description: "A very long description that goes on and on..."  # Too long (>160 chars)
```

**Weight Organization**
```yaml
# Recommended weight ranges:
1-10:   Critical pages (introduction, installation, quickstart)
11-30:  Common features (basic usage)
31-70:  Advanced features
71-99:  Reference material (API docs, appendices)

# Example:
# docs/introduction.md
weight: 1

# docs/installation.md
weight: 2

# docs/quickstart.md
weight: 3

# docs/basic-usage/failure-fuse.md
weight: 10
```

## Links and URLs

### Internal Documentation Links

Use **relative paths** with full filename to link between documentation pages:

```markdown
# Link to sibling file in same directory
[Installation Guide](installation.md)

# Link to file in parent directory
[Back to Introduction](../introduction.md)

# Link to file in subdirectory
[Failure Fuse](basic-usage/failure-fuse.md)

# Link to file in different subdirectory
[Platform Comparison](../platform-support/comparison.md)

# Link with anchor to heading
[Error Handling](advanced-usage/error-handling#result-pattern.md)
```

**Link Best Practices**
- ✅ Use descriptive link text: `[View API Reference](api-reference)`
- ✅ Keep `.md` extension: `[Guide](installation.md)` not `[Guide](installation)`
- ✅ Use relative paths: `[Guide](../guide)`
- ❌ Don't use generic text: `[Click here](guide.md)` or `[Read more](docs.md)`
- ❌ Don't hardcode absolute URLs: `[Guide](/docs/package/v1/guide.md)`
- ❌ Don't link to README.md (it's not displayed)

### External Links

```markdown
# Always use full URLs with https://
[GitHub Repository](https://github.com/owner/repo)
[Official Website](https://example.com)

# ✅ Good
[Documentation](https://example.com/docs)

# ❌ Avoid
[Documentation](example.com/docs)  # Missing https://
```

## Images and Assets

### Image References

Use **relative paths** for images:

```markdown
# Image in same directory
![Performance Chart](performance.png)

# Image in subdirectory
![Diagram](images/architecture.png)

# Image in parent images folder
![Logo](../images/logo.svg)

# Image with alt text and tooltip
![Chart](chart.png "CPU Performance Over Time")
```

**Image Best Practices**
- ✅ Always include alt text: `![Diagram](image.png)` not `![](image.png)`
- ✅ Use relative paths
- ✅ Organize in `/docs/images/` or feature-specific folders
- ✅ Supported formats: `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.webp`
- ❌ Don't use absolute URLs
- ❌ Don't reference images outside `/docs` folder

### Asset Organization

```
docs/
├── images/              # Shared images
│   ├── logo.png
│   └── architecture.svg
├── basic-usage/
│   ├── cpu-chart.png   # Feature-specific image
│   └── failure-fuse.md
└── screenshots/         # UI screenshots
    └── dashboard.png
```

## Code Blocks

### Syntax Highlighting

Always specify the language after the opening fence:

````markdown
```php
use Cbox\LaravelQueueAutoscale\Facades\LaravelQueueAutoscale;

$cluster = LaravelQueueAutoscale::cluster();
echo "Managers: {$cluster['manager_count']}\n";
```
````

**Supported Languages**
- PHP, JavaScript, Bash, JSON, YAML, XML, HTML, Markdown, SQL, Dockerfile

**Code Block Best Practices**
````markdown
# ✅ Good - Language specified
```php
$cluster = LaravelQueueAutoscale::cluster();
```

# ❌ Avoid - No language
```
$cluster = LaravelQueueAutoscale::cluster();
```
````

## Index Files (_index.md)

### Purpose
- Creates landing pages for directory sections
- Provides section overview
- Optional but recommended for better UX

### When to Use

**✅ Create _index.md for:**
- Major sections with 3+ child pages
- Directories needing explanation
- Sections requiring custom intro text

**❌ Skip _index.md for:**
- Simple directories with 1-2 pages
- Self-explanatory sections

### Example _index.md

```markdown
---
title: "Basic Usage"
description: "Essential features for getting started with the package"
weight: 1
---

# Basic Usage

This section covers the fundamental features you'll use daily:

- CPU and memory monitoring
- Disk usage tracking
- Network statistics
- System uptime

Start with the "System Overview" guide for a quick introduction.
```

## Complete Example

**File**: `docs/basic-usage/failure-fuse.md`

```markdown
---
title: "Failure Fuse"
description: "Stop scaling a queue up when its jobs are failing, and probe for recovery"
weight: 14
---

# Failure Fuse

The fuse watches a rolling window of job outcomes per queue. When the failure rate
crosses the configured threshold it trips, and the manager stops adding workers to
that queue — scaling up a queue whose jobs all fail just burns capacity faster.

## Configuring the Fuse

```php
'queues' => [
    'redis:emails' => [
        'fuse' => [
            'enabled' => true,
            'failure_rate_threshold' => 0.5,
            'window_seconds' => 60,
        ],
    ],
],
```

## Reacting to a Trip

```php
use Cbox\LaravelQueueAutoscale\Events\FuseTripped;

Event::listen(function (FuseTripped $event) {
    Log::critical("Fuse tripped for {$event->workload}", [
        'failure_rate' => $event->failureRate,
    ]);
});
```

## Recovery

A tripped fuse periodically allows a single probe worker through. If its jobs succeed
the fuse closes and normal scaling resumes; if they fail the window restarts.

See [Event Handling](event-handling.md) for the full event list.
```

## Quality Checklist

Before publishing, verify:

### Metadata
- [ ] Every `.md` file has `title` and `description`
- [ ] Titles are unique and descriptive (Title Case)
- [ ] Descriptions are 60-160 characters
- [ ] Weight values create logical ordering
- [ ] No generic titles like "Page 1", "Document"

### Structure
- [ ] Major sections have `_index.md` files
- [ ] Directory nesting is shallow (max 2-3 levels)
- [ ] File names use lowercase-with-hyphens
- [ ] Directory names are short and descriptive

### Content
- [ ] Code blocks specify language
- [ ] Images have alt text
- [ ] Links use relative paths
- [ ] No references to README.md
- [ ] All internal links tested

### Files
- [ ] All documentation in `/docs` folder
- [ ] No absolute URLs for internal content
- [ ] Images stored within `/docs` directory
- [ ] No spaces or special characters in filenames

## Troubleshooting

### Navigation Not Showing
- Check frontmatter exists and is valid YAML
- Verify `title` and `description` are present
- Ensure file has `.md` extension
- Confirm `hidden: false` (or field omitted)
- Verify file is in `/docs` folder (not root)

### Images Not Loading
- Use relative paths: `![](../images/file.png)`
- Verify image exists in repository
- Check file extension is supported
- Ensure image is within `/docs` directory

### Wrong Page Order
- Add `weight` to frontmatter
- Lower numbers appear first (1, 2, 3...)
- Default weight is 99
- Same weight = alphabetical by title

### Code Not Highlighting
- Specify language: \`\`\`php not just \`\`\`
- Supported: php, js, bash, json, yaml, xml, html, md, sql, dockerfile
- Check spelling of language name
- Ensure code block is properly closed

## URL Structure

Your documentation will be available at:

```
https://cbox.dk/docs/{package}/{major_version}/{page_path}

Examples:
/docs/laravel-queue-autoscale/v4/index
/docs/laravel-queue-autoscale/v4/basic-usage/failure-fuse
/docs/laravel-queue-autoscale/v4/advanced-usage/custom-strategies
```

**How URLs Are Generated**
```
File: docs/basic-usage/failure-fuse.md
URL:  /docs/laravel-queue-autoscale/v4/basic-usage/failure-fuse

File: docs/index.md
URL:  /docs/laravel-queue-autoscale/v4/index
```

## SEO Tips

**Title Impact**
- Shown in Google search results
- Used in social media shares
- Displayed in browser tabs
- Should be unique and descriptive

**Description Impact**
- Shown as snippet in search results
- Used in social media previews
- Should be 120 characters ideal
- Should explain page value to users

**Best Practices**
- ✅ Unique title per page
- ✅ Descriptive URLs (via good filenames)
- ✅ 60-160 character descriptions
- ✅ Include relevant keywords naturally
- ❌ Don't stuff keywords
- ❌ Don't use duplicate titles
- ❌ Don't create duplicate content
