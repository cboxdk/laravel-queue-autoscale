<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

test('returns empty list when no exclusions configured', function (): void {
    config(['queue-autoscale.excluded' => []]);

    expect(AutoscaleConfiguration::excludedPatterns())->toBe([]);
    expect(AutoscaleConfiguration::isExcluded('any-queue'))->toBeFalse();
});

test('matches exact queue name', function (): void {
    config(['queue-autoscale.excluded' => ['legacy-sync']]);

    expect(AutoscaleConfiguration::isExcluded('legacy-sync'))->toBeTrue();
    expect(AutoscaleConfiguration::isExcluded('legacy-sync-other'))->toBeFalse();
});

test('matches fnmatch glob patterns', function (): void {
    config(['queue-autoscale.excluded' => ['legacy-*', 'test-?']]);

    expect(AutoscaleConfiguration::isExcluded('legacy-sync'))->toBeTrue();
    expect(AutoscaleConfiguration::isExcluded('legacy-reports'))->toBeTrue();
    expect(AutoscaleConfiguration::isExcluded('test-1'))->toBeTrue();
    expect(AutoscaleConfiguration::isExcluded('test-12'))->toBeFalse();
    expect(AutoscaleConfiguration::isExcluded('production'))->toBeFalse();
});

test('silently ignores non-string entries', function (): void {
    config(['queue-autoscale.excluded' => ['legacy-*', 123, null, '']]);

    expect(AutoscaleConfiguration::excludedPatterns())->toBe(['legacy-*']);
});

test('returns false for any queue when config key missing', function (): void {
    config(['queue-autoscale.excluded' => null]);

    expect(AutoscaleConfiguration::isExcluded('anything'))->toBeFalse();
});
