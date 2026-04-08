---
title: "Quick Start Guide"
description: "Get started with Queue Autoscale for Laravel in 5 minutes"
weight: 2
---

# Quick Start Guide

Get your queue autoscaling up and running in minutes with sensible defaults.

## Installation

```bash
composer require cboxdk/laravel-queue-autoscale
php artisan vendor:publish --tag="queue-autoscale-config"
```

## Choose Your Profile

The autoscaler comes with 5 pre-configured profiles. Choose the one that best matches your workload:

| Profile | Best For | SLA | Workers | Cost |
|---------|----------|-----|---------|------|
| **Critical** | Payments, orders | 10s | 5-50 | High |
| **High-Volume** | Emails, notifications | 20s | 3-40 | Medium-High |
| **Balanced** | General purpose | 30s | 1-10 | Medium |
| **Bursty** | Sporadic spikes | 60s | 0-100 | Low-Medium |
| **Background** | Cleanup, reports | 300s | 0-5 | Low |

**Not sure?** Start with **Balanced** - it's the default and works for 80% of use cases.

## Basic Configuration

### Option 1: Use Default (Balanced)

If you're happy with the Balanced profile, you're done! No configuration needed.

```php
// config/queue-autoscale.php - default configuration
return [
    'enabled' => true,
    'sla_defaults' => ProfilePresets::balanced(),
];
```

### Option 2: Choose a Different Default Profile

Change the default for ALL queues:

```php
// config/queue-autoscale.php
use Cbox\LaravelQueueAutoscale\Configuration\ProfilePresets;

return [
    'sla_defaults' => ProfilePresets::highVolume(), // Changed from balanced()
];
```

### Option 3: Different Profiles Per Queue

Use different profiles for specific queues:

```php
// config/queue-autoscale.php
use Cbox\LaravelQueueAutoscale\Configuration\ProfilePresets;

return [
    'sla_defaults' => ProfilePresets::balanced(), // Default for all queues

    'queues' => [
        'payments' => ProfilePresets::critical(),      // Mission-critical
        'emails' => ProfilePresets::highVolume(),       // High throughput
        'cleanup' => ProfilePresets::background(),      // Low priority
    ],
];
```

## Start the Autoscaler

```bash
php artisan queue:autoscale
```

That's it! Your queues are now autoscaling.

## Common Scenarios

### E-commerce Store

```php
// Payments must be fast and reliable
// Emails can be slower but need high throughput
// Cleanup can wait

'queues' => [
    'payments' => ProfilePresets::critical(),
    'emails' => ProfilePresets::highVolume(),
    'cleanup' => ProfilePresets::background(),
],
```

### SaaS Application

```php
// Most operations use balanced profile
// Background tasks can be slower

'sla_defaults' => ProfilePresets::balanced(),

'queues' => [
    'analytics' => ProfilePresets::background(),
    'exports' => ProfilePresets::background(),
],
```

### Marketing Platform

```php
// Handle campaign spikes efficiently
// Webhooks need to be fast

'sla_defaults' => ProfilePresets::bursty(),

'queues' => [
    'webhooks' => ProfilePresets::critical(),
],
```

## Customizing a Profile

Need to tweak a profile? Use `array_merge()`:

```php
use Cbox\LaravelQueueAutoscale\Configuration\ProfilePresets;

'queues' => [
    'custom' => array_merge(ProfilePresets::balanced(), [
        'max_workers' => 20,              // Override max workers
        'max_pickup_time_seconds' => 15,  // Tighter SLA
    ]),
],
```

## Understanding What's Happening

The autoscaler evaluates your queues every 5 seconds and:

1. **Checks SLA compliance**: Is the oldest job approaching the SLA target?
2. **Predicts future load**: Based on arrival rate and backlog trends
3. **Scales proactively**: Adds workers BEFORE breaching SLA (not after)
4. **Applies policies**: Prevents thrashing with conservative scale-down

