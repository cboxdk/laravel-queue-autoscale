---
title: "Security"
description: "How to report a vulnerability in Queue Autoscale, plus the security-relevant behaviour of worker spawning and configuration"
weight: 33
---

# Security

## Reporting a vulnerability

**Please do not open a public GitHub issue for a security problem.**

Report it through **GitHub Private Vulnerability Reporting** on the repository:

[Report a vulnerability](https://github.com/cboxdk/laravel-queue-autoscale/security/advisories/new)

(Repository → **Security** tab → **Report a vulnerability**.)

The report stays private between you and the maintainers until a fix is published. If Private
Vulnerability Reporting is not enabled on the repository at the time you look, open a normal issue
that says only that you have a security report and asks for a private channel — do not include the
details in it.

This is a small open-source package maintained on a best-effort basis. There is no staffed security
desk, no guaranteed response window and no bug-bounty programme. Reports are triaged as maintainer
time allows, and confirmed issues are fixed and released as promptly as they can be.

### What to include

- The type of issue (for example: command injection, privilege escalation, resource exhaustion)
- The affected version or commit
- The file and code path involved
- Configuration required to reproduce it
- Step-by-step reproduction, and a proof of concept if you have one
- What an attacker gains

### Supported versions

Fixes are made on the **current major line (v3.x)** against the latest release. Older majors do not
receive backported patches. Upgrading to the latest v3 release is the supported remediation path.

### Disclosure

When a report is confirmed, the maintainers aim to fix it, publish a release, note it in
`CHANGELOG.md`, and — where the impact warrants it — publish a GitHub Security Advisory on the
repository. Reporters who ask to be credited will be.

## Security-relevant behaviour

These are properties of the code that matter when you threat-model a deployment. Each is stated from
the implementation, not from intent.

### Process spawning

`WorkerSpawner` builds each worker with `Symfony\Component\Process\Process` using an **explicit
argument array**:

```php
new Process([
    PHP_BINARY,
    base_path('artisan'),
    'queue:work',
    $connection,
    '--queue='.$queue,
    '--tries='.AutoscaleConfiguration::workerTries(),
    '--max-time='.AutoscaleConfiguration::workerTimeoutSeconds(),
    '--sleep='.AutoscaleConfiguration::workerSleepSeconds(),
]);
```

No shell is involved, so queue and connection names are passed as single arguments rather than being
interpolated into a command line. Queue names still originate from your queue backend by way of the
metrics package — treat them as data you control, not as attacker-supplied input.

Exactly three environment variables are injected into a spawned worker:
`LARAVEL_AUTOSCALE_WORKER`, `AUTOSCALE_MANAGER_ID`, and — for group workers only —
`AUTOSCALE_WORKER_GROUP`. The worker otherwise inherits the manager's environment, which means it
inherits the manager's credentials and file permissions.

### Configuration is executable by design

Several config keys are **class names that the container instantiates**: `strategy`, every entry in
`policies`, `sla_defaults` and per-queue profiles, `forecast.forecaster`, `forecast.policy`,
`pickup_time.percentile_calculator`, `fuse.classifier`, and the `'auto'|'redis'|'null'|FQCN` store
options. Anyone who can write `config/queue-autoscale.php` (or the env file feeding it) can cause
arbitrary classes to be constructed and invoked inside the manager process.

Treat the config file as code:

```bash
# Owned by deploy, readable by the runtime user, writable by neither at runtime
chown root:www-data config/queue-autoscale.php
chmod 640 config/queue-autoscale.php
```

`AutoscaleConfiguration::policyClasses()` filters `policies` to strings that pass `class_exists()`,
which prevents malformed entries from being resolved — it is not a security boundary.

### Resource limits are advisory, not enforced

`limits.max_cpu_percent`, `limits.max_memory_percent`, `limits.worker_memory_mb_estimate` and
`limits.worker_cpu_core_estimate` feed `CapacityCalculator`, which caps how many **new** workers the
autoscaler will start. They do not constrain workers that are already running, and they are not
kernel-enforced limits. If `SystemMetrics::limits()` cannot be read, `CapacityCalculator` falls back
to a conservative hardcoded result with `limitingFactor: 'system_metrics_unavailable'` rather than
assuming unlimited capacity.

A scaling policy can return a target above every one of these ceilings, and nothing re-clamps it —
see [Policy Execution Internals](scaling-policies.md). Review custom policies as carefully as you
review the config.

### Signals and process ownership

The package requires `ext-pcntl` and `ext-posix`. The manager installs signal handlers for graceful
shutdown and terminates its workers within `workers.shutdown_timeout_seconds` (default `30`).
`ManagerProcessLock` holds an exclusive `flock` per host, so a second manager on the same host is
refused unless started with `--replace`.

Because the manager sends signals to processes it spawned, it needs no elevated privileges beyond
those of its own user.

### Shared state

In cluster mode, manager heartbeats, leader election, recommendations and the cluster summary are
stored in your cache/Redis backend. Anything that can write those keys can influence worker targets
across the cluster. The failure-fuse and pickup-time stores use the same backend. Keep the cache
backend on a private network with authentication enabled, exactly as you would for the queue itself.

## Hardening checklist

- [ ] Manager runs as a dedicated non-root user
- [ ] `config/queue-autoscale.php` is not writable by the runtime user
- [ ] `workers.max` is set explicitly on every queue rather than relying on defaults
- [ ] `limits.worker_memory_mb_estimate` matches measured worker RSS
- [ ] Custom strategies and policies are code-reviewed like application code
- [ ] Redis/cache backend is authenticated and network-isolated
- [ ] The autoscaler's log channel is retained and monitored
- [ ] Dependencies kept current — `composer audit` in CI

## See Also

- [Production Deployment Reference](deployment.md) - Supervision, permissions and the runbook
- [Policy Execution Internals](scaling-policies.md) - Why policies bypass the capacity clamps
- [Configuration](../basic-usage/configuration.md) - Every key described above
