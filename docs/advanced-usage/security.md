---
title: "Security"
description: "Queue Autoscale for Laravel security policy and vulnerability reporting"
weight: 33
---

# Security Policy

## Supported Versions

We release patches for security vulnerabilities for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 0.1.x   | :white_check_mark: |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please report them via email to **security@cbox.dk**.

You should receive a response within 48 hours. If for some reason you do not, please follow up via email to ensure we received your original message.

### What to Include

Please include the following information in your report:

- Type of issue (e.g., command injection, process escape, resource exhaustion)
- Full paths of source file(s) related to the manifestation of the issue
- The location of the affected source code (tag/branch/commit or direct URL)
- Any special configuration required to reproduce the issue
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

This information will help us triage your report more quickly.

## Security Considerations

### Worker Process Management

- **Process Spawning**: Workers are spawned via Symfony Process with explicit command arrays (no shell execution)
- **Signal Handling**: Proper SIGTERM and SIGINT handling prevents orphaned processes
- **Graceful Shutdown**: 10-second timeout for graceful termination before SIGKILL
- **PID Tracking**: All worker processes tracked to prevent leaks

### Resource Limits

- **CPU/Memory Constraints**: System resource limits enforced via `system-metrics` package
- **Worker Caps**: Configurable min/max worker limits per queue
- **Cooldown Periods**: Prevents rapid scaling that could overwhelm system

### Configuration Security

- **No Arbitrary Execution**: Configuration uses class names, not executable strings
- **Validated Inputs**: All configuration values validated and type-checked
- **Safe Defaults**: Sensible defaults that prioritize stability

### Known Limitations

1. **Local Execution Only**: Autoscaler must run on same server as queue workers
2. **Process Permissions**: Requires permissions to spawn and terminate processes
3. **Signal Handling**: Relies on OS signal handling (POSIX systems)
4. **No Sandboxing**: Worker processes run with same permissions as autoscaler

### Best Practices

When deploying Queue Autoscale for Laravel:

1. **Run as Non-Root**: Never run autoscaler as root user
2. **Limit Permissions**: Use dedicated user with minimal permissions
3. **Monitor Logs**: Regularly review autoscaler logs for anomalies
4. **Resource Quotas**: Set appropriate worker limits based on system capacity
5. **Supervisor Configuration**: Use Supervisor or similar for process management
6. **Secure Metrics**: Ensure `laravel-queue-metrics` package is properly secured
7. **Network Isolation**: Queue backend (Redis/Database) should be network-isolated

### Security Checklist

- [ ] Autoscaler runs as non-root user
- [ ] Worker limits configured appropriately
- [ ] Supervisor manages autoscaler process
- [ ] Logs reviewed regularly
- [ ] Queue backend secured and isolated
- [ ] System metrics package up to date
- [ ] Custom strategies reviewed for security issues
- [ ] Policies don't expose sensitive information

## Disclosure Policy

When we receive a security bug report, we will:

1. Confirm the problem and determine affected versions
2. Audit code to find any similar problems
3. Prepare fixes for all supported versions
4. Release new security patch versions as quickly as possible

## Past Security Advisories

None yet. This is the initial release.

## Contact

- **Security Email**: security@cbox.dk
- **GPG Key**: Available on request
- **Response Time**: Within 48 hours

## Acknowledgments

We appreciate security researchers who responsibly disclose vulnerabilities to us. Contributors who report valid security issues will be acknowledged in:

- Security advisory
- CHANGELOG.md
- GitHub security advisory page

Thank you for helping keep Queue Autoscale for Laravel secure!
