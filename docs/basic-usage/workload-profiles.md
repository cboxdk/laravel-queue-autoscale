---
title: "Workload Profiles"
description: "Complete guide to choosing and configuring workload profiles for optimal queue autoscaling"
weight: 16
---

# Workload Profiles

Workload profiles are pre-configured settings optimized for specific queue patterns. Instead of manually tuning dozens of parameters, choose a profile that matches your workload characteristics and get optimal scaling behavior instantly.

## Quick Reference

| Profile | SLA | Min Workers | Max Workers | Best For | Cost |
|---------|-----|-------------|-------------|----------|------|
| **Critical** | 10s | 5 | 50 | Payments, orders | $$$$$ |
| **High-Volume** | 20s | 3 | 40 | Email, batch | $$$ |
| **Balanced** ⭐ | 30s | 1 | 10 | General purpose | $$ |
| **Bursty** | 60s | 0 | 100 | Campaigns, webhooks | $ |
| **Background** | 300s | 0 | 5 | Cleanup, analytics | $ |
| **Exclusive** (v2) | 60s | **1** (pinned) | **1** (pinned) | Sequential integrations, ordered jobs | $ |

> **New in v2:** `ExclusiveProfile` pins a queue to a single worker that is never scaled. The autoscaler acts as a process supervisor (respawn on death) but never adds or removes workers. Use this for customer integrations that require strict sequential processing. See [Queue Topology](queue-topology.md) for the full behaviour model.

## Profile Deep Dive

### Critical Profile

**Mission-critical workloads with zero tolerance for delays**

```php
use Cbox\LaravelQueueAutoscale\Configuration\ProfilePresets;

'queues' => [
    'payments' => ProfilePresets::critical(),
],
```

#### Configuration Details

```php
[
    'max_pickup_time_seconds' => 10,     // Very tight SLA
    'min_workers' => 5,                   // Always ready (no cold starts)
    'max_workers' => 50,                  // High capacity for spikes
    'scale_cooldown_seconds' => 30,       // Rapid response
    'breach_threshold' => 0.4,            // Act at 40% of SLA (extremely proactive)
    'evaluation_interval_seconds' => 3,   // Near real-time monitoring
]
```

#### When to Use

✅ **Perfect for:**
- Payment processing systems
- Order fulfillment workflows
- Real-time notification delivery
- Financial transactions
- Critical API integrations
- User-facing operations with SLA contracts

❌ **Not suitable for:**
- Background tasks
- Analytics processing
- Batch operations that can tolerate delays
- Development/staging environments

#### Characteristics

**Performance:**
- Jobs picked up within 10 seconds
- Zero cold start delays (always has workers running)
- Handles spikes up to 50 concurrent workers
- Scales up in 30 seconds or less

**Cost:**
- Highest operational cost (always running 5+ workers)
- Over-provisioning acceptable for reliability
- Resource commitment even during idle periods

**Reliability:**
- Excellent (99.9%+ SLA compliance)
- Redundant capacity prevents bottlenecks
- Proactive scaling prevents issues before they occur

#### Real-World Example

**E-commerce Payment Queue**

```php
// config/queue-autoscale.php
'queues' => [
    'payments' => ProfilePresets::critical(),
],
```

**Behavior:**
```
09:00 - Store opens
  → Maintains 5 workers constantly
  → Average: 2 payments/minute
  → Workers mostly idle but instantly available

10:30 - Flash sale starts
  → Spike to 50 payments/minute
  → Scales from 5 → 15 workers in 30s
  → All payments processed within 10s SLA

11:00 - Sale continues
  → Sustains 15-20 workers
  → Zero payment delays
  → Cost: ~$50/hour in worker resources

12:00 - Sale ends
  → Gradually scales to 8 workers
  → Never drops below 5 (min_workers)
  → Ready for next spike
```

---

### High-Volume Profile

**Steady high-throughput workloads**

```php
'queues' => [
    'emails' => ProfilePresets::highVolume(),
],
```

#### Configuration Details

```php
[
    'max_pickup_time_seconds' => 20,     // Fast processing
    'min_workers' => 3,                   // Efficient baseline
    'max_workers' => 40,                  // High throughput capacity
    'scale_cooldown_seconds' => 45,       // Balanced response time
    'breach_threshold' => 0.5,            // Proactive (50% of SLA)
    'evaluation_interval_seconds' => 5,   // Standard monitoring
]
```

#### When to Use

✅ **Perfect for:**
- Email sending systems (marketing, transactional)
- Batch data processing
- File processing/conversions
- Data synchronization
- Report generation
- ETL pipelines

