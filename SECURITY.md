# Security Policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through **GitHub Private Vulnerability Reporting**:
[Report a vulnerability](https://github.com/cboxdk/laravel-queue-autoscale/security/advisories/new)
(repository → **Security** → **Report a vulnerability**).

Please include the affected version or commit, what the issue is and what it lets an
attacker do, reproduction steps, and any remediation you would suggest.

## What to expect

This is a small open-source package maintained on a best-effort basis. There is no
staffed security desk, no guaranteed response window and no bug-bounty programme.
Reports are triaged as maintainer time allows, and confirmed issues are fixed and
released as promptly as they can be. Unless you would rather stay anonymous, you will
be credited when the fix ships, and the timing of public disclosure is coordinated
with you.

## Safe harbor

We will not pursue or support legal action against anyone who, in good faith, reports
through the private channel above, avoids privacy violations and service degradation,
only tests against systems they own or are permitted to test, and allows reasonable
time to remediate before disclosing publicly.

## Supported versions

Security fixes are provided for the latest release of the current major.

| Version | Supported |
|---------|-----------|
| `4.x`   | ✅        |
| `3.x`   | ❌        |

## What this package does to stay safe

The security-relevant behaviour is documented in
[`docs/advanced-usage/security.md`](docs/advanced-usage/security.md) — read that first;
it is the canonical version and covers the threat model in detail. In short:

- **Queue names are validated before they reach a `queue:work` argument.** The manager
  discovers queues from metrics rather than only from config, so a queue name is
  attacker-influenced input wherever job dispatch is. Names carrying option syntax are
  refused, and a comma is treated as a group separator with each member validated
  individually.
- **A host worker ceiling bounds total spawns**, so discovered per-tenant queues each
  being raised to their configured floor cannot exhaust the host.
- **CI gates every push**: PHPStan at level max with no baseline, the full Pest suite
  across both supported Laravel majors, `composer audit --no-dev`, a permissive-licence
  check over the dependency tree, and CycloneDX SBOM validation.

This package spawns and signals operating-system processes on the host it runs on. It
is intended to run as a trusted operator-controlled process, not to be exposed to
untrusted callers, and it ships no HTTP surface of its own.

## Disclosure history

Confirmed vulnerabilities and their fixes are published as
[GitHub Security Advisories](https://github.com/cboxdk/laravel-queue-autoscale/security/advisories)
and noted in [`CHANGELOG.md`](CHANGELOG.md).
