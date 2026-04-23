<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('install command writes single-low preset into env file', function (): void {
    $envFile = sys_get_temp_dir().'/queue-autoscale-install-single-low.env';

    File::put($envFile, "APP_NAME=Queue Autoscale Test\nQUEUE_METRICS_STORAGE=redis\n");

    $this->artisan('queue:autoscale:install', [
        '--no-publish' => true,
        '--topology' => 'single-low',
        '--metrics-connection' => 'mysql',
        '--write-env' => true,
        '--env-file' => $envFile,
        '--force' => true,
    ])->assertSuccessful();

    $contents = File::get($envFile);

    expect($contents)->toContain('QUEUE_AUTOSCALE_ENABLED=true');
    expect($contents)->toContain('QUEUE_AUTOSCALE_CLUSTER_ENABLED=false');
    expect($contents)->toContain('QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto');
    expect($contents)->toContain('QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto');
    expect($contents)->toContain('QUEUE_METRICS_STORAGE=database');
    expect($contents)->toContain('QUEUE_METRICS_CONNECTION=mysql');
    expect($contents)->toContain('QUEUE_METRICS_MAX_SAMPLES=500');
    expect(substr_count($contents, 'QUEUE_METRICS_STORAGE='))->toBe(1);

    File::delete($envFile);
});

test('install command writes cluster preset into env file', function (): void {
    $envFile = sys_get_temp_dir().'/queue-autoscale-install-cluster.env';

    if (File::exists($envFile)) {
        File::delete($envFile);
    }

    $this->artisan('queue:autoscale:install', [
        '--no-publish' => true,
        '--topology' => 'cluster',
        '--metrics-connection' => 'cache',
        '--write-env' => true,
        '--env-file' => $envFile,
    ])->assertSuccessful();

    $contents = File::get($envFile);

    expect($contents)->toContain('QUEUE_AUTOSCALE_CLUSTER_ENABLED=true');
    expect($contents)->toContain('QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto');
    expect($contents)->toContain('QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto');
    expect($contents)->toContain('QUEUE_METRICS_STORAGE=redis');
    expect($contents)->toContain('QUEUE_METRICS_CONNECTION=cache');
    expect($contents)->not->toContain('QUEUE_METRICS_MAX_SAMPLES=');

    File::delete($envFile);
});

test('install command supports interactive guide flow', function (): void {
    $envFile = sys_get_temp_dir().'/queue-autoscale-install-interactive.env';

    if (File::exists($envFile)) {
        File::delete($envFile);
    }

    $this->artisan('queue:autoscale:install', [
        '--no-publish' => true,
        '--env-file' => $envFile,
    ])
        ->expectsChoice(
            'Which deployment shape are you installing?',
            'Single host, low traffic, no Redis infrastructure',
            [
                'Single host, low traffic, no Redis infrastructure',
                'Single host with Redis-backed metrics and predictive signals',
                'Multi-host cluster with Redis coordination',
            ],
        )
        ->expectsQuestion('Which database connection should queue metrics use?', 'pgsql')
        ->expectsConfirmation("Apply these changes to {$envFile}?", 'yes')
        ->assertSuccessful();

    $contents = File::get($envFile);

    expect($contents)->toContain('QUEUE_METRICS_CONNECTION=pgsql');
    expect($contents)->toContain('QUEUE_METRICS_STORAGE=database');

    File::delete($envFile);
});

test('install command rejects invalid topology option', function (): void {
    $this->artisan('queue:autoscale:install', [
        '--no-publish' => true,
        '--topology' => 'wrong',
    ])->assertFailed();
});