❌ **Not suitable for:**
- Sporadic/unpredictable workloads
- Ultra-low latency requirements (<20s)
- Very low volume queues

#### Characteristics

**Performance:**
- Jobs picked up within 20 seconds
- Sustains high throughput (1000+ jobs/hour)
- Efficient resource utilization
- Handles consistent load well

**Cost:**
- Moderate operational cost
- Good balance between performance and efficiency
- Scales down to 3 workers during low periods

**Reliability:**
- Very good (99%+ SLA compliance)
- Built for sustained throughput
- Handles gradual load increases smoothly

#### Real-World Example

**Email Service Queue**

```php
'queues' => [
    'transactional-emails' => ProfilePresets::highVolume(),
    'marketing-emails' => ProfilePresets::highVolume(),
],
```

**Behavior:**
```
Campaign sends 100,000 emails over 4 hours

Hour 1: Ramp-up
  → Starts with 3 workers
  → Queue depth: 25,000 emails
  → Scales to 25 workers
  → Throughput: 5,000 emails/hour
  → All emails sent within 20s SLA

Hour 2-3: Sustained load
  → Maintains 20-25 workers
  → Processes 50,000 emails
  → Consistent performance
  → Cost: ~$20/hour

Hour 4: Wind-down
  → Queue empties
  → Scales down to 10 workers
  → Eventually returns to 3 workers
  → Ready for next campaign
```

---

### Balanced Profile ⭐

**General-purpose default for typical web applications**

```php
// This is the default - no configuration needed!
'sla_defaults' => ProfilePresets::balanced(),

// Or per-queue
'queues' => [
    'default' => ProfilePresets::balanced(),
],
```

#### Configuration Details

```php
[
    'max_pickup_time_seconds' => 30,     // Standard SLA
    'min_workers' => 1,                   // Always processing
    'max_workers' => 10,                  // Reasonable capacity
    'scale_cooldown_seconds' => 60,       // Standard cooldown
    'breach_threshold' => 0.5,            // Balanced proactivity
    'evaluation_interval_seconds' => 5,   // Standard monitoring
]
```

#### When to Use

✅ **Perfect for:**
- General web application queues
- Mixed job types
- Moderate traffic applications
- Unknown/unpredictable patterns
- When you're not sure which profile to use
- Development and testing

❌ **Not suitable for:**
- Mission-critical workflows
- Massive scale requirements (>100k jobs/hour)
- Strict SLA contracts (<30s)

#### Characteristics

**Performance:**
- Jobs picked up within 30 seconds
- Handles typical web application loads
- Good for 80% of use cases
- Predictable behavior

**Cost:**
- Low to moderate cost
- Good cost/performance balance
- Scales to zero during extended idle periods (down to 1 worker)

**Reliability:**
- Good (95-99% SLA compliance)
- Suitable for most business requirements
- Handles gradual growth well

#### Real-World Example

**Typical Laravel Application**

```php
// config/queue-autoscale.php
// Balanced profile is the default
'sla_defaults' => ProfilePresets::balanced(),
```

**Behavior:**
```
Typical day for a SaaS application:

00:00-06:00 (Night - Low activity)
  → Maintains 1 worker
  → Processes occasional jobs
  → Cost: ~$1/hour

09:00-17:00 (Business hours - Active)
  → User activity increases
  → Scales to 4-6 workers
  → Handles user-generated jobs
  → All jobs complete within 30s
  → Cost: ~$5/hour

18:00-20:00 (Evening spike)
  → Automated reports trigger
  → Scales to 8-10 workers
  → Processes backlog quickly
  → Returns to baseline

Monthly cost: ~$1,500 for autoscaling
Fixed workers would cost: ~$3,600 (10 workers 24/7)
Savings: 58%
```

---

### Bursty Profile

**Sporadic spike workloads with rapid scale-up**

```php
'queues' => [
    'webhooks' => ProfilePresets::bursty(),
],
```

#### Configuration Details

```php
[
    'max_pickup_time_seconds' => 60,     // Accepts initial delay
    'min_workers' => 0,                   // Scale to zero when idle
    'max_workers' => 100,                 // Handle massive spikes
    'scale_cooldown_seconds' => 20,       // Very rapid scale-up
    'breach_threshold' => 0.4,            // Early spike detection (40%)
    'evaluation_interval_seconds' => 3,   // Frequent monitoring
]
```

#### When to Use

✅ **Perfect for:**
- Marketing campaign queues
- Webhook processing
- Social media event processing
- Scheduled batch imports
- Contest/promotion systems
- Viral content handling

