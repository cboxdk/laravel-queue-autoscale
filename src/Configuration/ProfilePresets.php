<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Configuration;

/**
 * Standard workload profiles with optimized configurations
 *
 * Each profile is optimized for specific workload patterns:
 *
 * - Critical: Mission-critical workloads (payments, orders) - zero tolerance for delays
 * - High-Volume: Steady high-throughput workloads (email, batch processing)
 * - Balanced: General-purpose default for typical web applications
 * - Bursty: Sporadic spike workloads (campaigns, webhooks, social events)
 * - Background: Low-priority background jobs (cleanup, analytics, reports)
 */
final class ProfilePresets
{
    /**
     * Critical/Mission-Critical Profile
     *
     * Optimized for: Payment processing, order fulfillment, real-time notifications
     *
     * Characteristics:
     * - Minimal latency (10s SLA)
     * - Always ready (5 min workers)
     * - High capacity (50 max workers)
     * - Rapid response (30s cooldown)
     * - Extremely proactive (40% threshold)
     * - Near real-time evaluation (3s)
     *
     * Trade-offs: Highest cost, excellent reliability, excellent latency
     *
     * Recommended policies: NoScaleDownPolicy, BreachNotificationPolicy
     *
     * @return array<string, int|float>
     */
    public static function critical(): array
    {
        return [
            'max_pickup_time_seconds' => 10,
            'min_workers' => 5,
            'max_workers' => 50,
            'scale_cooldown_seconds' => 30,
            'breach_threshold' => 0.4,
            'evaluation_interval_seconds' => 3,
        ];
    }

    /**
     * High-Volume/Steady Workload Profile
     *
     * Optimized for: Email sending, batch processing, data synchronization
     *
     * Characteristics:
     * - Fast processing (20s SLA)
     * - Efficient baseline (3 min workers)
     * - High throughput capacity (40 max workers)
     * - Balanced response (45s cooldown)
     * - Proactive scaling (50% threshold)
     * - Standard evaluation (5s)
     *
     * Trade-offs: Moderate cost, very good reliability, good latency
     *
     * Recommended policies: ConservativeScaleDownPolicy, BreachNotificationPolicy
     *
     * @return array<string, int|float>
     */
    public static function highVolume(): array
    {
        return [
            'max_pickup_time_seconds' => 20,
            'min_workers' => 3,
            'max_workers' => 40,
            'scale_cooldown_seconds' => 45,
            'breach_threshold' => 0.5,
            'evaluation_interval_seconds' => 5,
        ];
    }

    /**
     * Balanced/Default Profile (Recommended Default)
     *
     * Optimized for: General web applications, mixed job types, typical workloads
     *
     * Characteristics:
     * - Standard SLA (30s)
     * - Always processing (1 min worker)
     * - Reasonable capacity (10 max workers)
     * - Standard cooldown (60s)
     * - Balanced proactivity (50% threshold)
     * - Standard evaluation (5s)
     *
     * Trade-offs: Good cost/performance balance, good reliability, good latency
     *
     * Recommended policies: ConservativeScaleDownPolicy
     *
     * @return array<string, int|float>
     */
    public static function balanced(): array
    {
        return [
            'max_pickup_time_seconds' => 30,
            'min_workers' => 1,
            'max_workers' => 10,
            'scale_cooldown_seconds' => 30,
            'breach_threshold' => 0.5,
            'evaluation_interval_seconds' => 5,
        ];
    }

    /**
     * Bursty/Sporadic Workload Profile
     *
     * Optimized for: Marketing campaigns, webhook processing, social media events
     *
     * Characteristics:
     * - Accepts initial delay (60s SLA)
     * - Scale to zero (0 min workers)
     * - Handle massive spikes (100 max workers)
     * - Rapid scale-up (20s cooldown)
     * - Early spike detection (40% threshold)
     * - Frequent monitoring (3s evaluation)
     *
     * Trade-offs: Low baseline cost, variable latency, excellent spike handling
     *
     * Recommended policies: AggressiveScaleDownPolicy, BreachNotificationPolicy
     *
     * @return array<string, int|float>
     */
    public static function bursty(): array
    {
        return [
            'max_pickup_time_seconds' => 60,
            'min_workers' => 0,
            'max_workers' => 100,
            'scale_cooldown_seconds' => 20,
            'breach_threshold' => 0.4,
            'evaluation_interval_seconds' => 3,
        ];
    }

    /**
     * Background/Low-Priority Profile
     *
     * Optimized for: Cleanup tasks, analytics, log processing, report generation
     *
     * Characteristics:
     * - Relaxed SLA (300s / 5 minutes)
     * - Scale to zero (0 min workers)
     * - Limited resources (5 max workers)
     * - Cost-optimized cooldown (120s)
     * - Relaxed proactivity (70% threshold)
     * - Infrequent evaluation (10s)
     *
     * Trade-offs: Lowest cost, acceptable delays, eventually consistent
     *
     * Recommended policies: AggressiveScaleDownPolicy
     *
     * @return array<string, int|float>
     */
    public static function background(): array
    {
        return [
            'max_pickup_time_seconds' => 300,
            'min_workers' => 0,
            'max_workers' => 5,
            'scale_cooldown_seconds' => 120,
            'breach_threshold' => 0.7,
            'evaluation_interval_seconds' => 10,
        ];
    }

    /**
     * Get all available profiles with their metadata
     *
     * @return array<string, array{config: array<string, int|float>, description: string, use_cases: string[], cost: string, policies: string[]}>
     */
    public static function all(): array
    {
        return [
            'critical' => [
                'config' => self::critical(),
                'description' => 'Mission-critical workloads with zero tolerance for delays',
                'use_cases' => ['Payment processing', 'Order fulfillment', 'Real-time notifications'],
                'cost' => '$$$$$',
                'policies' => ['NoScaleDownPolicy', 'BreachNotificationPolicy'],
            ],
            'high_volume' => [
                'config' => self::highVolume(),
                'description' => 'Steady high-throughput workloads',
                'use_cases' => ['Email sending', 'Batch processing', 'Data synchronization'],
                'cost' => '$$$',
                'policies' => ['ConservativeScaleDownPolicy', 'BreachNotificationPolicy'],
            ],
            'balanced' => [
                'config' => self::balanced(),
                'description' => 'General-purpose default for typical web applications',
                'use_cases' => ['Mixed job types', 'Web application queues', 'General processing'],
                'cost' => '$$',
                'policies' => ['ConservativeScaleDownPolicy'],
            ],
            'bursty' => [
                'config' => self::bursty(),
                'description' => 'Sporadic spike workloads with rapid scale-up',
                'use_cases' => ['Marketing campaigns', 'Webhook processing', 'Social events'],
                'cost' => '$',
                'policies' => ['AggressiveScaleDownPolicy', 'BreachNotificationPolicy'],
            ],
            'background' => [
                'config' => self::background(),
                'description' => 'Low-priority background jobs',
                'use_cases' => ['Cleanup tasks', 'Analytics', 'Log processing', 'Reports'],
                'cost' => '$',
                'policies' => ['AggressiveScaleDownPolicy'],
            ],
        ];
    }
}
