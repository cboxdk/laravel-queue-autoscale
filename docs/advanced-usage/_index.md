---
title: "Advanced Usage"
description: "Advanced topics for customizing and extending Queue Autoscale for Laravel"
weight: 30
---

# Advanced Usage

This section covers advanced topics for customizing Queue Autoscale for Laravel to fit your specific needs.

## Extensibility

Implement custom business logic and scaling strategies:

- **[Custom Strategies](custom-strategies.md)** - Write your own scaling algorithms
- **[Scaling Policies](scaling-policies.md)** - Add cross-cutting concerns and hooks

## Production

Deploy and secure your autoscaler for production environments:

- **[Deployment](deployment.md)** - Production deployment guide
- **[Security](security.md)** - Security policy and best practices

## Contributing

Help make Queue Autoscale for Laravel better:

- **[Contributing](contributing.md)** - Development guidelines and workflow

## When to Use Custom Strategies

Custom strategies are useful when:

- You have unique business requirements not covered by the default hybrid algorithm
- You need to integrate with external systems for scaling decisions
- You want to implement domain-specific logic (e.g., cost optimization, customer tiers)
- You need to comply with specific SLA structures or regulatory requirements

## When to Use Policies

Policies are ideal for:

- Logging and metrics collection
- Notifications (Slack, email, PagerDuty)
- Cost tracking and budget enforcement
- Integration with monitoring systems
- Custom validation logic
- Multi-stage approval workflows

## Prerequisites

Before exploring advanced topics:

1. Understand the [How It Works](../basic-usage/how-it-works.md) guide
2. Be familiar with [Configuration](../basic-usage/configuration.md) options
3. Have the autoscaler running successfully in your environment

## Example Use Cases

- **Cost Optimization**: Custom strategy that scales based on AWS Spot instance availability
- **Multi-Tenant SLAs**: Different strategies per customer tier (premium, standard, free)
- **Business Hours**: Policy that enforces different min/max workers during peak vs off-peak
- **Budget Enforcement**: Policy that prevents scaling beyond allocated cloud budget
- **Compliance**: Strategy that respects data residency and processing requirements