### Progressive Scaling

The autoscaler becomes more aggressive as you approach the SLA:

- **50-80% of SLA**: Early preparation (1.2× workers)
- **80-90% of SLA**: Aggressive scaling (1.5× workers)
- **90-100% of SLA**: Emergency mode (2.0× workers)
- **100%+ (breach)**: Maximum response (3.0× workers)

This ensures you rarely breach your SLA targets.

## Monitoring

Watch the autoscaler in action:

```bash
# Verbose output
php artisan queue:autoscale -vvv

# You'll see:
# 📊 Queue: default | Backlog: 150 | Workers: 3 → 8 | Reason: Predicted SLA breach
# ⚡ Scaled UP: 3 → 8 workers (added 5)
```

## Scaling Policies

By default, the autoscaler uses two policies:

1. **ConservativeScaleDownPolicy**: Prevents thrashing by limiting scale-down to 1 worker per cycle
2. **BreachNotificationPolicy**: Logs SLA breach risks for monitoring

These work great for most applications. Want to customize? See [Scaling Policies](basic-usage/scaling-policies.md).

## Next Steps

Now that you're up and running:

- **Tune your configuration**: See [Workload Profiles](basic-usage/workload-profiles.md) for detailed profile documentation
- **Understand policies**: See [Scaling Policies](basic-usage/scaling-policies.md) to customize scaling behavior
- **Monitor performance**: Watch your logs for SLA breach warnings
- **Optimize costs**: Consider more aggressive scale-down policies for non-critical queues

## Troubleshooting

### Workers not scaling up

**Check 1**: Is the autoscaler running?
```bash
# Should show the process
ps aux | grep "queue:autoscale"
```

**Check 2**: Is autoscaling enabled?
```php
// config/queue-autoscale.php
'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
```

**Check 3**: Are you hitting max_workers limit?
```bash
# Run with verbose output
php artisan queue:autoscale -vvv
# Look for: "⚠️ At max workers (10), cannot scale up"
```

### Workers scaling down too quickly

Change to a more conservative policy:

```php
use Cbox\LaravelQueueAutoscale\Policies\NoScaleDownPolicy;

'policies' => [
    NoScaleDownPolicy::class,  // Never scale down
],
```

### Too many workers during idle time

Lower your `min_workers`:

```php
'queues' => [
    'default' => array_merge(ProfilePresets::balanced(), [
        'min_workers' => 0,  // Scale to zero when idle
    ]),
],
```

### Still stuck?

Check the [detailed documentation](basic-usage/workload-profiles.md) or review your logs:

```bash
# Check autoscaler logs
tail -f storage/logs/laravel.log | grep -i "autoscale"
```

## Profile Reference

Quick reminder of what each profile is optimized for:

**Critical** (`ProfilePresets::critical()`)
- 10-second SLA, 5-50 workers
- Best for: Payments, orders, critical API calls
- Cost: High, but zero downtime

**High-Volume** (`ProfilePresets::highVolume()`)
- 20-second SLA, 3-40 workers
- Best for: Emails, notifications, webhooks
- Cost: Medium-high, high throughput

**Balanced** (`ProfilePresets::balanced()`)
- 30-second SLA, 1-10 workers
- Best for: General web app queues
- Cost: Medium, good for most apps

**Bursty** (`ProfilePresets::bursty()`)
- 60-second SLA, 0-100 workers
- Best for: Marketing campaigns, viral content
- Cost: Low baseline, high during spikes

**Background** (`ProfilePresets::background()`)
- 5-minute SLA, 0-5 workers
- Best for: Cleanup, reports, analytics
- Cost: Very low

## Support

- **Documentation**: [Full documentation](introduction.md)
- **Issues**: [GitHub Issues](https://github.com/cboxdk/laravel-queue-autoscale/issues)
- **Discussions**: [GitHub Discussions](https://github.com/cboxdk/laravel-queue-autoscale/discussions)
