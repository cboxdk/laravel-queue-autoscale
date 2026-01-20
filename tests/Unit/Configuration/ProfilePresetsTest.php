<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\ProfilePresets;

test('critical profile returns correct values', function () {
    $profile = ProfilePresets::critical();

    expect($profile)->toHaveKey('max_pickup_time_seconds')
        ->and($profile)->toHaveKey('min_workers')
        ->and($profile)->toHaveKey('max_workers')
        ->and($profile)->toHaveKey('scale_cooldown_seconds')
        ->and($profile)->toHaveKey('breach_threshold')
        ->and($profile)->toHaveKey('evaluation_interval_seconds')
        ->and($profile['max_pickup_time_seconds'])->toBe(10)
        ->and($profile['min_workers'])->toBe(5)
        ->and($profile['max_workers'])->toBe(50)
        ->and($profile['scale_cooldown_seconds'])->toBe(30)
        ->and($profile['breach_threshold'])->toBe(0.4)
        ->and($profile['evaluation_interval_seconds'])->toBe(3);
});

test('highVolume profile returns correct values', function () {
    $profile = ProfilePresets::highVolume();

    expect($profile['max_pickup_time_seconds'])->toBe(20)
        ->and($profile['min_workers'])->toBe(3)
        ->and($profile['max_workers'])->toBe(40)
        ->and($profile['scale_cooldown_seconds'])->toBe(45)
        ->and($profile['breach_threshold'])->toBe(0.5)
        ->and($profile['evaluation_interval_seconds'])->toBe(5);
});

test('balanced profile returns correct values', function () {
    $profile = ProfilePresets::balanced();

    expect($profile['max_pickup_time_seconds'])->toBe(30)
        ->and($profile['min_workers'])->toBe(1)
        ->and($profile['max_workers'])->toBe(10)
        ->and($profile['scale_cooldown_seconds'])->toBe(30)
        ->and($profile['breach_threshold'])->toBe(0.5)
        ->and($profile['evaluation_interval_seconds'])->toBe(5);
});

test('bursty profile returns correct values', function () {
    $profile = ProfilePresets::bursty();

    expect($profile['max_pickup_time_seconds'])->toBe(60)
        ->and($profile['min_workers'])->toBe(0)
        ->and($profile['max_workers'])->toBe(100)
        ->and($profile['scale_cooldown_seconds'])->toBe(20)
        ->and($profile['breach_threshold'])->toBe(0.4)
        ->and($profile['evaluation_interval_seconds'])->toBe(3);
});

test('background profile returns correct values', function () {
    $profile = ProfilePresets::background();

    expect($profile['max_pickup_time_seconds'])->toBe(300)
        ->and($profile['min_workers'])->toBe(0)
        ->and($profile['max_workers'])->toBe(5)
        ->and($profile['scale_cooldown_seconds'])->toBe(120)
        ->and($profile['breach_threshold'])->toBe(0.7)
        ->and($profile['evaluation_interval_seconds'])->toBe(10);
});

test('all returns all profiles with metadata', function () {
    $all = ProfilePresets::all();

    expect($all)->toHaveKey('critical')
        ->and($all)->toHaveKey('high_volume')
        ->and($all)->toHaveKey('balanced')
        ->and($all)->toHaveKey('bursty')
        ->and($all)->toHaveKey('background')
        ->and($all)->toHaveCount(5);
});

test('all profiles have required metadata keys', function () {
    $all = ProfilePresets::all();

    foreach ($all as $name => $profile) {
        expect($profile)->toHaveKey('config')
            ->and($profile)->toHaveKey('description')
            ->and($profile)->toHaveKey('use_cases')
            ->and($profile)->toHaveKey('cost')
            ->and($profile)->toHaveKey('policies');
    }
});

test('all profiles configs match standalone methods', function () {
    $all = ProfilePresets::all();

    expect($all['critical']['config'])->toBe(ProfilePresets::critical())
        ->and($all['high_volume']['config'])->toBe(ProfilePresets::highVolume())
        ->and($all['balanced']['config'])->toBe(ProfilePresets::balanced())
        ->and($all['bursty']['config'])->toBe(ProfilePresets::bursty())
        ->and($all['background']['config'])->toBe(ProfilePresets::background());
});

test('critical profile has highest cost indicator', function () {
    $all = ProfilePresets::all();

    expect($all['critical']['cost'])->toBe('$$$$$');
});

test('background profile has lowest cost indicator', function () {
    $all = ProfilePresets::all();

    expect($all['background']['cost'])->toBe('$');
});

test('all profiles have non-empty use cases', function () {
    $all = ProfilePresets::all();

    foreach ($all as $name => $profile) {
        expect($profile['use_cases'])->toBeArray()
            ->and($profile['use_cases'])->not->toBeEmpty("Profile {$name} should have use cases");
    }
});

test('all profiles have non-empty policies', function () {
    $all = ProfilePresets::all();

    foreach ($all as $name => $profile) {
        expect($profile['policies'])->toBeArray()
            ->and($profile['policies'])->not->toBeEmpty("Profile {$name} should have policies");
    }
});

test('critical profile is optimized for low latency', function () {
    $critical = ProfilePresets::critical();
    $balanced = ProfilePresets::balanced();

    expect($critical['max_pickup_time_seconds'])->toBeLessThan($balanced['max_pickup_time_seconds'])
        ->and($critical['min_workers'])->toBeGreaterThan($balanced['min_workers'])
        ->and($critical['evaluation_interval_seconds'])->toBeLessThan($balanced['evaluation_interval_seconds']);
});

test('background profile is optimized for cost', function () {
    $background = ProfilePresets::background();
    $balanced = ProfilePresets::balanced();

    expect($background['max_pickup_time_seconds'])->toBeGreaterThan($balanced['max_pickup_time_seconds'])
        ->and($background['max_workers'])->toBeLessThan($balanced['max_workers'])
        ->and($background['scale_cooldown_seconds'])->toBeGreaterThan($balanced['scale_cooldown_seconds']);
});
