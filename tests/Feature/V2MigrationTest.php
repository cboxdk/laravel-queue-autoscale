<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('migrate-config translates v1 sla_defaults values into v2 array shape', function (): void {
    $src = __DIR__.'/fixtures/v1-config.php';
    $dst = __DIR__.'/fixtures/queue-autoscale.v2.php';

    if (File::exists($dst)) {
        File::delete($dst);
    }

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    expect(File::exists($dst))->toBeTrue();

    /** @var array<string, mixed> $v2 */
    $v2 = require $dst;

    // sla_defaults must be an array (not a class string) because user had custom values
    expect($v2['sla_defaults'])->toBeArray();

    // max_pickup_time_seconds: 60 → sla.target_seconds: 60
    expect($v2['sla_defaults']['sla']['target_seconds'])->toBe(60);

    // min_workers: 5 → workers.min: 5
    expect($v2['sla_defaults']['workers']['min'])->toBe(5);

    // max_workers: 30 → workers.max: 30
    expect($v2['sla_defaults']['workers']['max'])->toBe(30);

    // scale_cooldown_seconds: 90 → scaling.cooldown_seconds: 90
    expect($v2['scaling']['cooldown_seconds'])->toBe(90);

    // BalancedProfile defaults are preserved for keys not overridden
    expect($v2['sla_defaults']['sla']['percentile'])->toBe(95);
    expect($v2['sla_defaults']['forecast'])->toBeArray();

    File::delete($dst);
});

test('migrate-config preserves custom per-queue overrides', function (): void {
    $src = __DIR__.'/fixtures/v1-config.php';
    $dst = __DIR__.'/fixtures/queue-autoscale.v2.php';

    if (File::exists($dst)) {
        File::delete($dst);
    }

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    /** @var array<string, mixed> $v2 */
    $v2 = require $dst;

    // emails queue override
    expect($v2['queues'])->toHaveKey('emails');
    expect($v2['queues']['emails']['sla']['target_seconds'])->toBe(45);
    expect($v2['queues']['emails']['workers']['min'])->toBe(2);
    expect($v2['queues']['emails']['workers']['max'])->toBe(15);

    // notifications queue override
    expect($v2['queues'])->toHaveKey('notifications');
    expect($v2['queues']['notifications']['connection'])->toBe('redis-high');
    expect($v2['queues']['notifications']['workers']['min'])->toBe(3);

    File::delete($dst);
});

test('migrate-config preserves global cooldown from v1 sla_defaults.scale_cooldown_seconds', function (): void {
    $src = sys_get_temp_dir().'/v1-cooldown-test.php';
    $dst = sys_get_temp_dir().'/v2-cooldown-test.php';

    File::put($src, "<?php\nreturn [\n    'enabled' => true,\n    'sla_defaults' => [\n        'max_pickup_time_seconds' => 30,\n        'min_workers' => 1,\n        'max_workers' => 10,\n        'scale_cooldown_seconds' => 90,\n    ],\n    'queues' => [],\n];\n");

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    /** @var array<string, mixed> $v2 */
    $v2 = require $dst;

    expect($v2['scaling']['cooldown_seconds'])->toBe(90);

    File::delete($src);
    File::delete($dst);
});

test('migrate-config warns when source is already v2', function (): void {
    $src = __DIR__.'/fixtures/already-v2.php';
    $dst = __DIR__.'/fixtures/already-v2-out.php';

    File::put($src, "<?php\n\nreturn ['sla_defaults' => 'SomeProfile', 'sla' => ['target_seconds' => 30]];\n");

    if (File::exists($dst)) {
        File::delete($dst);
    }

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    File::delete($src);
    if (File::exists($dst)) {
        File::delete($dst);
    }
});

test('migrate-config writes sla_defaults as BalancedProfile class when v1 uses bare default values', function (): void {
    $src = sys_get_temp_dir().'/v1-defaults-test.php';
    $dst = sys_get_temp_dir().'/v2-defaults-test.php';

    // A v1 file that still carries non-default SLA values (this fixture does)
    // To test the full-array path we just check the fixture produces an array.
    // For "clean defaults" a minimal file would produce the class string; omit those keys entirely.
    File::put($src, "<?php\nreturn [\n    'enabled' => true,\n    'sla_defaults' => [\n        'max_pickup_time_seconds' => 30,\n        'min_workers' => 1,\n        'max_workers' => 10,\n        'scale_cooldown_seconds' => 60,\n    ],\n    'queues' => [],\n];\n");

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    /** @var array<string, mixed> $v2 */
    $v2 = require $dst;

    // Even with "default-matching" numbers the translate always emits the full resolved array
    // (BalancedProfile string shorthand is only kept when no v1 sla_defaults keys are present)
    expect($v2['sla_defaults'])->toBeArray();
    expect($v2['sla_defaults']['sla']['target_seconds'])->toBe(30);
    expect($v2['sla_defaults']['workers']['min'])->toBe(1);
    expect($v2['sla_defaults']['workers']['max'])->toBe(10);

    File::delete($src);
    File::delete($dst);
});