❌ **Not suitable for:**
- Steady workloads
- Ultra-low latency requirements
- Workloads sensitive to cold starts

#### Characteristics

**Performance:**
- Jobs picked up within 60 seconds
- Handles massive spikes (0 → 100 workers in minutes)
- Optimized for bursty patterns
- Rapid scale-down after spike

**Cost:**
- Very low baseline cost (scales to zero)
- Cost spikes during events (acceptable trade-off)
- Excellent cost efficiency for sporadic loads

**Reliability:**
- Excellent spike handling
- Variable latency (cold starts acceptable)
- Built for unpredictable patterns

#### Real-World Example

**Social Media Campaign Queue**

```php
'queues' => [
    'campaign-posts' => ProfilePresets::bursty(),
    'user-submissions' => ProfilePresets::bursty(),
],
```

**Behavior:**
```
Instagram contest: "Post with #OurBrand to win"

Day 1-6: Quiet period
  → 0-1 workers running
  → Occasional submissions (~10/day)
  → Cost: ~$0.50/day

Day 7: Contest announced
09:00 - Announcement
  → 0 workers running
  → Queue empty

09:15 - First wave (100 submissions in 10 minutes)
  → Detects spike at 40% threshold (24s of 60s SLA)
  → Scales 0 → 5 workers in 20s
  → Continues to 10 workers
  → Processes submissions within 60s

10:00 - Viral spike (1,000 submissions in 30 minutes)
  → Scales 10 → 50 workers
  → Then 50 → 80 workers
  → Handles ~33 submissions/minute
  → All processed within SLA

12:00 - Spike ends
  → Scales down 80 → 40 workers
  → Then 40 → 10 workers
  → Eventually back to 0 workers
  → Cost for spike: ~$50

Total campaign cost: ~$60
Fixed workers would cost: ~$720 (10 workers 24/7)
Savings: 92%
```

---

### Background Profile

**Low-priority background jobs**

```php
'queues' => [
    'cleanup' => ProfilePresets::background(),
],
```

#### Configuration Details

```php
[
    'max_pickup_time_seconds' => 300,    // 5 minutes (very relaxed)
    'min_workers' => 0,                   // Scale to zero
    'max_workers' => 5,                   // Limited resources
    'scale_cooldown_seconds' => 120,      // Cost-optimized cooldown
    'breach_threshold' => 0.7,            // Relaxed (70% of SLA)
    'evaluation_interval_seconds' => 10,  // Infrequent monitoring
]
```

#### When to Use

✅ **Perfect for:**
- Database cleanup tasks
- Log aggregation
- Analytics calculation
- Cache warming
- Nightly reports
- Old data archival
- Non-urgent maintenance

❌ **Not suitable for:**
- User-facing operations
- Time-sensitive tasks
- Business-critical processes

#### Characteristics

**Performance:**
- Jobs picked up within 5 minutes
- Eventually consistent processing
- Delays are acceptable
- Minimal resource commitment

**Cost:**
- Lowest operational cost
- Scales to zero aggressively
- Maximum cost efficiency
- Suitable for budget constraints

**Reliability:**
- Eventually consistent
- Jobs will complete eventually
- Delays don't impact business
- Good enough for background work

#### Real-World Example

**Maintenance Queue**

```php
'queues' => [
    'database-cleanup' => ProfilePresets::background(),
    'log-rotation' => ProfilePresets::background(),
    'analytics-aggregation' => ProfilePresets::background(),
],
```

**Behavior:**
```
Nightly maintenance tasks:

22:00 - Scheduled jobs dispatch
  → 500 cleanup jobs queued
  → 200 analytics jobs queued
  → 100 log rotation jobs

22:00-22:05 - Initial delay (acceptable)
  → 0 workers running
  → Jobs wait in queue

22:05 - Workers spin up
  → Scales 0 → 1 worker
  → Begins processing
  → Very slow but acceptable

22:15 - More workers added
  → SLA at 70% threshold
  → Scales 1 → 3 workers
  → Processing accelerates

23:00 - Queue processed
  → All 800 jobs complete
  → Total time: 1 hour
  → Cost: ~$3

23:30 - Workers scale down
  → 3 → 1 workers
  → Then 1 → 0 workers
  → Ready for tomorrow

Monthly cost: ~$90
Fixed 3 workers would cost: ~$2,160
Savings: 96%
```

## Choosing the Right Profile

### Decision Tree

```
Does the workload affect users directly?
├─ Yes → Is it critical to business operations?
│   ├─ Yes (payments, orders) → CRITICAL
│   └─ No (emails, reports) → HIGH-VOLUME or BALANCED
│
└─ No → Is the volume predictable?
    ├─ Yes (steady background) → BACKGROUND
    ├─ No (spikes/campaigns) → BURSTY
    └─ Mixed → BALANCED
```

