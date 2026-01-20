<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\TrendScalingPolicy;

test('disabled policy has zero trend weight', function () {
    $policy = TrendScalingPolicy::DISABLED;

    expect($policy->trendWeight())->toBe(0.0);
});

test('hint policy has 0.3 trend weight', function () {
    $policy = TrendScalingPolicy::HINT;

    expect($policy->trendWeight())->toBe(0.3);
});

test('moderate policy has 0.5 trend weight', function () {
    $policy = TrendScalingPolicy::MODERATE;

    expect($policy->trendWeight())->toBe(0.5);
});

test('aggressive policy has 0.8 trend weight', function () {
    $policy = TrendScalingPolicy::AGGRESSIVE;

    expect($policy->trendWeight())->toBe(0.8);
});

test('disabled policy has impossible confidence threshold', function () {
    $policy = TrendScalingPolicy::DISABLED;

    expect($policy->minConfidence())->toBe(1.0);
});

test('hint policy requires high confidence', function () {
    $policy = TrendScalingPolicy::HINT;

    expect($policy->minConfidence())->toBe(0.8);
});

test('moderate policy requires moderate confidence', function () {
    $policy = TrendScalingPolicy::MODERATE;

    expect($policy->minConfidence())->toBe(0.7);
});

test('aggressive policy accepts low confidence', function () {
    $policy = TrendScalingPolicy::AGGRESSIVE;

    expect($policy->minConfidence())->toBe(0.5);
});

test('isEnabled returns false for disabled', function () {
    $policy = TrendScalingPolicy::DISABLED;

    expect($policy->isEnabled())->toBeFalse();
});

test('isEnabled returns true for non-disabled policies', function (TrendScalingPolicy $policy) {
    expect($policy->isEnabled())->toBeTrue();
})->with([
    TrendScalingPolicy::HINT,
    TrendScalingPolicy::MODERATE,
    TrendScalingPolicy::AGGRESSIVE,
]);

test('description returns meaningful text', function (TrendScalingPolicy $policy, string $expected) {
    expect($policy->description())->toContain($expected);
})->with([
    [TrendScalingPolicy::DISABLED, 'disabled'],
    [TrendScalingPolicy::HINT, 'Conservative'],
    [TrendScalingPolicy::MODERATE, 'Balanced'],
    [TrendScalingPolicy::AGGRESSIVE, 'Proactive'],
]);

test('all policies have string values', function (TrendScalingPolicy $policy, string $value) {
    expect($policy->value)->toBe($value);
})->with([
    [TrendScalingPolicy::DISABLED, 'disabled'],
    [TrendScalingPolicy::HINT, 'hint'],
    [TrendScalingPolicy::MODERATE, 'moderate'],
    [TrendScalingPolicy::AGGRESSIVE, 'aggressive'],
]);