### By Characteristics

**Choose CRITICAL if you need:**
- Sub-10 second latency
- Zero cold start tolerance
- Maximum reliability
- SLA contracts with penalties

**Choose HIGH-VOLUME if you have:**
- Steady high throughput
- Predictable patterns
- 1000+ jobs per hour
- Balance between cost and performance

**Choose BALANCED if you have:**
- Mixed workloads
- Moderate volume
- Standard web application
- Uncertainty about best fit

**Choose BURSTY if you have:**
- Unpredictable spikes
- Long idle periods
- Event-driven workloads
- Cost sensitivity during idle

**Choose BACKGROUND if you have:**
- No time sensitivity
- Overnight/scheduled jobs
- Cost optimization priority
- Non-user-facing tasks

## Mixing Profiles

You can use different profiles for different queues in the same application:

```php
'queues' => [
    // Different profiles for different needs
    'payments' => ProfilePresets::critical(),
    'emails' => ProfilePresets::highVolume(),
    'default' => ProfilePresets::balanced(),
    'webhooks' => ProfilePresets::bursty(),
    'cleanup' => ProfilePresets::background(),
],
```

## Customizing Profiles

Start with a profile and customize specific parameters:

```php
'queues' => [
    'custom' => array_merge(ProfilePresets::balanced(), [
        'max_workers' => 20,              // Increase capacity
        'max_pickup_time_seconds' => 15,  // Tighter SLA
    ]),
],
```

## Profile Comparison

### Cost Analysis

Based on typical AWS t3.medium instances ($0.05/hour):

| Profile | Baseline Cost/Day | Spike Cost/Hour | Monthly Cost |
|---------|-------------------|-----------------|--------------|
| Critical | $6.00 (5 workers) | $60 (50 workers) | ~$4,500 |
| High-Volume | $3.60 (3 workers) | $48 (40 workers) | ~$1,800 |
| Balanced | $1.20 (1 worker) | $12 (10 workers) | ~$900 |
| Bursty | $0 (0 workers) | $120 (100 workers) | ~$300 |
| Background | $0 (0 workers) | $6 (5 workers) | ~$90 |

*Actual costs vary based on instance types, spike frequency, and load patterns*

### Performance Comparison

| Profile | P50 Latency | P95 Latency | P99 Latency | Throughput |
|---------|-------------|-------------|-------------|------------|
| Critical | 2s | 5s | 8s | Excellent |
| High-Volume | 5s | 12s | 18s | Excellent |
| Balanced | 10s | 20s | 28s | Good |
| Bursty | 15s | 40s | 55s | Variable |
| Background | 60s | 180s | 280s | Low |

## Migration Guide

### From Manual Configuration

**Before:**
```php
'sla_defaults' => [
    'max_pickup_time_seconds' => 30,
    'min_workers' => 1,
    'max_workers' => 10,
    'scale_cooldown_seconds' => 60,
],
```

**After:**
```php
'sla_defaults' => ProfilePresets::balanced(),
```

### From Fixed Workers

**Before:**
```bash
# supervisor config - 10 fixed workers
[program:queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php artisan queue:work
numprocs=10
```

**After:**
```php
// config/queue-autoscale.php
'sla_defaults' => ProfilePresets::balanced(),
// Autoscaler manages workers dynamically (0-10 based on load)
```

## Troubleshooting

### Profile Not Meeting SLA

If your chosen profile isn't meeting SLA targets:

1. **Check metrics**: Verify throughput and processing times are accurate
2. **Increase max_workers**: Allow more capacity
3. **Decrease SLA**: Relax targets if requirements allow
4. **Switch profile**: Move to more aggressive profile (e.g., Balanced → High-Volume)

### Over-Provisioning

If you're running too many workers:

1. **Check min_workers**: Lower the baseline
2. **Increase cooldown**: Reduce scaling frequency
3. **Increase threshold**: Scale less aggressively
4. **Switch profile**: Move to more conservative profile (e.g., Balanced → Background)

### Oscillation (Thrashing)

If workers scale up/down rapidly:

1. **Add policies**: Use ConservativeScaleDownPolicy
2. **Increase cooldown**: Prevent rapid changes
3. **Adjust threshold**: More conservative triggers

## Next Steps

- [Scaling Policies](scaling-policies.md) - Modify profile behavior with policies
- [Monitoring](monitoring.md) - Track profile performance
- [Performance Tuning](performance.md) - Optimize for your workload
